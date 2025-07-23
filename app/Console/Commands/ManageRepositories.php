<?php

namespace App\Console\Commands;

use App\Services\RepositoryService;
use App\Services\ProjectService;
use App\Models\Repository;
use Illuminate\Console\Command;
use Exception;

class ManageRepositories extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'octomind:repository 
                           {action : Action to perform (list|create|show|update|clone|sync|deploy-ssh|check-ssh|import|stats|clear-cache)}
                           {repository? : Repository full name (owner/repo)}
                           {--provider=github : Git provider (github|gitlab|bitbucket)}
                           {--description= : Repository description}
                           {--clone-url= : Custom clone URL}
                           {--default-branch=main : Default branch name}
                           {--private : Repository is private}
                           {--bot-enabled : Enable bot for this repository}
                           {--framework= : Framework type}
                           {--branch-prefix=octomind : Branch prefix for bot}
                           {--force : Force operation}
                           {--fingerprint= : SSH key fingerprint}';

    /**
     * The console command description.
     */
    protected $description = 'Manage Octomind repositories and their configurations';

    private RepositoryService $repositoryService;
    private ProjectService $projectService;

    public function __construct()
    {
        parent::__construct();
        $this->repositoryService = new RepositoryService();
        $this->projectService = new ProjectService();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action');

        $this->displayBanner();

        return match ($action) {
            'list' => $this->listRepositories(),
            'create' => $this->createRepository(),
            'show' => $this->showRepository(),
            'update' => $this->updateRepository(),
            'clone' => $this->cloneRepository(),
            'sync' => $this->syncRepository(),
            'deploy-ssh' => $this->deploySSHKey(),
            'check-ssh' => $this->checkSSHKey(),
            'import' => $this->importRepository(),
            'stats' => $this->showStats(),
            'clear-cache' => $this->clearCache(),
            default => $this->showHelp()
        };
    }

    private function displayBanner(): void
    {
        $this->info('');
        $this->info('🔗 Octomind Repository Management');
        $this->info('==================================');
        $this->info('');
    }

    private function listRepositories(): int
    {
        $this->info('🔗 Aktive Repositories:');
        $this->info('');

        $repositories = $this->repositoryService->getRepositoriesOverview();

        if (empty($repositories)) {
            $this->warn('Keine Repositories gefunden.');
            return Command::SUCCESS;
        }

        $headers = ['Full Name', 'Provider', 'Framework', 'Bot', 'SSH', 'Commits', 'PRs', 'Projekte', 'Letzter Sync'];
        $rows = [];

        foreach ($repositories as $repo) {
            $sshStatus = match($repo['ssh_key_status']) {
                'active' => '✅',
                'not_deployed' => '❌',
                'needs_rotation' => '⚠️',
                default => '❓'
            };

            $rows[] = [
                $repo['full_name'],
                ucfirst($repo['provider']),
                $repo['framework_type'] ?? 'Unbekannt',
                $repo['bot_enabled'] ? '✅' : '❌',
                $sshStatus,
                $repo['total_commits'],
                $repo['total_prs_created'],
                $repo['projects_count'],
                $repo['last_synced_at'] ? $repo['last_synced_at']->diffForHumans() : 'Nie'
            ];
        }

        $this->table($headers, $rows);

        return Command::SUCCESS;
    }

    private function createRepository(): int
    {
        $fullName = $this->argument('repository');

        if (!$fullName) {
            $fullName = $this->ask('Repository Full-Name (owner/repo)');
        }

        $provider = $this->option('provider');

        $this->info("🔗 Erstelle Repository: {$fullName}");

        try {
            $repository = $this->repositoryService->createRepository([
                'full_name' => $fullName,
                'provider' => $provider,
                'description' => $this->option('description'),
                'clone_url' => $this->option('clone-url'),
                'default_branch' => $this->option('default-branch'),
                'is_private' => $this->option('private'),
                'bot_enabled' => $this->option('bot-enabled'),
                'framework_type' => $this->option('framework'),
                'branch_prefix' => $this->option('branch-prefix')
            ]);

            $this->info('✅ Repository erfolgreich erstellt!');
            $this->info('');
            $this->info("🔗 Repository-Details:");
            $this->info("   ID: {$repository->id}");
            $this->info("   Full Name: {$repository->full_name}");
            $this->info("   Provider: {$repository->provider}");
            $this->info("   Clone URL: {$repository->clone_url}");
            $this->info("   SSH URL: {$repository->ssh_url}");
            $this->info("   Web URL: {$repository->provider_url}");
            $this->info("   Bot aktiviert: " . ($repository->bot_enabled ? 'Ja' : 'Nein'));
            $this->info("   Workspace-Pfad: {$repository->local_workspace_path}");

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('❌ Repository-Erstellung fehlgeschlagen:');
            $this->error('   ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function showRepository(): int
    {
        $fullName = $this->argument('repository');

        if (!$fullName) {
            $fullName = $this->ask('Repository Full-Name (owner/repo)');
        }

        $repository = $this->repositoryService->findByFullName($fullName);

        if (!$repository) {
            $this->error("❌ Repository '{$fullName}' nicht gefunden.");
            return Command::FAILURE;
        }

        $this->info("🔗 Repository: {$repository->full_name}");
        $this->info('');

        // Basis-Informationen
        $this->info('📊 Basis-Informationen:');
        $this->info("   Owner: {$repository->owner}");
        $this->info("   Name: {$repository->name}");
        $this->info("   Beschreibung: " . ($repository->description ?? 'Keine'));
        $this->info("   Provider: {$repository->provider}");
        $this->info("   Privat: " . ($repository->is_private ? 'Ja' : 'Nein'));
        $this->info("   Standard-Branch: {$repository->default_branch}");
        $this->info('');

        // URLs
        $this->info('🌐 URLs:');
        $this->info("   Clone (HTTPS): {$repository->clone_url}");
        $this->info("   Clone (SSH): {$repository->ssh_url}");
        $this->info("   Web: {$repository->provider_url}");
        $this->info('');

        // Bot-Konfiguration
        $this->info('🤖 Bot-Konfiguration:');
        $this->info("   Bot aktiviert: " . ($repository->bot_enabled ? 'Ja' : 'Nein'));
        $this->info("   Branch-Prefix: {$repository->branch_prefix}");
        $this->info("   Draft PRs: " . ($repository->create_draft_prs ? 'Ja' : 'Nein'));
        $this->info("   Auto-Merge: " . ($repository->auto_merge_enabled ? 'Ja' : 'Nein'));
        $this->info("   Erlaubte Dateierweiterungen: " . implode(', ', $repository->allowed_file_extensions));
        $this->info("   Verbotene Pfade: " . implode(', ', $repository->forbidden_paths));
        $this->info("   Max Dateigröße: " . number_format($repository->max_file_size / 1024, 0) . ' KB');
        $this->info('');

        // Framework-Erkennung
        $this->info('🔧 Framework:');
        $this->info("   Typ: " . ($repository->framework_type ?? 'Nicht erkannt'));
        $this->info("   Package Manager: " . ($repository->package_manager ?? 'Unbekannt'));
        $this->info("   Framework erkannt: " . ($repository->framework_detected ? 'Ja' : 'Nein'));
        $this->info('');

        // SSH-Key-Status
        $sshStatus = $this->repositoryService->checkSSHKeyStatus($repository);
        $this->info('🔐 SSH-Key-Status:');
        $this->info("   Status: {$sshStatus['status']}");
        $this->info("   Deployed: " . ($sshStatus['ssh_key_deployed'] ? 'Ja' : 'Nein'));
        $this->info("   Fingerprint: " . ($sshStatus['fingerprint'] ?? 'Nicht verfügbar'));
        $this->info("   Deployed am: " . ($sshStatus['deployed_at'] ? $sshStatus['deployed_at']->format('Y-m-d H:i:s') : 'Nie'));
        $this->info("   Aktion erforderlich: " . ($sshStatus['needs_action'] ? 'Ja' : 'Nein'));
        $this->info('');

        // Workspace-Informationen
        $this->info('💾 Workspace:');
        $this->info("   Lokaler Pfad: {$repository->local_workspace_path}");
        $this->info("   Letztes Klonen: " . ($repository->last_cloned_at ? $repository->last_cloned_at->diffForHumans() : 'Nie'));
        $this->info("   Letzter Sync: " . ($repository->last_synced_at ? $repository->last_synced_at->diffForHumans() : 'Nie'));
        $this->info("   Aktueller Commit: " . ($repository->current_commit_hash ? substr($repository->current_commit_hash, 0, 8) : 'Unbekannt'));
        $this->info("   Ist veraltet: " . ($repository->is_stale ? 'Ja' : 'Nein'));
        $this->info('');

        // Statistiken
        $stats = $this->repositoryService->getRepositoryStats($repository);
        $this->info('📈 Statistiken:');
        $this->info("   Gesamt Commits: {$stats['total_commits']}");
        $this->info("   PRs erstellt: {$stats['total_prs_created']}");
        $this->info("   PRs gemerged: {$stats['total_prs_merged']}");
        $this->info("   PR-Erfolgsrate: " . number_format($stats['pr_success_rate'] * 100, 1) . '%');
        $this->info("   Letzter Commit: " . ($stats['last_commit_at'] ? $stats['last_commit_at']->diffForHumans() : 'Nie'));
        $this->info("   Letzte PR: " . ($stats['last_pr_created_at'] ? $stats['last_pr_created_at']->diffForHumans() : 'Nie'));
        $this->info('');

        // Verknüpfte Projekte
        $projects = $repository->projects()->get();
        if ($projects->count() > 0) {
            $this->info('📋 Verknüpfte Projekte:');
            foreach ($projects as $project) {
                $pivot = $project->pivot;
                $defaultMarker = $pivot->is_default ? ' [STANDARD]' : '';
                $this->info("   • {$project->jira_key} ({$project->name}){$defaultMarker}");
                $this->info("     Priorität: {$pivot->priority}, Strategie: {$pivot->branch_strategy}");
            }
        } else {
            $this->warn('   Keine Projekte verknüpft');
        }

        return Command::SUCCESS;
    }

    private function updateRepository(): int
    {
        $fullName = $this->argument('repository');

        if (!$fullName) {
            $fullName = $this->ask('Repository Full-Name (owner/repo)');
        }

        $repository = $this->repositoryService->findByFullName($fullName);

        if (!$repository) {
            $this->error("❌ Repository '{$fullName}' nicht gefunden.");
            return Command::FAILURE;
        }

        $this->info("🔗 Aktualisiere Repository: {$repository->full_name}");

        $updateData = [];

        if ($this->option('description')) {
            $updateData['description'] = $this->option('description');
        }

        if ($this->option('default-branch')) {
            $updateData['default_branch'] = $this->option('default-branch');
        }

        if ($this->option('bot-enabled')) {
            $updateData['bot_enabled'] = true;
        }

        if ($this->option('framework')) {
            $updateData['framework_type'] = $this->option('framework');
        }

        if ($this->option('branch-prefix')) {
            $updateData['branch_prefix'] = $this->option('branch-prefix');
        }

        if (empty($updateData)) {
            $this->warn('Keine Aktualisierungen angegeben.');
            return Command::SUCCESS;
        }

        try {
            $this->repositoryService->updateRepository($repository, $updateData);
            $this->info('✅ Repository erfolgreich aktualisiert!');
            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('❌ Repository-Aktualisierung fehlgeschlagen:');
            $this->error('   ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function cloneRepository(): int
    {
        $fullName = $this->argument('repository');

        if (!$fullName) {
            $fullName = $this->ask('Repository Full-Name (owner/repo)');
        }

        $repository = $this->repositoryService->findByFullName($fullName);

        if (!$repository) {
            $this->error("❌ Repository '{$fullName}' nicht gefunden.");
            return Command::FAILURE;
        }

        $force = $this->option('force');

        $this->info("📥 Klone Repository: {$repository->full_name}");

        if ($force) {
            $this->warn('⚠️  Force-Modus aktiviert - existierendes Repository wird überschrieben');
        }

        try {
            $result = $this->repositoryService->cloneRepository($repository, $force);

            if ($result['success']) {
                $this->info("✅ Repository erfolgreich geklont!");
                $this->info("   Pfad: {$result['path']}");
                $this->info("   Aktion: {$result['action']}");

                // Framework-Erkennung anzeigen
                if ($repository->framework_type) {
                    $this->info("   Framework erkannt: {$repository->framework_type}");
                }
            } else {
                $this->error("❌ Repository-Kloning fehlgeschlagen:");
                $this->error("   {$result['error']}");
                return Command::FAILURE;
            }

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('❌ Repository-Kloning fehlgeschlagen:');
            $this->error('   ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function syncRepository(): int
    {
        $fullName = $this->argument('repository');

        if (!$fullName) {
            $fullName = $this->ask('Repository Full-Name (owner/repo)');
        }

        $repository = $this->repositoryService->findByFullName($fullName);

        if (!$repository) {
            $this->error("❌ Repository '{$fullName}' nicht gefunden.");
            return Command::FAILURE;
        }

        $this->info("🔄 Synchronisiere Repository: {$repository->full_name}");

        try {
            $result = $this->repositoryService->syncRepository($repository);

            if ($result['success']) {
                $this->info("✅ Repository erfolgreich synchronisiert!");
                $this->info("   Aktion: {$result['action']}");
                
                if (isset($result['commit_hash'])) {
                    $this->info("   Aktueller Commit: " . substr($result['commit_hash'], 0, 8));
                }
            } else {
                $this->error("❌ Repository-Synchronisation fehlgeschlagen:");
                $this->error("   {$result['error']}");
                return Command::FAILURE;
            }

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('❌ Repository-Synchronisation fehlgeschlagen:');
            $this->error('   ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function deploySSHKey(): int
    {
        $fullName = $this->argument('repository');
        $fingerprint = $this->option('fingerprint');

        if (!$fullName) {
            $fullName = $this->ask('Repository Full-Name (owner/repo)');
        }

        if (!$fingerprint) {
            $fingerprint = $this->ask('SSH-Key-Fingerprint');
        }

        $repository = $this->repositoryService->findByFullName($fullName);

        if (!$repository) {
            $this->error("❌ Repository '{$fullName}' nicht gefunden.");
            return Command::FAILURE;
        }

        $this->info("🔐 Markiere SSH-Key als deployed für: {$repository->full_name}");

        try {
            $this->repositoryService->deploySSHKey($repository, $fingerprint);
            $this->info('✅ SSH-Key erfolgreich als deployed markiert!');
            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('❌ SSH-Key-Deployment fehlgeschlagen:');
            $this->error('   ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function checkSSHKey(): int
    {
        $fullName = $this->argument('repository');

        if (!$fullName) {
            $fullName = $this->ask('Repository Full-Name (owner/repo)');
        }

        $repository = $this->repositoryService->findByFullName($fullName);

        if (!$repository) {
            $this->error("❌ Repository '{$fullName}' nicht gefunden.");
            return Command::FAILURE;
        }

        $this->info("🔐 SSH-Key-Status für: {$repository->full_name}");
        $this->info('');

        $status = $this->repositoryService->checkSSHKeyStatus($repository);

        $statusIcon = match($status['status']) {
            'active' => '✅',
            'not_deployed' => '❌',
            'needs_rotation' => '⚠️',
            default => '❓'
        };

        $this->info("Status: {$statusIcon} {$status['status']}");
        $this->info("SSH-Key deployed: " . ($status['ssh_key_deployed'] ? 'Ja' : 'Nein'));
        
        if ($status['fingerprint']) {
            $this->info("Fingerprint: {$status['fingerprint']}");
        }
        
        if ($status['deployed_at']) {
            $this->info("Deployed am: {$status['deployed_at']->format('Y-m-d H:i:s')}");
        }

        if ($status['needs_action']) {
            $this->warn('⚠️  Aktion erforderlich!');
            
            if ($status['status'] === 'not_deployed') {
                $this->info('📋 Nächste Schritte:');
                $this->info('   1. SSH-Key generieren: php artisan octomind:ssh-keys init');
                $this->info('   2. Public Key zu Repository hinzufügen');
                $this->info('   3. Als deployed markieren: php artisan octomind:repository deploy-ssh ' . $fullName . ' --fingerprint=...');
            } elseif ($status['status'] === 'needs_rotation') {
                $this->info('📋 SSH-Key-Rotation empfohlen:');
                $this->info('   1. Neue Keys generieren: php artisan octomind:ssh-keys rotate');
                $this->info('   2. Public Key in Repository aktualisieren');
                $this->info('   3. Als deployed markieren: php artisan octomind:repository deploy-ssh ' . $fullName . ' --fingerprint=...');
            }
        } else {
            $this->info('✅ SSH-Key-Status ist in Ordnung');
        }

        return Command::SUCCESS;
    }

    private function importRepository(): int
    {
        $fullName = $this->argument('repository');
        $provider = $this->option('provider');

        if (!$fullName) {
            $fullName = $this->ask('Repository Full-Name (owner/repo)');
        }

        $this->info("📥 Importiere Repository '{$fullName}' von {$provider}...");

        try {
            $repository = $this->repositoryService->importFromProvider($fullName, $provider);

            $this->info('✅ Repository erfolgreich importiert!');
            $this->warn('⚠️  Repository ist zunächst deaktiviert. Aktiviere es nach der Konfiguration.');
            $this->info('');
            $this->info("📋 Nächste Schritte:");
            $this->info("   1. Repository klonen: php artisan octomind:repository clone {$fullName}");
            $this->info("   2. SSH-Key deployen: php artisan octomind:repository deploy-ssh {$fullName} --fingerprint=...");
            $this->info("   3. Bot aktivieren: php artisan octomind:repository update {$fullName} --bot-enabled");
            $this->info("   4. Mit Projekt verknüpfen: php artisan octomind:project attach-repo PROJECT --repository={$fullName}");

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('❌ Repository-Import fehlgeschlagen:');
            $this->error('   ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function showStats(): int
    {
        $this->info('📊 Repository-Statistiken:');
        $this->info('');

        $repositories = $this->repositoryService->getActiveRepositories();

        if ($repositories->isEmpty()) {
            $this->warn('Keine aktiven Repositories gefunden.');
            return Command::SUCCESS;
        }

        $totalCommits = 0;
        $totalPRs = 0;
        $totalMerged = 0;
        $sshDeployed = 0;

        foreach ($repositories as $repository) {
            $stats = $this->repositoryService->getRepositoryStats($repository);
            
            $this->info("🔗 {$repository->full_name}:");
            $this->info("   Commits: {$stats['total_commits']} | PRs: {$stats['total_prs_created']} | Merged: {$stats['total_prs_merged']}");
            $this->info("   PR-Erfolgsrate: " . number_format($stats['pr_success_rate'] * 100, 1) . '%');
            $this->info("   Framework: " . ($stats['framework_type'] ?? 'Unbekannt'));
            $this->info("   SSH-Status: {$stats['ssh_key_status']}");
            $this->info("   Projekte: {$stats['projects_count']} | Tickets: {$stats['tickets_count']}");
            $this->info('');

            $totalCommits += $stats['total_commits'];
            $totalPRs += $stats['total_prs_created'];
            $totalMerged += $stats['total_prs_merged'];
            
            if ($stats['ssh_key_status'] === 'active') {
                $sshDeployed++;
            }
        }

        $overallPRSuccessRate = $totalPRs > 0 ? ($totalMerged / $totalPRs) * 100 : 0;

        $this->info('📈 Gesamt-Statistiken:');
        $this->info("   Repositories: {$repositories->count()}");
        $this->info("   SSH-Keys deployed: {$sshDeployed}");
        $this->info("   Gesamt Commits: {$totalCommits}");
        $this->info("   Gesamt PRs: {$totalPRs}");
        $this->info("   Gesamt Merged: {$totalMerged}");
        $this->info("   Gesamt-PR-Erfolgsrate: " . number_format($overallPRSuccessRate, 1) . '%');

        return Command::SUCCESS;
    }

    private function clearCache(): int
    {
        $this->info('🗑️ Lösche Repository-Caches...');

        $this->repositoryService->clearAllCaches();

        $this->info('✅ Alle Repository-Caches gelöscht!');
        return Command::SUCCESS;
    }

    private function showHelp(): int
    {
        $this->error('❌ Unbekannte Aktion. Verfügbare Aktionen:');
        $this->info('');
        $this->info('🔗 Repository-Management:');
        $this->info('  list                       - Alle Repositories auflisten');
        $this->info('  create <owner/repo>        - Neues Repository erstellen');
        $this->info('  show <owner/repo>          - Repository-Details anzeigen');
        $this->info('  update <owner/repo>        - Repository aktualisieren');
        $this->info('  import <owner/repo>        - Repository von Provider importieren');
        $this->info('  stats                      - Repository-Statistiken anzeigen');
        $this->info('');
        $this->info('💾 Workspace-Management:');
        $this->info('  clone <owner/repo>         - Repository klonen');
        $this->info('  sync <owner/repo>          - Repository synchronisieren');
        $this->info('');
        $this->info('🔐 SSH-Key-Management:');
        $this->info('  deploy-ssh <owner/repo>    - SSH-Key als deployed markieren');
        $this->info('  check-ssh <owner/repo>     - SSH-Key-Status prüfen');
        $this->info('');
        $this->info('🛠️ Wartung:');
        $this->info('  clear-cache                - Alle Caches löschen');
        $this->info('');
        $this->info('Beispiele:');
        $this->info('  php artisan octomind:repository create owner/repo --provider=github --bot-enabled');
        $this->info('  php artisan octomind:repository clone owner/repo');
        $this->info('  php artisan octomind:repository deploy-ssh owner/repo --fingerprint=SHA256:abc123...');
        $this->info('  php artisan octomind:repository show owner/repo');

        return Command::FAILURE;
    }
} 