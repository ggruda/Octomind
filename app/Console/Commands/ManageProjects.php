<?php

namespace App\Console\Commands;

use App\Services\ProjectService;
use App\Services\RepositoryService;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Console\Command;
use Exception;

class ManageProjects extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'octomind:project 
                           {action : Action to perform (list|create|show|update|attach-repo|set-default-repo|import|stats|clear-cache)}
                           {project? : Project Jira-Key}
                           {--name= : Project name}
                           {--description= : Project description}
                           {--jira-base-url= : Jira base URL}
                           {--bot-enabled : Enable bot for this project}
                           {--required-label=ai-bot : Required label for tickets}
                           {--fetch-interval=300 : Fetch interval in seconds}
                           {--repository= : Repository full name (owner/repo)}
                           {--default : Set as default repository}
                           {--priority=1 : Repository priority}
                           {--branch-strategy=feature : Branch strategy}
                           {--labels= : Comma-separated labels for routing}
                           {--components= : Comma-separated components for routing}';

    /**
     * The console command description.
     */
    protected $description = 'Manage Octomind projects and their repository associations';

    private ProjectService $projectService;
    private RepositoryService $repositoryService;

    public function __construct()
    {
        parent::__construct();
        $this->projectService = new ProjectService();
        $this->repositoryService = new RepositoryService();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action');

        $this->displayBanner();

        return match ($action) {
            'list' => $this->listProjects(),
            'create' => $this->createProject(),
            'show' => $this->showProject(),
            'update' => $this->updateProject(),
            'attach-repo' => $this->attachRepository(),
            'set-default-repo' => $this->setDefaultRepository(),
            'import' => $this->importProject(),
            'stats' => $this->showStats(),
            'clear-cache' => $this->clearCache(),
            default => $this->showHelp()
        };
    }

    private function displayBanner(): void
    {
        $this->info('');
        $this->info('📋 Octomind Project Management');
        $this->info('===============================');
        $this->info('');
    }

    private function listProjects(): int
    {
        $this->info('📋 Aktive Projekte:');
        $this->info('');

        $projects = $this->projectService->getProjectsOverview();

        if (empty($projects)) {
            $this->warn('Keine Projekte gefunden.');
            return Command::SUCCESS;
        }

        $headers = ['Jira-Key', 'Name', 'Bot', 'Tickets', 'Erfolgsrate', 'Repositories', 'Standard-Repo', 'Letzter Sync'];
        $rows = [];

        foreach ($projects as $project) {
            $rows[] = [
                $project['jira_key'],
                $project['name'],
                $project['bot_enabled'] ? '✅' : '❌',
                $project['total_tickets'],
                number_format($project['success_rate'] * 100, 1) . '%',
                $project['repositories_count'],
                $project['default_repository'] ?? '-',
                $project['last_sync_at'] ? $project['last_sync_at']->diffForHumans() : 'Nie'
            ];
        }

        $this->table($headers, $rows);

        return Command::SUCCESS;
    }

    private function createProject(): int
    {
        $jiraKey = $this->argument('project');
        $name = $this->option('name');
        $jiraBaseUrl = $this->option('jira-base-url');

        if (!$jiraKey) {
            $jiraKey = $this->ask('Jira-Key (z.B. PROJ, DEV)');
        }

        if (!$name) {
            $name = $this->ask('Projekt-Name');
        }

        if (!$jiraBaseUrl) {
            $jiraBaseUrl = $this->ask('Jira-Base-URL (z.B. https://company.atlassian.net)');
        }

        $this->info("📋 Erstelle Projekt: {$jiraKey}");

        try {
            $project = $this->projectService->createProject([
                'jira_key' => $jiraKey,
                'name' => $name,
                'description' => $this->option('description'),
                'jira_base_url' => $jiraBaseUrl,
                'bot_enabled' => $this->option('bot-enabled'),
                'required_label' => $this->option('required-label'),
                'fetch_interval' => (int) $this->option('fetch-interval')
            ]);

            $this->info('✅ Projekt erfolgreich erstellt!');
            $this->info('');
            $this->info("📋 Projekt-Details:");
            $this->info("   ID: {$project->id}");
            $this->info("   Jira-Key: {$project->jira_key}");
            $this->info("   Name: {$project->name}");
            $this->info("   Jira-URL: {$project->jira_url}");
            $this->info("   Bot aktiviert: " . ($project->bot_enabled ? 'Ja' : 'Nein'));

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('❌ Projekt-Erstellung fehlgeschlagen:');
            $this->error('   ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function showProject(): int
    {
        $jiraKey = $this->argument('project');

        if (!$jiraKey) {
            $jiraKey = $this->ask('Jira-Key des Projekts');
        }

        $project = $this->projectService->findByJiraKey($jiraKey);

        if (!$project) {
            $this->error("❌ Projekt '{$jiraKey}' nicht gefunden.");
            return Command::FAILURE;
        }

        $this->info("📋 Projekt: {$project->name} ({$project->jira_key})");
        $this->info('');

        // Basis-Informationen
        $this->info('📊 Basis-Informationen:');
        $this->info("   Name: {$project->name}");
        $this->info("   Beschreibung: " . ($project->description ?? 'Keine'));
        $this->info("   Jira-URL: {$project->jira_url}");
        $this->info("   Typ: {$project->project_type}");
        $this->info("   Kategorie: " . ($project->project_category ?? 'Keine'));
        $this->info('');

        // Konfiguration
        $this->info('⚙️ Konfiguration:');
        $this->info("   Bot aktiviert: " . ($project->bot_enabled ? 'Ja' : 'Nein'));
        $this->info("   Erforderliches Label: {$project->required_label}");
        $this->info("   Nur unassigned: " . ($project->require_unassigned ? 'Ja' : 'Nein'));
        $this->info("   Erlaubte Status: " . implode(', ', $project->allowed_statuses));
        $this->info("   Fetch-Intervall: {$project->fetch_interval}s");
        $this->info('');

        // Statistiken
        $stats = $this->projectService->getProjectStats($project);
        $this->info('📈 Statistiken:');
        $this->info("   Gesamt Tickets: {$stats['total_tickets']}");
        $this->info("   Erfolgreich: {$stats['successful_tickets']}");
        $this->info("   Fehlgeschlagen: {$stats['failed_tickets']}");
        $this->info("   Erfolgsrate: " . number_format($stats['success_rate'] * 100, 1) . '%');
        $this->info("   Letzter Sync: " . ($project->last_sync_at ? $project->last_sync_at->diffForHumans() : 'Nie'));
        $this->info('');

        // Repositories
        $repositories = $project->repositories()->with('pivot')->get();
        if ($repositories->count() > 0) {
            $this->info('🔗 Verknüpfte Repositories:');
            foreach ($repositories as $repo) {
                $pivot = $repo->pivot;
                $defaultMarker = $pivot->is_default ? ' [STANDARD]' : '';
                $this->info("   • {$repo->full_name}{$defaultMarker}");
                $this->info("     Priorität: {$pivot->priority}, Strategie: {$pivot->branch_strategy}");
                $this->info("     Tickets verarbeitet: {$pivot->tickets_processed}");
            }
        } else {
            $this->warn('   Keine Repositories verknüpft');
        }

        return Command::SUCCESS;
    }

    private function updateProject(): int
    {
        $jiraKey = $this->argument('project');

        if (!$jiraKey) {
            $jiraKey = $this->ask('Jira-Key des Projekts');
        }

        $project = $this->projectService->findByJiraKey($jiraKey);

        if (!$project) {
            $this->error("❌ Projekt '{$jiraKey}' nicht gefunden.");
            return Command::FAILURE;
        }

        $this->info("📋 Aktualisiere Projekt: {$project->name}");

        $updateData = [];

        if ($this->option('name')) {
            $updateData['name'] = $this->option('name');
        }

        if ($this->option('description')) {
            $updateData['description'] = $this->option('description');
        }

        if ($this->option('jira-base-url')) {
            $updateData['jira_base_url'] = $this->option('jira-base-url');
        }

        if ($this->option('bot-enabled')) {
            $updateData['bot_enabled'] = true;
        }

        if ($this->option('required-label')) {
            $updateData['required_label'] = $this->option('required-label');
        }

        if ($this->option('fetch-interval')) {
            $updateData['fetch_interval'] = (int) $this->option('fetch-interval');
        }

        if (empty($updateData)) {
            $this->warn('Keine Aktualisierungen angegeben.');
            return Command::SUCCESS;
        }

        try {
            $this->projectService->updateProject($project, $updateData);
            $this->info('✅ Projekt erfolgreich aktualisiert!');
            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('❌ Projekt-Aktualisierung fehlgeschlagen:');
            $this->error('   ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function attachRepository(): int
    {
        $jiraKey = $this->argument('project');
        $repositoryName = $this->option('repository');

        if (!$jiraKey) {
            $jiraKey = $this->ask('Jira-Key des Projekts');
        }

        if (!$repositoryName) {
            $repositoryName = $this->ask('Repository Full-Name (owner/repo)');
        }

        $project = $this->projectService->findByJiraKey($jiraKey);
        if (!$project) {
            $this->error("❌ Projekt '{$jiraKey}' nicht gefunden.");
            return Command::FAILURE;
        }

        $repository = $this->repositoryService->findByFullName($repositoryName);
        if (!$repository) {
            $this->error("❌ Repository '{$repositoryName}' nicht gefunden.");
            $this->info('Verwende: php artisan octomind:repository create ' . $repositoryName);
            return Command::FAILURE;
        }

        $this->info("🔗 Verknüpfe Repository '{$repositoryName}' mit Projekt '{$jiraKey}'");

        try {
            $pivotData = [
                'priority' => (int) $this->option('priority'),
                'branch_strategy' => $this->option('branch-strategy'),
                'is_default' => $this->option('default')
            ];

            // Routing-Konfiguration
            if ($this->option('labels')) {
                $pivotData['label_mapping'] = explode(',', $this->option('labels'));
            }

            if ($this->option('components')) {
                $pivotData['component_mapping'] = explode(',', $this->option('components'));
            }

            $this->projectService->attachRepository($project, $repository, $pivotData);

            $this->info('✅ Repository erfolgreich verknüpft!');

            if ($this->option('default')) {
                $this->info('📌 Als Standard-Repository gesetzt');
            }

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('❌ Repository-Verknüpfung fehlgeschlagen:');
            $this->error('   ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function setDefaultRepository(): int
    {
        $jiraKey = $this->argument('project');
        $repositoryName = $this->option('repository');

        if (!$jiraKey) {
            $jiraKey = $this->ask('Jira-Key des Projekts');
        }

        if (!$repositoryName) {
            $repositoryName = $this->ask('Repository Full-Name (owner/repo)');
        }

        $project = $this->projectService->findByJiraKey($jiraKey);
        if (!$project) {
            $this->error("❌ Projekt '{$jiraKey}' nicht gefunden.");
            return Command::FAILURE;
        }

        $repository = $this->repositoryService->findByFullName($repositoryName);
        if (!$repository) {
            $this->error("❌ Repository '{$repositoryName}' nicht gefunden.");
            return Command::FAILURE;
        }

        try {
            $this->projectService->setDefaultRepository($project, $repository);
            $this->info("✅ '{$repositoryName}' als Standard-Repository für '{$jiraKey}' gesetzt!");
            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('❌ Standard-Repository-Setzung fehlgeschlagen:');
            $this->error('   ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function importProject(): int
    {
        $jiraKey = $this->argument('project');
        $jiraBaseUrl = $this->option('jira-base-url');

        if (!$jiraKey) {
            $jiraKey = $this->ask('Jira-Key des zu importierenden Projekts');
        }

        if (!$jiraBaseUrl) {
            $jiraBaseUrl = $this->ask('Jira-Base-URL');
        }

        $this->info("📥 Importiere Projekt '{$jiraKey}' aus Jira...");

        try {
            $project = $this->projectService->importFromJira($jiraKey, $jiraBaseUrl);

            $this->info('✅ Projekt erfolgreich importiert!');
            $this->warn('⚠️  Projekt ist zunächst deaktiviert. Aktiviere es nach der Konfiguration.');
            $this->info('');
            $this->info("📋 Nächste Schritte:");
            $this->info("   1. Repository verknüpfen: php artisan octomind:project attach-repo {$jiraKey} --repository=owner/repo");
            $this->info("   2. Bot aktivieren: php artisan octomind:project update {$jiraKey} --bot-enabled");

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('❌ Projekt-Import fehlgeschlagen:');
            $this->error('   ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function showStats(): int
    {
        $this->info('📊 Projekt-Statistiken:');
        $this->info('');

        $projects = $this->projectService->getActiveProjects();

        if ($projects->isEmpty()) {
            $this->warn('Keine aktiven Projekte gefunden.');
            return Command::SUCCESS;
        }

        $totalTickets = 0;
        $totalSuccessful = 0;
        $totalFailed = 0;

        foreach ($projects as $project) {
            $stats = $this->projectService->getProjectStats($project);
            
            $this->info("📋 {$project->name} ({$project->jira_key}):");
            $this->info("   Tickets: {$stats['total_tickets']} | Erfolg: {$stats['successful_tickets']} | Fehler: {$stats['failed_tickets']}");
            $this->info("   Erfolgsrate: " . number_format($stats['success_rate'] * 100, 1) . '%');
            $this->info("   Repositories: {$stats['repositories_count']} | Aktive: {$stats['active_repositories_count']}");
            $this->info('');

            $totalTickets += $stats['total_tickets'];
            $totalSuccessful += $stats['successful_tickets'];
            $totalFailed += $stats['failed_tickets'];
        }

        $overallSuccessRate = $totalTickets > 0 ? ($totalSuccessful / $totalTickets) * 100 : 0;

        $this->info('📈 Gesamt-Statistiken:');
        $this->info("   Projekte: {$projects->count()}");
        $this->info("   Gesamt Tickets: {$totalTickets}");
        $this->info("   Erfolgreich: {$totalSuccessful}");
        $this->info("   Fehlgeschlagen: {$totalFailed}");
        $this->info("   Gesamt-Erfolgsrate: " . number_format($overallSuccessRate, 1) . '%');

        return Command::SUCCESS;
    }

    private function clearCache(): int
    {
        $this->info('🗑️ Lösche Projekt-Caches...');

        $this->projectService->clearAllCaches();

        $this->info('✅ Alle Projekt-Caches gelöscht!');
        return Command::SUCCESS;
    }

    private function showHelp(): int
    {
        $this->error('❌ Unbekannte Aktion. Verfügbare Aktionen:');
        $this->info('');
        $this->info('📋 Projekt-Management:');
        $this->info('  list                    - Alle Projekte auflisten');
        $this->info('  create <jira-key>       - Neues Projekt erstellen');
        $this->info('  show <jira-key>         - Projekt-Details anzeigen');
        $this->info('  update <jira-key>       - Projekt aktualisieren');
        $this->info('  import <jira-key>       - Projekt aus Jira importieren');
        $this->info('  stats                   - Projekt-Statistiken anzeigen');
        $this->info('');
        $this->info('🔗 Repository-Verknüpfung:');
        $this->info('  attach-repo <jira-key>  - Repository mit Projekt verknüpfen');
        $this->info('  set-default-repo        - Standard-Repository setzen');
        $this->info('');
        $this->info('🛠️ Wartung:');
        $this->info('  clear-cache             - Alle Caches löschen');
        $this->info('');
        $this->info('Beispiele:');
        $this->info('  php artisan octomind:project create PROJ --name="My Project" --jira-base-url="https://company.atlassian.net"');
        $this->info('  php artisan octomind:project attach-repo PROJ --repository=owner/repo --default');
        $this->info('  php artisan octomind:project show PROJ');

        return Command::FAILURE;
    }
} 