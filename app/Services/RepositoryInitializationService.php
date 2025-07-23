<?php

namespace App\Services;

use App\DTOs\TicketDTO;
use Exception;
use Illuminate\Support\Facades\File;

class RepositoryInitializationService
{
    private ConfigService $config;
    private LogService $logger;
    private ProviderManager $providerManager;
    private SSHKeyManagementService $sshManager;

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
        $this->providerManager = new ProviderManager();
        $this->sshManager = new SSHKeyManagementService();
    }

    /**
     * Initialisiert Repository für das erste Ticket
     */
    public function initializeRepository(TicketDTO $ticket): array
    {
        $this->logger->info('Initialisiere Repository für erstes Ticket', [
            'ticket_key' => $ticket->key,
            'repository_url' => $ticket->repositoryUrl
        ]);

        try {
            // 1. SSH-Keys initialisieren falls nötig
            $this->ensureSSHKeysConfigured();
            
            // 2. Repository-Informationen validieren
            $repoInfo = $this->validateRepositoryUrl($ticket->repositoryUrl);
            
            // 3. Lokales Arbeitsverzeichnis erstellen
            $workspacePath = $this->createWorkspace($repoInfo);
            
            // 4. Repository klonen oder initialisieren
            $cloneResult = $this->cloneOrInitializeRepository($repoInfo, $workspacePath);
            
            // 5. Basis-Struktur und Konfiguration prüfen/erstellen
            $setupResult = $this->setupRepositoryStructure($workspacePath, $repoInfo);
            
            // 6. Git-Konfiguration einrichten
            $this->configureGit($workspacePath);
            
            $this->logger->info('Repository erfolgreich initialisiert', [
                'ticket_key' => $ticket->key,
                'workspace_path' => $workspacePath,
                'repository_url' => $repoInfo['clone_url']
            ]);

            return [
                'success' => true,
                'workspace_path' => $workspacePath,
                'repository_info' => $repoInfo,
                'clone_result' => $cloneResult,
                'setup_result' => $setupResult
            ];

        } catch (Exception $e) {
            $this->logger->error('Repository-Initialisierung fehlgeschlagen', [
                'ticket_key' => $ticket->key,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'workspace_path' => null
            ];
        }
    }

    /**
     * Stellt sicher, dass SSH-Keys konfiguriert sind
     */
    private function ensureSSHKeysConfigured(): void
    {
        if (!$this->sshManager->isConfigured()) {
            $this->logger->info('SSH-Keys nicht konfiguriert, initialisiere...');
            
            $result = $this->sshManager->initializeSSHKeys();
            
            if (!$result['success']) {
                throw new Exception('SSH-Key-Initialisierung fehlgeschlagen: ' . $result['error']);
            }

            if ($result['action'] === 'generated') {
                $this->logger->warning('⚠️  NEUE SSH-KEYS GENERIERT! Bitte Deploy Keys zu den Repositories hinzufügen:', [
                    'public_key' => $result['public_key'],
                    'fingerprint' => $result['fingerprint']
                ]);
                
                // Deployment-Anweisungen loggen
                foreach ($result['instructions'] as $provider => $instructions) {
                    $this->logger->info("📋 {$instructions['title']}:", [
                        'steps' => $instructions['steps']
                    ]);
                }
            }
        }

        // Git für SSH konfigurieren
        $this->sshManager->configureGitForSSH();
    }

    /**
     * Validiert Repository-URL und extrahiert Informationen
     */
    private function validateRepositoryUrl(string $repositoryUrl): array
    {
        if (empty($repositoryUrl)) {
            throw new Exception('Keine Repository-URL im Ticket gefunden');
        }

        // GitHub-URL parsen
        if (preg_match('/github\.com\/([^\/]+)\/([^\/\s]+)/', $repositoryUrl, $matches)) {
            $owner = $matches[1];
            $repo = rtrim($matches[2], '.git');
            
            // Repository-Informationen über API abrufen
            $vcsProvider = $this->providerManager->getVCSProvider('github');
            $repoData = $vcsProvider->getRepositoryInfo($owner, $repo);
            
            if (!$repoData['success']) {
                throw new Exception("Repository nicht gefunden oder nicht zugänglich: {$repositoryUrl}");
            }

            return [
                'owner' => $owner,
                'name' => $repo,
                'full_name' => "{$owner}/{$repo}",
                'clone_url' => "https://github.com/{$owner}/{$repo}.git",
                'ssh_url' => "git@github.com:{$owner}/{$repo}.git",
                'default_branch' => $repoData['data']['default_branch'] ?? 'main',
                'private' => $repoData['data']['private'] ?? false,
                'description' => $repoData['data']['description'] ?? '',
                'provider' => 'github'
            ];
        }

        // GitLab-URL parsen
        if (preg_match('/gitlab\.com\/([^\/]+)\/([^\/\s]+)/', $repositoryUrl, $matches)) {
            $owner = $matches[1];
            $repo = rtrim($matches[2], '.git');
            
            return [
                'owner' => $owner,
                'name' => $repo,
                'full_name' => "{$owner}/{$repo}",
                'clone_url' => "https://gitlab.com/{$owner}/{$repo}.git",
                'ssh_url' => "git@gitlab.com:{$owner}/{$repo}.git",
                'default_branch' => 'main',
                'private' => false,
                'description' => '',
                'provider' => 'gitlab'
            ];
        }

        throw new Exception("Nicht unterstützte Repository-URL: {$repositoryUrl}");
    }

    /**
     * Erstellt lokales Arbeitsverzeichnis
     */
    private function createWorkspace(array $repoInfo): string
    {
        $basePath = $this->config->get('repository.storage_path');
        $workspacePath = $basePath . '/' . $repoInfo['full_name'];

        $this->logger->debug('Erstelle Arbeitsverzeichnis', [
            'path' => $workspacePath
        ]);

        // Arbeitsverzeichnis erstellen
        if (!File::exists($basePath)) {
            File::makeDirectory($basePath, 0755, true);
        }

        // Altes Verzeichnis entfernen falls vorhanden
        if (File::exists($workspacePath)) {
            $this->logger->info('Entferne altes Arbeitsverzeichnis', [
                'path' => $workspacePath
            ]);
            File::deleteDirectory($workspacePath);
        }

        File::makeDirectory($workspacePath, 0755, true);

        return $workspacePath;
    }

    /**
     * Klont Repository oder initialisiert neues
     */
    private function cloneOrInitializeRepository(array $repoInfo, string $workspacePath): array
    {
        try {
            // Versuche Repository zu klonen
            $cloneResult = $this->cloneExistingRepository($repoInfo, $workspacePath);
            
            return [
                'action' => 'cloned',
                'result' => $cloneResult,
                'branch' => $repoInfo['default_branch']
            ];

        } catch (Exception $e) {
            $this->logger->warning('Repository klonen fehlgeschlagen, initialisiere neues', [
                'error' => $e->getMessage()
            ]);

            // Falls klonen fehlschlägt, neues Repository initialisieren
            $initResult = $this->initializeNewRepository($workspacePath, $repoInfo);
            
            return [
                'action' => 'initialized',
                'result' => $initResult,
                'branch' => 'main'
            ];
        }
    }

    /**
     * Klont existierendes Repository
     */
    private function cloneExistingRepository(array $repoInfo, string $workspacePath): string
    {
        $this->logger->info('Klone existierendes Repository', [
            'url' => $repoInfo['clone_url'],
            'path' => $workspacePath
        ]);

        // SSH-URL für sicheres Klonen verwenden
        $sshUrl = $this->sshManager->convertToSSHUrl($repoInfo['clone_url']);
        
        $parentDir = dirname($workspacePath);
        $repoName = basename($workspacePath);

        // Git clone mit SSH ausführen
        $sshCommand = "ssh -i {$this->sshManager->getStatus()['private_key_path']} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null";
        $command = "cd {$parentDir} && GIT_SSH_COMMAND=\"{$sshCommand}\" git clone {$sshUrl} {$repoName}";
        $output = shell_exec($command . ' 2>&1');

        if (!File::exists($workspacePath . '/.git')) {
            throw new Exception("Git clone fehlgeschlagen: {$output}");
        }

        $this->logger->info('Repository erfolgreich geklont via SSH', [
            'ssh_url' => $sshUrl,
            'path' => $workspacePath
        ]);

        return $output;
    }

    /**
     * Initialisiert neues Repository
     */
    private function initializeNewRepository(string $workspacePath, array $repoInfo): string
    {
        $this->logger->info('Initialisiere neues Repository', [
            'path' => $workspacePath
        ]);

        // Git Repository initialisieren
        $commands = [
            "cd {$workspacePath}",
            "git init",
            "git branch -M main",
            "git remote add origin {$repoInfo['clone_url']}"
        ];

        $output = '';
        foreach ($commands as $command) {
            $result = shell_exec($command . ' 2>&1');
            $output .= $result . "\n";
        }

        // Basis-README erstellen
        $this->createInitialFiles($workspacePath, $repoInfo);

        return $output;
    }

    /**
     * Erstellt initiale Dateien für neues Repository
     */
    private function createInitialFiles(string $workspacePath, array $repoInfo): void
    {
        // README.md erstellen
        $readmeContent = "# {$repoInfo['name']}\n\n";
        $readmeContent .= $repoInfo['description'] ? $repoInfo['description'] . "\n\n" : "Projekt-Repository\n\n";
        $readmeContent .= "Automatisch erstellt vom Octomind Bot.\n";
        
        File::put($workspacePath . '/README.md', $readmeContent);

        // .gitignore erstellen
        $gitignoreContent = "# Dependencies\nnode_modules/\nvendor/\n\n";
        $gitignoreContent .= "# Environment\n.env\n.env.local\n\n";
        $gitignoreContent .= "# Logs\n*.log\nlogs/\n\n";
        $gitignoreContent .= "# OS\n.DS_Store\nThumbs.db\n";
        
        File::put($workspacePath . '/.gitignore', $gitignoreContent);

        $this->logger->debug('Initiale Dateien erstellt', [
            'files' => ['README.md', '.gitignore']
        ]);
    }

    /**
     * Richtet Repository-Struktur ein
     */
    private function setupRepositoryStructure(string $workspacePath, array $repoInfo): array
    {
        $this->logger->debug('Richte Repository-Struktur ein', [
            'path' => $workspacePath
        ]);

        $setupActions = [];

        // Prüfe ob es ein bekanntes Projekt-Framework ist
        $frameworkType = $this->detectFrameworkType($workspacePath);
        $setupActions['framework_detected'] = $frameworkType;

        // Framework-spezifische Setup-Aktionen
        switch ($frameworkType) {
            case 'laravel':
                $setupActions['laravel'] = $this->setupLaravelProject($workspacePath);
                break;
                
            case 'node':
                $setupActions['node'] = $this->setupNodeProject($workspacePath);
                break;
                
            case 'python':
                $setupActions['python'] = $this->setupPythonProject($workspacePath);
                break;
                
            default:
                $setupActions['generic'] = $this->setupGenericProject($workspacePath);
        }

        return $setupActions;
    }

    /**
     * Erkennt Framework-Typ
     */
    private function detectFrameworkType(string $workspacePath): string
    {
        if (File::exists($workspacePath . '/composer.json')) {
            $composer = json_decode(File::get($workspacePath . '/composer.json'), true);
            if (isset($composer['require']['laravel/framework'])) {
                return 'laravel';
            }
        }

        if (File::exists($workspacePath . '/package.json')) {
            return 'node';
        }

        if (File::exists($workspacePath . '/requirements.txt') || File::exists($workspacePath . '/pyproject.toml')) {
            return 'python';
        }

        return 'generic';
    }

    /**
     * Richtet Laravel-Projekt ein
     */
    private function setupLaravelProject(string $workspacePath): array
    {
        $actions = [];

        // Prüfe ob .env existiert
        if (!File::exists($workspacePath . '/.env')) {
            if (File::exists($workspacePath . '/.env.example')) {
                File::copy($workspacePath . '/.env.example', $workspacePath . '/.env');
                $actions['env_created'] = true;
            }
        }

        return $actions;
    }

    /**
     * Richtet Node-Projekt ein
     */
    private function setupNodeProject(string $workspacePath): array
    {
        return ['type' => 'node'];
    }

    /**
     * Richtet Python-Projekt ein
     */
    private function setupPythonProject(string $workspacePath): array
    {
        return ['type' => 'python'];
    }

    /**
     * Richtet generisches Projekt ein
     */
    private function setupGenericProject(string $workspacePath): array
    {
        return ['type' => 'generic'];
    }

    /**
     * Konfiguriert Git für das Repository
     */
    private function configureGit(string $workspacePath): void
    {
        $authorName = $this->config->get('repository.commit_author_name');
        $authorEmail = $this->config->get('repository.commit_author_email');

        $commands = [
            "cd {$workspacePath}",
            "git config user.name \"{$authorName}\"",
            "git config user.email \"{$authorEmail}\"",
            "git config init.defaultBranch main"
        ];

        foreach ($commands as $command) {
            shell_exec($command . ' 2>&1');
        }

        $this->logger->debug('Git konfiguriert', [
            'author' => $authorName,
            'email' => $authorEmail
        ]);
    }

    /**
     * Prüft ob Repository bereits initialisiert ist
     */
    public function isRepositoryInitialized(TicketDTO $ticket): bool
    {
        if (!$ticket->repositoryUrl) {
            return false;
        }

        try {
            $repoInfo = $this->validateRepositoryUrl($ticket->repositoryUrl);
            $workspacePath = $this->config->get('repository.storage_path') . '/' . $repoInfo['full_name'];
            
            return File::exists($workspacePath . '/.git');
            
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Gibt Workspace-Pfad für Ticket zurück
     */
    public function getWorkspacePath(TicketDTO $ticket): ?string
    {
        if (!$ticket->repositoryUrl) {
            return null;
        }

        try {
            $repoInfo = $this->validateRepositoryUrl($ticket->repositoryUrl);
            return $this->config->get('repository.storage_path') . '/' . $repoInfo['full_name'];
            
        } catch (Exception $e) {
            return null;
        }
    }
} 