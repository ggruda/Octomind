<?php

namespace App\Services;

use App\Models\Repository;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Exception;

class RepositoryService
{
    private LogService $logger;
    private SSHKeyManagementService $sshManager;

    public function __construct()
    {
        $this->logger = new LogService();
        $this->sshManager = new SSHKeyManagementService();
    }

    /**
     * Holt alle aktiven Repositories (gecacht)
     */
    public function getActiveRepositories(): Collection
    {
        return Repository::getActiveRepositories();
    }

    /**
     * Findet ein Repository anhand des Full-Names (gecacht)
     */
    public function findByFullName(string $fullName): ?Repository
    {
        return Repository::findByFullName($fullName);
    }

    /**
     * Erstellt ein neues Repository
     */
    public function createRepository(array $data): Repository
    {
        $this->logger->info('Erstelle neues Repository', [
            'full_name' => $data['full_name'],
            'provider' => $data['provider'] ?? 'github'
        ]);

        // Parse owner/name from full_name
        [$owner, $name] = $this->parseFullName($data['full_name']);

        $repository = Repository::create([
            'name' => $name,
            'full_name' => $data['full_name'],
            'owner' => $owner,
            'description' => $data['description'] ?? null,
            'clone_url' => $data['clone_url'] ?? $this->generateCloneUrl($data['full_name'], $data['provider'] ?? 'github'),
            'ssh_url' => $data['ssh_url'] ?? null, // Auto-generated in model
            'web_url' => $data['web_url'] ?? $this->generateWebUrl($data['full_name'], $data['provider'] ?? 'github'),
            'provider' => $data['provider'] ?? 'github',
            'provider_id' => $data['provider_id'] ?? null,
            'default_branch' => $data['default_branch'] ?? 'main',
            'is_private' => $data['is_private'] ?? true,
            'bot_enabled' => $data['bot_enabled'] ?? true,
            'allowed_file_extensions' => $data['allowed_file_extensions'] ?? [
                'php', 'js', 'ts', 'vue', 'blade.php', 'json', 'yaml', 'yml', 'md'
            ],
            'forbidden_paths' => $data['forbidden_paths'] ?? [
                '.env', '.git', 'vendor', 'node_modules'
            ],
            'max_file_size' => $data['max_file_size'] ?? 1048576, // 1MB
            'framework_type' => $data['framework_type'] ?? null,
            'framework_config' => $data['framework_config'] ?? null,
            'package_manager' => $data['package_manager'] ?? null,
            'branch_prefix' => $data['branch_prefix'] ?? 'octomind',
            'create_draft_prs' => $data['create_draft_prs'] ?? true,
            'auto_merge_enabled' => $data['auto_merge_enabled'] ?? false,
            'pr_template' => $data['pr_template'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'notes' => $data['notes'] ?? null,
            'webhook_config' => $data['webhook_config'] ?? null
        ]);

        $this->logger->info('Repository erfolgreich erstellt', [
            'repository_id' => $repository->id,
            'full_name' => $repository->full_name
        ]);

        return $repository;
    }

    /**
     * Aktualisiert ein Repository
     */
    public function updateRepository(Repository $repository, array $data): Repository
    {
        $this->logger->info('Aktualisiere Repository', [
            'repository_id' => $repository->id,
            'full_name' => $repository->full_name
        ]);

        $repository->update($data);

        $this->logger->info('Repository erfolgreich aktualisiert', [
            'repository_id' => $repository->id
        ]);

        return $repository->fresh();
    }

    /**
     * Klont ein Repository in den lokalen Workspace
     */
    public function cloneRepository(Repository $repository, bool $force = false): array
    {
        $workspacePath = $repository->local_workspace_path;

        $this->logger->info('Klone Repository', [
            'repository' => $repository->full_name,
            'workspace_path' => $workspacePath,
            'force' => $force
        ]);

        // Prüfe ob bereits geklont
        if (!$force && File::exists($workspacePath . '/.git')) {
            $this->logger->debug('Repository bereits geklont', [
                'repository' => $repository->full_name
            ]);
            return [
                'success' => true,
                'action' => 'already_exists',
                'path' => $workspacePath
            ];
        }

        try {
            // Stelle sicher, dass SSH-Keys konfiguriert sind
            $this->ensureSSHKeysConfigured();

            // Workspace-Verzeichnis erstellen
            $parentDir = dirname($workspacePath);
            if (!File::exists($parentDir)) {
                File::makeDirectory($parentDir, 0755, true);
            }

            // Altes Verzeichnis löschen falls force
            if ($force && File::exists($workspacePath)) {
                File::deleteDirectory($workspacePath);
            }

            // Git clone mit SSH
            $sshCommand = $this->sshManager->getGitSSHCommand();
            $command = "cd {$parentDir} && GIT_SSH_COMMAND=\"{$sshCommand}\" git clone {$repository->ssh_url} " . basename($workspacePath);
            
            $output = shell_exec($command . ' 2>&1');

            if (!File::exists($workspacePath . '/.git')) {
                throw new Exception("Git clone fehlgeschlagen: {$output}");
            }

            // Repository-Metadaten aktualisieren
            $repository->update([
                'last_cloned_at' => now(),
                'last_synced_at' => now()
            ]);

            // Framework-Erkennung durchführen
            $this->detectAndUpdateFramework($repository);

            $this->logger->info('Repository erfolgreich geklont', [
                'repository' => $repository->full_name,
                'path' => $workspacePath
            ]);

            return [
                'success' => true,
                'action' => 'cloned',
                'path' => $workspacePath,
                'output' => $output
            ];

        } catch (Exception $e) {
            $this->logger->error('Repository-Kloning fehlgeschlagen', [
                'repository' => $repository->full_name,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'path' => $workspacePath
            ];
        }
    }

    /**
     * Synchronisiert ein Repository (git pull)
     */
    public function syncRepository(Repository $repository): array
    {
        $workspacePath = $repository->local_workspace_path;

        $this->logger->info('Synchronisiere Repository', [
            'repository' => $repository->full_name,
            'workspace_path' => $workspacePath
        ]);

        if (!File::exists($workspacePath . '/.git')) {
            $this->logger->warning('Repository nicht geklont, klone zuerst', [
                'repository' => $repository->full_name
            ]);
            return $this->cloneRepository($repository);
        }

        try {
            $sshCommand = $this->sshManager->getGitSSHCommand();
            $commands = [
                "cd {$workspacePath}",
                "GIT_SSH_COMMAND=\"{$sshCommand}\" git fetch origin",
                "git checkout {$repository->default_branch}",
                "GIT_SSH_COMMAND=\"{$sshCommand}\" git pull origin {$repository->default_branch}"
            ];

            $output = '';
            foreach ($commands as $command) {
                $result = shell_exec($command . ' 2>&1');
                $output .= $result . "\n";
            }

            // Aktuellen Commit-Hash ermitteln
            $commitHash = trim(shell_exec("cd {$workspacePath} && git rev-parse HEAD"));

            // Repository-Metadaten aktualisieren
            $repository->update([
                'last_synced_at' => now(),
                'current_commit_hash' => $commitHash
            ]);

            $this->logger->info('Repository erfolgreich synchronisiert', [
                'repository' => $repository->full_name,
                'commit_hash' => $commitHash
            ]);

            return [
                'success' => true,
                'action' => 'synced',
                'commit_hash' => $commitHash,
                'output' => $output
            ];

        } catch (Exception $e) {
            $this->logger->error('Repository-Synchronisation fehlgeschlagen', [
                'repository' => $repository->full_name,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Erkennt und aktualisiert Framework-Typ
     */
    public function detectAndUpdateFramework(Repository $repository): ?string
    {
        $this->logger->debug('Erkenne Framework-Typ', [
            'repository' => $repository->full_name
        ]);

        $frameworkType = $repository->detectFramework();

        if ($frameworkType && $frameworkType !== $repository->framework_type) {
            $repository->update([
                'framework_type' => $frameworkType,
                'package_manager' => $this->getPackageManagerForFramework($frameworkType)
            ]);

            $this->logger->info('Framework-Typ erkannt und aktualisiert', [
                'repository' => $repository->full_name,
                'framework_type' => $frameworkType
            ]);
        }

        return $frameworkType;
    }

    /**
     * Deployed SSH-Key für Repository
     */
    public function deploySSHKey(Repository $repository, string $fingerprint): void
    {
        $this->logger->info('Markiere SSH-Key als deployed', [
            'repository' => $repository->full_name,
            'fingerprint' => $fingerprint
        ]);

        $repository->markSSHKeyDeployed($fingerprint);

        $this->logger->info('SSH-Key erfolgreich als deployed markiert', [
            'repository' => $repository->full_name
        ]);
    }

    /**
     * Prüft SSH-Key-Status für Repository
     */
    public function checkSSHKeyStatus(Repository $repository): array
    {
        $status = $repository->ssh_key_status;
        
        return [
            'repository' => $repository->full_name,
            'ssh_key_deployed' => $repository->ssh_key_deployed,
            'status' => $status,
            'fingerprint' => $repository->deploy_key_fingerprint,
            'deployed_at' => $repository->ssh_key_deployed_at,
            'needs_action' => in_array($status, ['not_deployed', 'needs_rotation'])
        ];
    }

    /**
     * Holt Repository-Konfiguration (gecacht)
     */
    public function getRepositoryConfig(string $fullName): ?array
    {
        $repository = $this->findByFullName($fullName);
        
        if (!$repository) {
            return null;
        }

        return $repository->getCachedConfig();
    }

    /**
     * Holt alle Projekte eines Repositories (gecacht)
     */
    public function getRepositoryProjects(string $fullName): Collection
    {
        $repository = $this->findByFullName($fullName);
        
        if (!$repository) {
            return collect();
        }

        return $repository->getCachedProjects();
    }

    /**
     * Holt Repositories die Synchronisation benötigen
     */
    public function getRepositoriesNeedingSync(): Collection
    {
        return Cache::remember('repositories:needing_sync', 300, function () {
            return Repository::active()
                            ->botEnabled()
                            ->needingSync()
                            ->get();
        });
    }

    /**
     * Aktualisiert Repository-Statistiken
     */
    public function updateCommitStats(Repository $repository, string $commitHash): void
    {
        $this->logger->debug('Aktualisiere Commit-Statistiken', [
            'repository' => $repository->full_name,
            'commit_hash' => $commitHash
        ]);

        $repository->updateCommitStats($commitHash);
    }

    public function updatePRStats(Repository $repository): void
    {
        $this->logger->debug('Aktualisiere PR-Statistiken', [
            'repository' => $repository->full_name
        ]);

        $repository->updatePRStats();
    }

    /**
     * Validiert Repository-Konfiguration
     */
    public function validateRepositoryConfig(array $config): array
    {
        $errors = [];

        if (empty($config['full_name'])) {
            $errors[] = 'Repository Full-Name ist erforderlich';
        } elseif (!preg_match('/^[a-zA-Z0-9\-_]+\/[a-zA-Z0-9\-_\.]+$/', $config['full_name'])) {
            $errors[] = 'Repository Full-Name muss Format "owner/repo" haben';
        }

        if (isset($config['clone_url']) && !filter_var($config['clone_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Clone-URL ist keine gültige URL';
        }

        if (isset($config['max_file_size']) && $config['max_file_size'] < 1024) {
            $errors[] = 'Max-File-Size muss mindestens 1024 Bytes betragen';
        }

        if (isset($config['provider']) && !in_array($config['provider'], ['github', 'gitlab', 'bitbucket', 'azure_devops'])) {
            $errors[] = 'Ungültiger Provider';
        }

        return $errors;
    }

    /**
     * Importiert Repository von Provider
     */
    public function importFromProvider(string $fullName, string $provider = 'github'): Repository
    {
        $this->logger->info('Importiere Repository von Provider', [
            'full_name' => $fullName,
            'provider' => $provider
        ]);

        // TODO: Provider-API-Integration für Repository-Import
        // Für jetzt: Basis-Repository erstellen
        
        $repository = $this->createRepository([
            'full_name' => $fullName,
            'provider' => $provider,
            'bot_enabled' => false, // Erst nach Konfiguration aktivieren
            'is_active' => true
        ]);

        $this->logger->info('Repository von Provider importiert', [
            'repository_id' => $repository->id,
            'full_name' => $fullName
        ]);

        return $repository;
    }

    /**
     * Holt Repository-Statistiken
     */
    public function getRepositoryStats(Repository $repository): array
    {
        return Cache::remember("repository:{$repository->full_name}:stats", 1800, function () use ($repository) {
            return [
                'total_commits' => $repository->total_commits,
                'total_prs_created' => $repository->total_prs_created,
                'total_prs_merged' => $repository->total_prs_merged,
                'pr_success_rate' => $repository->pr_success_rate,
                'last_commit_at' => $repository->last_commit_at,
                'last_pr_created_at' => $repository->last_pr_created_at,
                'last_synced_at' => $repository->last_synced_at,
                'is_stale' => $repository->is_stale,
                'framework_detected' => $repository->framework_detected,
                'framework_type' => $repository->framework_type,
                'ssh_key_status' => $repository->ssh_key_status,
                'projects_count' => $repository->projects()->count(),
                'active_projects_count' => $repository->activeProjects()->count(),
                'tickets_count' => $repository->tickets()->count(),
                'pending_tickets' => $repository->tickets()->where('status', 'pending')->count(),
                'completed_tickets' => $repository->tickets()->where('status', 'completed')->count()
            ];
        });
    }

    /**
     * Löscht alle Caches
     */
    public function clearAllCaches(): void
    {
        $this->logger->info('Lösche alle Repository-Caches');
        Repository::clearAllCache();
    }

    /**
     * Holt Übersicht aller Repositories
     */
    public function getRepositoriesOverview(): array
    {
        return Cache::remember('repositories:overview', 900, function () {
            $repositories = Repository::active()->with(['projects'])->get();
            
            return $repositories->map(function ($repository) {
                return [
                    'id' => $repository->id,
                    'full_name' => $repository->full_name,
                    'provider' => $repository->provider,
                    'framework_type' => $repository->framework_type,
                    'bot_enabled' => $repository->bot_enabled,
                    'is_active' => $repository->is_active,
                    'ssh_key_deployed' => $repository->ssh_key_deployed,
                    'ssh_key_status' => $repository->ssh_key_status,
                    'total_commits' => $repository->total_commits,
                    'total_prs_created' => $repository->total_prs_created,
                    'pr_success_rate' => $repository->pr_success_rate,
                    'last_synced_at' => $repository->last_synced_at,
                    'is_stale' => $repository->is_stale,
                    'projects_count' => $repository->projects->count(),
                    'provider_url' => $repository->provider_url
                ];
            })->toArray();
        });
    }

    /**
     * Private Helper Methods
     */
    private function parseFullName(string $fullName): array
    {
        $parts = explode('/', $fullName, 2);
        
        if (count($parts) !== 2) {
            throw new Exception("Ungültiger Repository Full-Name: {$fullName}");
        }

        return [$parts[0], $parts[1]];
    }

    private function generateCloneUrl(string $fullName, string $provider): string
    {
        return match($provider) {
            'github' => "https://github.com/{$fullName}.git",
            'gitlab' => "https://gitlab.com/{$fullName}.git",
            'bitbucket' => "https://bitbucket.org/{$fullName}.git",
            default => "https://github.com/{$fullName}.git"
        };
    }

    private function generateWebUrl(string $fullName, string $provider): string
    {
        return match($provider) {
            'github' => "https://github.com/{$fullName}",
            'gitlab' => "https://gitlab.com/{$fullName}",
            'bitbucket' => "https://bitbucket.org/{$fullName}",
            default => "https://github.com/{$fullName}"
        };
    }

    private function getPackageManagerForFramework(string $frameworkType): ?string
    {
        return match($frameworkType) {
            'laravel' => 'composer',
            'nodejs', 'react', 'vue', 'nextjs' => 'npm',
            'python' => 'pip',
            default => null
        };
    }

    private function ensureSSHKeysConfigured(): void
    {
        if (!$this->sshManager->isConfigured()) {
            $result = $this->sshManager->initializeSSHKeys();
            
            if (!$result['success']) {
                throw new Exception('SSH-Key-Initialisierung fehlgeschlagen: ' . $result['error']);
            }
        }
    }
} 