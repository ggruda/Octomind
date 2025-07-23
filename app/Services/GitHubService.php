<?php

namespace App\Services;

use App\DTOs\TicketDTO;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Exception;

class GitHubService
{
    private ConfigService $config;
    private LogService $logger;
    private string $token;
    private string $baseUrl = 'https://api.github.com';

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
        
        $this->token = $this->config->get('auth.github_token');
        if (!$this->token) {
            $this->logger->warning('GitHub Token nicht konfiguriert');
        }
    }

    /**
     * Erstellt einen Pull Request für das Ticket
     */
    public function createPullRequest(TicketDTO $ticket, array $executionResult): array
    {
        $this->logger->info('Erstelle GitHub Pull Request', [
            'ticket_key' => $ticket->key,
            'files_changed' => count($executionResult['changes']['files'] ?? [])
        ]);

        try {
            $repoInfo = $ticket->getRepositoryInfo();
            if (!$repoInfo) {
                throw new Exception('Kein Repository für Ticket gefunden');
            }

            // 1. Repository klonen/aktualisieren
            $localRepo = $this->cloneRepository($repoInfo);
            
            // 2. Branch erstellen
            $branchName = $ticket->generateBranchName();
            $this->createBranch($localRepo, $branchName);
            
            // 3. Änderungen anwenden
            $this->applyChanges($localRepo, $executionResult['changes']);
            
            // 4. Commit erstellen
            $commitHash = $this->createCommit($localRepo, $executionResult['changes']);
            
            // 5. Branch pushen
            $this->pushBranch($localRepo, $branchName);
            
            // 6. Pull Request erstellen
            $prResult = $this->createPR($repoInfo, $ticket, $branchName);
            
            $this->logger->info('Pull Request erfolgreich erstellt', [
                'ticket_key' => $ticket->key,
                'pr_number' => $prResult['number'],
                'pr_url' => $prResult['html_url'],
                'branch' => $branchName,
                'commit' => $commitHash
            ]);

            return [
                'success' => true,
                'pr_url' => $prResult['html_url'],
                'pr_number' => $prResult['number'],
                'branch' => $branchName,
                'commit_hash' => $commitHash
            ];

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Erstellen des Pull Requests', [
                'ticket_key' => $ticket->key,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'pr_url' => null
            ];
        }
    }

    /**
     * Klont oder aktualisiert ein Repository
     */
    private function cloneRepository(array $repoInfo): string
    {
        $repoPath = $this->config->get('repository.storage_path') . '/' . $repoInfo['full_name'];
        
        if (is_dir($repoPath)) {
            // Repository existiert bereits - aktualisieren
            $this->logger->debug('Aktualisiere existierendes Repository', [
                'path' => $repoPath
            ]);
            
            $this->runGitCommand($repoPath, 'fetch origin');
            $this->runGitCommand($repoPath, 'checkout main');
            $this->runGitCommand($repoPath, 'pull origin main');
        } else {
            // Repository klonen
            $this->logger->debug('Klone Repository', [
                'url' => $repoInfo['url'],
                'path' => $repoPath
            ]);
            
            $cloneUrl = str_replace('github.com', $this->token . '@github.com', $repoInfo['url']);
            $parentDir = dirname($repoPath);
            
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }
            
            $this->runGitCommand($parentDir, "clone {$cloneUrl} " . basename($repoPath));
        }

        return $repoPath;
    }

    /**
     * Erstellt einen neuen Branch
     */
    private function createBranch(string $repoPath, string $branchName): void
    {
        $this->logger->debug('Erstelle Branch', [
            'branch' => $branchName,
            'repo' => $repoPath
        ]);

        // Prüfe ob Branch bereits existiert
        $branches = $this->runGitCommand($repoPath, 'branch -a');
        if (strpos($branches, $branchName) !== false) {
            // Branch existiert bereits - checke ihn aus
            $this->runGitCommand($repoPath, "checkout {$branchName}");
        } else {
            // Neuen Branch erstellen
            $this->runGitCommand($repoPath, "checkout -b {$branchName}");
        }
    }

    /**
     * Wendet Code-Änderungen auf das Repository an
     */
    private function applyChanges(string $repoPath, array $changes): void
    {
        $this->logger->debug('Wende Code-Änderungen an', [
            'files_count' => count($changes['files']),
            'repo' => $repoPath
        ]);

        foreach ($changes['files'] as $file) {
            $filePath = $repoPath . '/' . ltrim($file['path'], '/');
            $directory = dirname($filePath);

            // Erstelle Verzeichnis falls nötig
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            switch ($file['action']) {
                case 'create':
                case 'modify':
                    file_put_contents($filePath, $file['content']);
                    $this->logger->debug('Datei erstellt/geändert', ['file' => $file['path']]);
                    break;
                    
                case 'delete':
                    if (file_exists($filePath)) {
                        unlink($filePath);
                        $this->logger->debug('Datei gelöscht', ['file' => $file['path']]);
                    }
                    break;
                    
                default:
                    $this->logger->warning('Unbekannte Aktion', [
                        'action' => $file['action'],
                        'file' => $file['path']
                    ]);
            }
        }

        // Füge alle Änderungen zum Git Index hinzu
        $this->runGitCommand($repoPath, 'add .');
    }

    /**
     * Erstellt einen Commit
     */
    private function createCommit(string $repoPath, array $changes): string
    {
        $commitMessage = $changes['commit_message'] ?? 'Automated changes';
        
        // Konfiguriere Git User falls nötig
        $authorName = $this->config->get('repository.commit_author_name', 'Octomind Bot');
        $authorEmail = $this->config->get('repository.commit_author_email', 'bot@octomind.com');
        
        $this->runGitCommand($repoPath, "config user.name \"{$authorName}\"");
        $this->runGitCommand($repoPath, "config user.email \"{$authorEmail}\"");
        
        // Erstelle Commit
        $this->runGitCommand($repoPath, "commit -m \"{$commitMessage}\"");
        
        // Hole Commit Hash
        $commitHash = trim($this->runGitCommand($repoPath, 'rev-parse HEAD'));
        
        $this->logger->debug('Commit erstellt', [
            'hash' => $commitHash,
            'message' => $commitMessage
        ]);

        return $commitHash;
    }

    /**
     * Pusht einen Branch zum Remote Repository
     */
    private function pushBranch(string $repoPath, string $branchName): void
    {
        $this->logger->debug('Pushe Branch', [
            'branch' => $branchName,
            'repo' => $repoPath
        ]);

        $this->runGitCommand($repoPath, "push origin {$branchName}");
    }

    /**
     * Erstellt einen Pull Request über die GitHub API
     */
    private function createPR(array $repoInfo, TicketDTO $ticket, string $branchName): array
    {
        $url = "{$this->baseUrl}/repos/{$repoInfo['full_name']}/pulls";
        
        $prData = [
            'title' => $ticket->generatePRTitle(),
            'head' => $branchName,
            'base' => 'main',
            'body' => $ticket->generatePRDescription(),
            'draft' => $this->config->get('repository.create_draft_prs', true)
        ];

        $response = Http::withHeaders([
            'Authorization' => 'token ' . $this->token,
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'Octomind-Bot/1.0'
        ])->post($url, $prData);

        if (!$response->successful()) {
            throw new Exception("GitHub API Fehler beim Erstellen des PR: HTTP {$response->status()} - " . $response->body());
        }

        return $response->json();
    }

    /**
     * Führt Git-Befehle aus
     */
    private function runGitCommand(string $workingDir, string $command): string
    {
        $fullCommand = "cd \"{$workingDir}\" && git {$command}";
        
        $this->logger->debug('Führe Git-Befehl aus', [
            'command' => $command,
            'working_dir' => $workingDir
        ]);

        $output = [];
        $returnCode = 0;
        
        exec($fullCommand . ' 2>&1', $output, $returnCode);
        
        $outputString = implode("\n", $output);
        
        if ($returnCode !== 0) {
            throw new Exception("Git-Befehl fehlgeschlagen: {$command}\nOutput: {$outputString}");
        }

        return $outputString;
    }

    /**
     * Testet die GitHub-Verbindung
     */
    public function testConnection(): array
    {
        try {
            $startTime = microtime(true);
            
            $response = Http::withHeaders([
                'Authorization' => 'token ' . $this->token,
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'Octomind-Bot/1.0'
            ])->get($this->baseUrl . '/user');

            $responseTime = round((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $userData = $response->json();
                
                $this->logger->info('GitHub-Verbindungstest erfolgreich', [
                    'user' => $userData['login'] ?? 'Unknown',
                    'response_time_ms' => $responseTime
                ]);

                return [
                    'success' => true,
                    'message' => 'GitHub-Verbindung erfolgreich',
                    'user' => $userData['login'] ?? 'Unknown',
                    'response_time_ms' => $responseTime
                ];
            } else {
                throw new Exception("HTTP {$response->status()}: " . $response->body());
            }

        } catch (Exception $e) {
            $this->logger->error('GitHub-Verbindungstest fehlgeschlagen', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'GitHub-Verbindung fehlgeschlagen: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Holt Repository-Informationen von GitHub
     */
    public function getRepositoryInfo(string $owner, string $repo): array
    {
        try {
            $url = "{$this->baseUrl}/repos/{$owner}/{$repo}";
            
            $response = Http::withHeaders([
                'Authorization' => 'token ' . $this->token,
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'Octomind-Bot/1.0'
            ])->get($url);

            if (!$response->successful()) {
                throw new Exception("HTTP {$response->status()}: " . $response->body());
            }

            $repoData = $response->json();
            
            return [
                'success' => true,
                'data' => [
                    'name' => $repoData['name'],
                    'full_name' => $repoData['full_name'],
                    'description' => $repoData['description'],
                    'language' => $repoData['language'],
                    'default_branch' => $repoData['default_branch'],
                    'clone_url' => $repoData['clone_url'],
                    'ssh_url' => $repoData['ssh_url'],
                    'private' => $repoData['private']
                ]
            ];

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Abrufen der Repository-Informationen', [
                'owner' => $owner,
                'repo' => $repo,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Fügt einen Kommentar zu einem Pull Request hinzu
     */
    public function addPRComment(string $owner, string $repo, int $prNumber, string $comment): bool
    {
        try {
            $url = "{$this->baseUrl}/repos/{$owner}/{$repo}/issues/{$prNumber}/comments";
            
            $response = Http::withHeaders([
                'Authorization' => 'token ' . $this->token,
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'Octomind-Bot/1.0'
            ])->post($url, [
                'body' => $comment
            ]);

            if ($response->successful()) {
                $this->logger->info('PR-Kommentar erfolgreich hinzugefügt', [
                    'owner' => $owner,
                    'repo' => $repo,
                    'pr_number' => $prNumber
                ]);
                return true;
            } else {
                throw new Exception("HTTP {$response->status()}: " . $response->body());
            }

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Hinzufügen des PR-Kommentars', [
                'owner' => $owner,
                'repo' => $repo,
                'pr_number' => $prNumber,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Merged einen Pull Request (falls Auto-Merge aktiviert)
     */
    public function mergePullRequest(string $owner, string $repo, int $prNumber): array
    {
        if (!$this->config->get('repository.auto_merge_enabled', false)) {
            return [
                'success' => false,
                'message' => 'Auto-Merge ist deaktiviert'
            ];
        }

        try {
            $url = "{$this->baseUrl}/repos/{$owner}/{$repo}/pulls/{$prNumber}/merge";
            
            $response = Http::withHeaders([
                'Authorization' => 'token ' . $this->token,
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'Octomind-Bot/1.0'
            ])->put($url, [
                'commit_title' => 'Automated merge by Octomind Bot',
                'merge_method' => 'squash'
            ]);

            if ($response->successful()) {
                $mergeData = $response->json();
                
                $this->logger->info('Pull Request erfolgreich gemerged', [
                    'owner' => $owner,
                    'repo' => $repo,
                    'pr_number' => $prNumber,
                    'sha' => $mergeData['sha']
                ]);

                return [
                    'success' => true,
                    'sha' => $mergeData['sha'],
                    'message' => $mergeData['message']
                ];
            } else {
                throw new Exception("HTTP {$response->status()}: " . $response->body());
            }

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Mergen des Pull Requests', [
                'owner' => $owner,
                'repo' => $repo,
                'pr_number' => $prNumber,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Löscht einen Branch nach erfolgreichem Merge
     */
    public function deleteBranch(string $owner, string $repo, string $branchName): bool
    {
        try {
            $url = "{$this->baseUrl}/repos/{$owner}/{$repo}/git/refs/heads/{$branchName}";
            
            $response = Http::withHeaders([
                'Authorization' => 'token ' . $this->token,
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'Octomind-Bot/1.0'
            ])->delete($url);

            if ($response->successful()) {
                $this->logger->info('Branch erfolgreich gelöscht', [
                    'owner' => $owner,
                    'repo' => $repo,
                    'branch' => $branchName
                ]);
                return true;
            } else {
                throw new Exception("HTTP {$response->status()}: " . $response->body());
            }

        } catch (Exception $e) {
            $this->logger->warning('Fehler beim Löschen des Branches', [
                'owner' => $owner,
                'repo' => $repo,
                'branch' => $branchName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
} 