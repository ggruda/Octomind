<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\BotSession;
use App\Services\ProviderManager;
use App\Services\LogService;
use Carbon\Carbon;
use Exception;

class LoadTickets extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'octomind:load-tickets 
                          {--project-key= : Nur für spezifisches Projekt laden}
                          {--provider= : Nur für spezifischen Ticket-Provider laden}
                          {--force : Auch bei inaktiven Projekten laden}
                          {--dry-run : Nur anzeigen, was geladen würde}
                          {--limit= : Maximale Anzahl Tickets pro Projekt}';

    /**
     * The console command description.
     */
    protected $description = 'Lädt Tickets von allen konfigurierten Ticket-Systemen (für Cronjob)';

    private ProviderManager $providerManager;
    private LogService $logger;

    public function __construct()
    {
        parent::__construct();
        $this->providerManager = new ProviderManager();
        $this->logger = new LogService();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = now();
        $this->info('🎫 Starte Ticket-Loading von allen Ticket-Systemen...');

        try {
            // 1. Prüfe ob aktive Sessions mit verbleibenden Stunden existieren
            $activeSessions = BotSession::active()->where('remaining_hours', '>', 0)->count();
            
            if ($activeSessions === 0 && !$this->option('force')) {
                $this->warn('⚠️ Keine aktiven Bot-Sessions mit verbleibenden Stunden gefunden.');
                $this->info('💡 Verwende --force um trotzdem zu laden.');
                return 0;
            }

            // 2. Projekte laden
            $projects = $this->getProjectsToProcess();
            
            if ($projects->isEmpty()) {
                $this->warn('⚠️ Keine Projekte zum Verarbeiten gefunden.');
                return 0;
            }

            $this->info("📁 Verarbeite {$projects->count()} Projekt(e) mit verschiedenen Ticket-Systemen:");

            // 3. Verfügbare Ticket-Provider ermitteln
            $availableProviders = $this->getAvailableTicketProviders();
            
            if (empty($availableProviders)) {
                $this->error('❌ Keine konfigurierten Ticket-Provider gefunden.');
                return 1;
            }

            $this->info("🔌 Verfügbare Ticket-Provider: " . implode(', ', array_keys($availableProviders)));

            // 4. Tickets für jedes Projekt und jeden Provider laden
            $totalNewTickets = 0;
            $totalProcessedProjects = 0;
            $errors = [];

            foreach ($projects as $project) {
                try {
                    $result = $this->loadTicketsForProject($project, $availableProviders);
                    $totalNewTickets += $result['new_tickets'];
                    $totalProcessedProjects++;

                    $providerName = $result['provider_used'] ?? 'Unbekannt';
                    $this->info("  ✅ {$project->jira_key} ({$providerName}): {$result['new_tickets']} neue Tickets ({$result['total_tickets']} gesamt)");

                } catch (Exception $e) {
                    $errors[] = [
                        'project' => $project->jira_key,
                        'error' => $e->getMessage()
                    ];

                    $this->error("  ❌ {$project->jira_key}: {$e->getMessage()}");
                }
            }

            // 5. Zusammenfassung
            $duration = now()->diffInSeconds($startTime);
            
            $this->info('');
            $this->info('📊 Zusammenfassung:');
            $this->table(
                ['Metrik', 'Wert'],
                [
                    ['Verarbeitete Projekte', $totalProcessedProjects],
                    ['Neue Tickets', $totalNewTickets],
                    ['Fehler', count($errors)],
                    ['Dauer', $duration . ' Sekunden'],
                    ['Aktive Sessions (mit Stunden)', $activeSessions],
                    ['Ticket-Provider', count($availableProviders)]
                ]
            );

            // 6. Fehler-Details
            if (!empty($errors)) {
                $this->warn('⚠️ Fehler-Details:');
                foreach ($errors as $error) {
                    $this->line("  • {$error['project']}: {$error['error']}");
                }
            }

            // 7. Logging
            $this->logger->info('Ticket-Loading abgeschlossen', [
                'processed_projects' => $totalProcessedProjects,
                'new_tickets' => $totalNewTickets,
                'errors' => count($errors),
                'duration_seconds' => $duration,
                'active_sessions_with_hours' => $activeSessions,
                'providers_used' => array_keys($availableProviders)
            ]);

            return count($errors) > 0 ? 1 : 0;

        } catch (Exception $e) {
            $this->error('❌ Kritischer Fehler beim Ticket-Loading: ' . $e->getMessage());
            
            $this->logger->error('Kritischer Fehler beim Ticket-Loading', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return 1;
        }
    }

    /**
     * Holt Projekte zum Verarbeiten
     */
    private function getProjectsToProcess()
    {
        $query = Project::query();

        // Spezifisches Projekt?
        if ($projectKey = $this->option('project-key')) {
            $query->where('jira_key', $projectKey);
        } else {
            // Nur aktive Projekte (außer bei --force)
            if (!$this->option('force')) {
                $query->where('bot_enabled', true);
            }
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Holt verfügbare und konfigurierte Ticket-Provider
     */
    private function getAvailableTicketProviders(): array
    {
        $allProviders = $this->providerManager->getAvailableTicketProviders();
        
        // Filtere nur konfigurierte Provider
        $configuredProviders = array_filter(
            $allProviders, 
            fn($provider) => $provider['configured']
        );

        // Spezifischer Provider gewünscht?
        if ($providerName = $this->option('provider')) {
            if (isset($configuredProviders[$providerName])) {
                return [$providerName => $configuredProviders[$providerName]];
            } else {
                throw new Exception("Provider '{$providerName}' ist nicht konfiguriert oder nicht verfügbar.");
            }
        }

        return $configuredProviders;
    }

    /**
     * Lädt Tickets für ein Projekt mit dem passenden Provider
     */
    private function loadTicketsForProject(Project $project, array $availableProviders): array
    {
        $this->logger->debug('Lade Tickets für Projekt', [
            'project_key' => $project->jira_key,
            'project_name' => $project->name
        ]);

        // 1. Passenden Provider für Projekt ermitteln
        $providerName = $this->determineProviderForProject($project, $availableProviders);
        
        if (!$providerName) {
            throw new Exception("Kein passender Ticket-Provider für Projekt {$project->jira_key} gefunden.");
        }

        $this->logger->debug("Verwende Provider '{$providerName}' für Projekt {$project->jira_key}");

        // 2. Provider-Instanz holen
        $ticketProvider = $this->providerManager->getTicketProvider($providerName);

        // 3. Provider für Projekt konfigurieren
        $this->configureProviderForProject($ticketProvider, $project);

        // 4. Tickets vom Provider laden
        $providerTickets = $ticketProvider->fetchTickets();
        $newTicketsCount = 0;

        // 5. Dry-Run?
        if ($this->option('dry-run')) {
            $this->info("  🔍 DRY-RUN: Würde " . count($providerTickets) . " Tickets für {$project->jira_key} verarbeiten");
            return [
                'new_tickets' => 0,
                'total_tickets' => count($providerTickets),
                'provider_used' => $providerName
            ];
        }

        // 6. Tickets in DB speichern/aktualisieren
        foreach ($providerTickets as $ticketData) {
            $existingTicket = Ticket::where('jira_key', $ticketData->key)->first();

            if (!$existingTicket) {
                // Neues Ticket erstellen
                Ticket::create([
                    'jira_key' => $ticketData->key,
                    'summary' => $ticketData->summary,
                    'description' => $ticketData->description ?? '',
                    'jira_status' => $ticketData->status,
                    'priority' => $ticketData->priority ?? 'Medium',
                    'assignee' => $ticketData->assignee ?? null,
                    'reporter' => $ticketData->reporter ?? null,
                    'labels' => $ticketData->labels ?? [],
                    'jira_created_at' => Carbon::parse($ticketData->created),
                    'jira_updated_at' => Carbon::parse($ticketData->updated),
                    'project_id' => $project->id,
                    'status' => 'pending'
                ]);

                $newTicketsCount++;

            } else {
                // Existierendes Ticket aktualisieren (falls Änderungen)
                $updated = $existingTicket->update([
                    'summary' => $ticketData->summary,
                    'description' => $ticketData->description ?? '',
                    'jira_status' => $ticketData->status,
                    'priority' => $ticketData->priority ?? 'Medium',
                    'assignee' => $ticketData->assignee ?? null,
                    'labels' => $ticketData->labels ?? [],
                    'jira_updated_at' => Carbon::parse($ticketData->updated)
                ]);

                if ($updated) {
                    $this->logger->debug('Ticket aktualisiert', [
                        'ticket_key' => $ticketData->key
                    ]);
                }
            }
        }

        // 7. Projekt-Statistiken aktualisieren
        $project->update([
            'last_sync_at' => now(),
            'total_tickets_synced' => ($project->total_tickets_synced ?? 0) + $newTicketsCount
        ]);

        return [
            'new_tickets' => $newTicketsCount,
            'total_tickets' => count($providerTickets),
            'provider_used' => $providerName
        ];
    }

    /**
     * Ermittelt den passenden Provider für ein Projekt
     */
    private function determineProviderForProject(Project $project, array $availableProviders): ?string
    {
        // 1. Explizit konfigurierter Provider im Projekt
        if (isset($project->ticket_provider) && isset($availableProviders[$project->ticket_provider])) {
            return $project->ticket_provider;
        }

        // 2. Provider basierend auf Projekt-URL ermitteln
        if ($project->jira_base_url) {
            if (str_contains($project->jira_base_url, 'atlassian.net') && isset($availableProviders['jira'])) {
                return 'jira';
            }
            if (str_contains($project->jira_base_url, 'linear.app') && isset($availableProviders['linear'])) {
                return 'linear';
            }
        }

        // 3. Standard-Provider aus Konfiguration
        $defaultProvider = config('octomind.ticket.default_provider', 'jira');
        if (isset($availableProviders[$defaultProvider])) {
            return $defaultProvider;
        }

        // 4. Ersten verfügbaren Provider nehmen
        return array_key_first($availableProviders);
    }

    /**
     * Konfiguriert Provider für spezifisches Projekt
     */
    private function configureProviderForProject($ticketProvider, Project $project): void
    {
        // Provider-spezifische Konfiguration setzen
        if (method_exists($ticketProvider, 'setProjectConfig')) {
            $config = [
                'base_url' => $project->jira_base_url,
                'project_key' => $project->jira_key,
                'required_label' => $project->required_label ?? 'ai-bot',
                'allowed_statuses' => $project->allowed_statuses ?? ['Open', 'In Progress', 'To Do'],
                'require_unassigned' => $project->require_unassigned ?? true,
                'max_results' => $this->option('limit') ? (int) $this->option('limit') : 50
            ];

            // Projekt-spezifische JQL-Filter oder Query-Parameter
            if (isset($project->custom_fields_mapping['jql_filter'])) {
                $config['jql_filter'] = $project->custom_fields_mapping['jql_filter'];
            }

            $ticketProvider->setProjectConfig($config);
        }
    }
} 