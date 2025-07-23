<?php

namespace App\Services;

use App\Contracts\VersionControlProviderInterface;
use App\DTOs\TicketDTO;
use Illuminate\Support\Facades\Http;
use Exception;

class GitLabService implements VersionControlProviderInterface
{
    private ConfigService $config;
    private LogService $logger;
    private string $token;
    private string $baseUrl;

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
        
        $this->token = $this->config->get('auth.gitlab_token');
        $this->baseUrl = $this->config->get('gitlab.base_url', 'https://gitlab.com');
    }

    public function createPullRequest(TicketDTO $ticket, array $executionResult): array
    {
        $this->logger->info('Erstelle GitLab Merge Request', [
            'ticket_key' => $ticket->key
        ]);

        try {
            $repoInfo = $ticket->getRepositoryInfo();
            if (!$repoInfo) {
                throw new Exception('Kein Repository für Ticket gefunden');
            }

            // GitLab-spezifische MR-Erstellung
            $projectId = $this->getProjectId($repoInfo['full_name']);
            
            $mrData = [
                'source_branch' => $ticket->generateBranchName(),
                'target_branch' => 'main',
                'title' => $ticket->generatePRTitle(),
                'description' => $ticket->generatePRDescription(),
                'remove_source_branch' => true
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json'
            ])->post("{$this->baseUrl}/api/v4/projects/{$projectId}/merge_requests", $mrData);

            if (!$response->successful()) {
                throw new Exception("GitLab API Fehler: " . $response->body());
            }

            $mrResult = $response->json();

            return [
                'success' => true,
                'pr_url' => $mrResult['web_url'],
                'pr_number' => $mrResult['iid'],
                'branch' => $mrResult['source_branch'],
                'commit_hash' => $executionResult['changes']['commit_hash'] ?? null
            ];

        } catch (Exception $e) {
            $this->logger->error('GitLab MR-Erstellung fehlgeschlagen', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function testConnection(): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token
            ])->get("{$this->baseUrl}/api/v4/user");

            if ($response->successful()) {
                $userData = $response->json();
                return [
                    'success' => true,
                    'message' => 'GitLab-Verbindung erfolgreich',
                    'user' => $userData['username'] ?? 'Unknown'
                ];
            }

            throw new Exception("HTTP {$response->status()}");

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'GitLab-Verbindung fehlgeschlagen: ' . $e->getMessage()
            ];
        }
    }

    public function getRepositoryInfo(string $owner, string $repo): array
    {
        try {
            $projectPath = urlencode("{$owner}/{$repo}");
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token
            ])->get("{$this->baseUrl}/api/v4/projects/{$projectPath}");

            if (!$response->successful()) {
                throw new Exception("HTTP {$response->status()}");
            }

            $repoData = $response->json();
            
            return [
                'success' => true,
                'data' => [
                    'name' => $repoData['name'],
                    'full_name' => $repoData['path_with_namespace'],
                    'description' => $repoData['description'],
                    'default_branch' => $repoData['default_branch'],
                    'clone_url' => $repoData['http_url_to_repo'],
                    'ssh_url' => $repoData['ssh_url_to_repo'],
                    'private' => $repoData['visibility'] === 'private'
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function addPRComment(string $owner, string $repo, int $prNumber, string $comment): bool
    {
        try {
            $projectId = $this->getProjectId("{$owner}/{$repo}");
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json'
            ])->post("{$this->baseUrl}/api/v4/projects/{$projectId}/merge_requests/{$prNumber}/notes", [
                'body' => $comment
            ]);

            return $response->successful();

        } catch (Exception $e) {
            $this->logger->error('GitLab MR-Kommentar fehlgeschlagen', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function mergePullRequest(string $owner, string $repo, int $prNumber): array
    {
        try {
            $projectId = $this->getProjectId("{$owner}/{$repo}");
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token
            ])->put("{$this->baseUrl}/api/v4/projects/{$projectId}/merge_requests/{$prNumber}/merge");

            if ($response->successful()) {
                $mergeData = $response->json();
                return [
                    'success' => true,
                    'sha' => $mergeData['sha'],
                    'message' => 'Merge Request erfolgreich gemerged'
                ];
            }

            throw new Exception("HTTP {$response->status()}");

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function deleteBranch(string $owner, string $repo, string $branchName): bool
    {
        try {
            $projectId = $this->getProjectId("{$owner}/{$repo}");
            $encodedBranch = urlencode($branchName);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token
            ])->delete("{$this->baseUrl}/api/v4/projects/{$projectId}/repository/branches/{$encodedBranch}");

            return $response->successful();

        } catch (Exception $e) {
            return false;
        }
    }

    public function getProviderName(): string
    {
        return 'gitlab';
    }

    public function getSupportedRepositoryTypes(): array
    {
        return ['git'];
    }

    public function validateConfiguration(): array
    {
        $errors = [];

        if (empty($this->token)) {
            $errors[] = 'GitLab Token nicht konfiguriert';
        }

        if (empty($this->baseUrl)) {
            $errors[] = 'GitLab Base URL nicht konfiguriert';
        }

        return $errors;
    }

    private function getProjectId(string $fullName): string
    {
        return urlencode($fullName);
    }
} 