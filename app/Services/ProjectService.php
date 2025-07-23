<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Repository;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Exception;

class ProjectService
{
    private LogService $logger;

    public function __construct()
    {
        $this->logger = new LogService();
    }

    /**
     * Holt alle aktiven Projekte (gecacht)
     */
    public function getActiveProjects(): Collection
    {
        return Project::getActiveProjects();
    }

    /**
     * Findet ein Projekt anhand des Jira-Keys (gecacht)
     */
    public function findByJiraKey(string $jiraKey): ?Project
    {
        return Project::findByJiraKey($jiraKey);
    }

    /**
     * Erstellt ein neues Projekt
     */
    public function createProject(array $data): Project
    {
        $this->logger->info('Erstelle neues Projekt', [
            'jira_key' => $data['jira_key'],
            'name' => $data['name']
        ]);

        $project = Project::create([
            'jira_key' => $data['jira_key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'jira_base_url' => $data['jira_base_url'],
            'project_type' => $data['project_type'] ?? 'software',
            'project_category' => $data['project_category'] ?? null,
            'bot_enabled' => $data['bot_enabled'] ?? true,
            'required_label' => $data['required_label'] ?? 'ai-bot',
            'require_unassigned' => $data['require_unassigned'] ?? true,
            'allowed_statuses' => $data['allowed_statuses'] ?? ['Open', 'In Progress', 'To Do'],
            'fetch_interval' => $data['fetch_interval'] ?? 300,
            'custom_fields_mapping' => $data['custom_fields_mapping'] ?? null,
            'webhook_config' => $data['webhook_config'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'notes' => $data['notes'] ?? null
        ]);

        $this->logger->info('Projekt erfolgreich erstellt', [
            'project_id' => $project->id,
            'jira_key' => $project->jira_key
        ]);

        return $project;
    }

    /**
     * Aktualisiert ein Projekt
     */
    public function updateProject(Project $project, array $data): Project
    {
        $this->logger->info('Aktualisiere Projekt', [
            'project_id' => $project->id,
            'jira_key' => $project->jira_key
        ]);

        $project->update($data);

        $this->logger->info('Projekt erfolgreich aktualisiert', [
            'project_id' => $project->id
        ]);

        return $project->fresh();
    }

    /**
     * Verknüpft ein Repository mit einem Projekt
     */
    public function attachRepository(
        Project $project, 
        Repository $repository, 
        array $pivotData = []
    ): void {
        $this->logger->info('Verknüpfe Repository mit Projekt', [
            'project_id' => $project->id,
            'repository_id' => $repository->id,
            'project_key' => $project->jira_key,
            'repository_name' => $repository->full_name
        ]);

        $defaultPivotData = [
            'is_default' => false,
            'priority' => 1,
            'branch_strategy' => 'feature',
            'is_active' => true
        ];

        $project->repositories()->attach($repository->id, array_merge($defaultPivotData, $pivotData));

        // Wenn dies das erste Repository ist, als Standard setzen
        if ($project->repositories()->count() === 1) {
            $this->setDefaultRepository($project, $repository);
        }

        $this->logger->info('Repository erfolgreich verknüpft', [
            'project_id' => $project->id,
            'repository_id' => $repository->id
        ]);
    }

    /**
     * Setzt ein Repository als Standard für ein Projekt
     */
    public function setDefaultRepository(Project $project, Repository $repository): void
    {
        $this->logger->info('Setze Standard-Repository', [
            'project_id' => $project->id,
            'repository_id' => $repository->id
        ]);

        // Alle anderen als nicht-standard markieren
        $project->repositories()->updateExistingPivot($project->repositories()->pluck('id'), [
            'is_default' => false
        ]);

        // Das gewählte Repository als Standard setzen
        $project->repositories()->updateExistingPivot($repository->id, [
            'is_default' => true,
            'priority' => 0 // Höchste Priorität
        ]);

        // Projekt-Referenz aktualisieren
        $project->update(['default_repository_id' => $repository->id]);

        $this->logger->info('Standard-Repository gesetzt', [
            'project_id' => $project->id,
            'repository_id' => $repository->id
        ]);
    }

    /**
     * Löst Repository für ein Ticket auf
     */
    public function resolveRepositoryForTicket(Ticket $ticket): ?Repository
    {
        if (!$ticket->project) {
            $this->logger->warning('Ticket hat kein verknüpftes Projekt', [
                'ticket_key' => $ticket->jira_key
            ]);
            return null;
        }

        $repository = $ticket->project->getRepositoryForTicket($ticket);

        if ($repository) {
            $this->logger->debug('Repository für Ticket aufgelöst', [
                'ticket_key' => $ticket->jira_key,
                'repository' => $repository->full_name,
                'project' => $ticket->project->jira_key
            ]);
        } else {
            $this->logger->warning('Kein Repository für Ticket gefunden', [
                'ticket_key' => $ticket->jira_key,
                'project' => $ticket->project->jira_key
            ]);
        }

        return $repository;
    }

    /**
     * Holt Projekt-Konfiguration (gecacht)
     */
    public function getProjectConfig(string $jiraKey): ?array
    {
        $project = $this->findByJiraKey($jiraKey);
        
        if (!$project) {
            return null;
        }

        return $project->getCachedConfig();
    }

    /**
     * Holt alle Repositories eines Projekts (gecacht)
     */
    public function getProjectRepositories(string $jiraKey): Collection
    {
        $project = $this->findByJiraKey($jiraKey);
        
        if (!$project) {
            return collect();
        }

        return $project->getCachedRepositories();
    }

    /**
     * Prüft ob ein Projekt Bot-Verarbeitung benötigt
     */
    public function needsProcessing(Project $project): bool
    {
        if (!$project->bot_enabled || !$project->is_active) {
            return false;
        }

        return $project->is_stale;
    }

    /**
     * Holt alle Projekte die Synchronisation benötigen
     */
    public function getProjectsNeedingSync(): Collection
    {
        return Cache::remember('projects:needing_sync', 300, function () {
            return Project::active()
                        ->botEnabled()
                        ->needingSync()
                        ->get();
        });
    }

    /**
     * Aktualisiert Projekt-Statistiken nach Ticket-Verarbeitung
     */
    public function updateTicketStats(Project $project, bool $success): void
    {
        $this->logger->debug('Aktualisiere Projekt-Statistiken', [
            'project_id' => $project->id,
            'success' => $success
        ]);

        $project->incrementTicketStats($success);
    }

    /**
     * Aktualisiert Sync-Zeitstempel
     */
    public function updateSyncTimestamp(Project $project): void
    {
        $project->updateSyncTimestamp();
        
        $this->logger->debug('Projekt-Sync-Zeitstempel aktualisiert', [
            'project_id' => $project->id,
            'last_sync_at' => $project->last_sync_at
        ]);
    }

    /**
     * Konfiguriert Ticket-Routing für ein Repository
     */
    public function configureTicketRouting(
        Project $project, 
        Repository $repository, 
        array $routingConfig
    ): void {
        $this->logger->info('Konfiguriere Ticket-Routing', [
            'project_id' => $project->id,
            'repository_id' => $repository->id,
            'routing_config' => $routingConfig
        ]);

        $project->repositories()->updateExistingPivot($repository->id, [
            'ticket_routing_rules' => $routingConfig['routing_rules'] ?? null,
            'component_mapping' => $routingConfig['component_mapping'] ?? null,
            'label_mapping' => $routingConfig['label_mapping'] ?? null,
            'branch_strategy' => $routingConfig['branch_strategy'] ?? 'feature',
            'custom_branch_prefix' => $routingConfig['custom_branch_prefix'] ?? null
        ]);

        $this->logger->info('Ticket-Routing konfiguriert', [
            'project_id' => $project->id,
            'repository_id' => $repository->id
        ]);
    }

    /**
     * Holt Projekt-Statistiken
     */
    public function getProjectStats(Project $project): array
    {
        return Cache::remember("project:{$project->jira_key}:stats", 1800, function () use ($project) {
            return [
                'total_tickets' => $project->total_tickets_processed,
                'successful_tickets' => $project->successful_tickets,
                'failed_tickets' => $project->failed_tickets,
                'success_rate' => $project->success_rate,
                'failure_rate' => $project->failure_rate,
                'last_sync_at' => $project->last_sync_at,
                'is_stale' => $project->is_stale,
                'repositories_count' => $project->repositories()->count(),
                'active_repositories_count' => $project->activeRepositories()->count(),
                'pending_tickets' => $project->tickets()->where('status', 'pending')->count(),
                'in_progress_tickets' => $project->tickets()->where('status', 'in_progress')->count(),
                'completed_tickets' => $project->tickets()->where('status', 'completed')->count(),
                'failed_tickets_count' => $project->tickets()->where('status', 'failed')->count()
            ];
        });
    }

    /**
     * Validiert Projekt-Konfiguration
     */
    public function validateProjectConfig(array $config): array
    {
        $errors = [];

        if (empty($config['jira_key'])) {
            $errors[] = 'Jira-Key ist erforderlich';
        }

        if (empty($config['name'])) {
            $errors[] = 'Projekt-Name ist erforderlich';
        }

        if (empty($config['jira_base_url'])) {
            $errors[] = 'Jira-Base-URL ist erforderlich';
        } elseif (!filter_var($config['jira_base_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Jira-Base-URL ist keine gültige URL';
        }

        if (isset($config['fetch_interval']) && $config['fetch_interval'] < 60) {
            $errors[] = 'Fetch-Interval muss mindestens 60 Sekunden betragen';
        }

        if (isset($config['allowed_statuses']) && !is_array($config['allowed_statuses'])) {
            $errors[] = 'Allowed-Statuses muss ein Array sein';
        }

        return $errors;
    }

    /**
     * Importiert Projekt aus Jira
     */
    public function importFromJira(string $jiraKey, string $jiraBaseUrl): Project
    {
        $this->logger->info('Importiere Projekt aus Jira', [
            'jira_key' => $jiraKey,
            'jira_base_url' => $jiraBaseUrl
        ]);

        // TODO: Jira-API-Integration für Projekt-Import
        // Für jetzt: Basis-Projekt erstellen
        
        $project = $this->createProject([
            'jira_key' => $jiraKey,
            'name' => $jiraKey, // Wird später aus Jira aktualisiert
            'jira_base_url' => $jiraBaseUrl,
            'bot_enabled' => false, // Erst nach Konfiguration aktivieren
            'is_active' => true
        ]);

        $this->logger->info('Projekt aus Jira importiert', [
            'project_id' => $project->id,
            'jira_key' => $jiraKey
        ]);

        return $project;
    }

    /**
     * Löscht alle Caches
     */
    public function clearAllCaches(): void
    {
        $this->logger->info('Lösche alle Projekt-Caches');
        Project::clearAllCache();
    }

    /**
     * Holt Übersicht aller Projekte - PRODUKTIONSREIF
     */
    public function getProjectsOverview(): array
    {
        // KEIN CACHE - direkt aus DB für Zuverlässigkeit
        $projects = Project::with(['repositories', 'tickets'])
                          ->orderBy('name')
                          ->get();

        $overview = [];
        foreach ($projects as $project) {
            $overview[] = [
                'id' => $project->id,
                'jira_key' => $project->jira_key,
                'name' => $project->name,
                'description' => $project->description,
                'jira_base_url' => $project->jira_base_url,
                'bot_enabled' => $project->bot_enabled,
                'is_active' => $project->is_active,
                'repositories_count' => $project->repositories->count(),
                'tickets_count' => $project->tickets->count(),
                'last_sync_at' => $project->last_sync_at?->toISOString(),
                'total_tickets_processed' => $project->total_tickets_processed,
                'successful_tickets' => $project->successful_tickets,
                'failed_tickets' => $project->failed_tickets,
                'success_rate' => $project->success_rate,
                'failure_rate' => $project->failure_rate,
                'jira_url' => $project->jira_url,
                'is_stale' => $project->is_stale,
            ];
        }

        return $overview;
    }
} 