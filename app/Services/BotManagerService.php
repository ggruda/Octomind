<?php

namespace App\Services;

use App\Models\BotSession;
use App\Models\Project;
use App\Models\Ticket;
use App\Services\TicketProcessingService;
use App\Services\JiraService;
use App\Services\LogService;
use App\Services\ConfigService;
use App\Services\EmailNotificationService;
use Carbon\Carbon;
use Exception;

class BotManagerService
{
    private LogService $logger;
    private ConfigService $config;
    private TicketProcessingService $ticketProcessor;
    private JiraService $jiraService;
    private EmailNotificationService $emailService;
    
    private bool $isRunning = false;
    private ?BotSession $currentSession = null;

    public function __construct()
    {
        $this->logger = new LogService();
        $this->config = ConfigService::getInstance();
        $this->ticketProcessor = new TicketProcessingService();
        $this->jiraService = new JiraService();
        $this->emailService = new EmailNotificationService();
    }

    /**
     * Startet Bot-Manager mit automatischem Ticket-Loading
     */
    public function start(?string $sessionId = null): array
    {
        $this->logger->info('Starte Bot-Manager', [
            'session_id' => $sessionId,
            'timestamp' => now()->toISOString()
        ]);

        try {
            // 1. Session laden oder erstellen
            $this->currentSession = $this->resolveSession($sessionId);
            
            if (!$this->currentSession) {
                throw new Exception('Keine aktive Bot-Session verfügbar');
            }

            if (!$this->currentSession->canBeActive()) {
                throw new Exception('Bot-Session ist abgelaufen oder deaktiviert');
            }

            // 2. Bot als aktiv markieren
            $this->isRunning = true;
            $this->currentSession->resume();

            $this->logger->info('Bot-Manager erfolgreich gestartet', [
                'session_id' => $this->currentSession->session_id,
                'remaining_hours' => $this->currentSession->remaining_hours,
                'customer_email' => $this->currentSession->customer_email
            ]);

            // 3. Hauptschleife starten (wird in separatem Process laufen)
            return [
                'success' => true,
                'session_id' => $this->currentSession->session_id,
                'remaining_hours' => $this->currentSession->remaining_hours,
                'status' => 'started'
            ];

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Starten des Bot-Managers', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Stoppt Bot-Manager
     */
    public function stop(): array
    {
        $this->logger->info('Stoppe Bot-Manager', [
            'session_id' => $this->currentSession?->session_id
        ]);

        $this->isRunning = false;

        if ($this->currentSession) {
            $this->currentSession->pause();
        }

        return [
            'success' => true,
            'status' => 'stopped',
            'session_report' => $this->currentSession?->generateReport()
        ];
    }

    /**
     * Hauptschleife für automatisches Ticket-Loading und -Processing
     */
    public function runMainLoop(): void
    {
        $this->logger->info('Starte Bot-Hauptschleife', [
            'session_id' => $this->currentSession->session_id
        ]);

        $lastTicketLoad = null;
        $ticketLoadInterval = $this->config->get('bot.ticket_load_interval_minutes', 2);

        while ($this->isRunning && $this->currentSession->canBeActive()) {
            try {
                $now = Carbon::now();

                // 1. Alle 2 Minuten Tickets laden
                if (!$lastTicketLoad || $now->diffInMinutes($lastTicketLoad) >= $ticketLoadInterval) {
                    $this->loadTicketsFromAllProjects();
                    $lastTicketLoad = $now;
                }

                // 2. Verfügbare Tickets verarbeiten
                $this->processAvailableTickets();

                // 3. Warnungen und Status prüfen
                $this->checkWarningsAndStatus();

                // 4. Session aktualisieren
                $this->currentSession->refresh();

                // 5. Kurze Pause bevor nächste Iteration
                sleep(30); // 30 Sekunden zwischen Checks

            } catch (Exception $e) {
                $this->logger->error('Fehler in Bot-Hauptschleife', [
                    'session_id' => $this->currentSession->session_id,
                    'error' => $e->getMessage()
                ]);

                // Bei kritischem Fehler: 1 Minute warten
                sleep(60);
            }
        }

        // Session beendet
        if (!$this->currentSession->canBeActive()) {
            $this->handleSessionExpiry();
        }

        $this->logger->info('Bot-Hauptschleife beendet', [
            'session_id' => $this->currentSession->session_id,
            'final_status' => $this->currentSession->status
        ]);
    }

    /**
     * Lädt Tickets von allen aktiven Projekten
     */
    private function loadTicketsFromAllProjects(): void
    {
        $this->logger->info('Lade Tickets von allen Projekten');

        $activeProjects = Project::where('bot_enabled', true)->get();
        $totalTicketsLoaded = 0;

        foreach ($activeProjects as $project) {
            try {
                $this->logger->debug('Lade Tickets für Projekt', [
                    'project_key' => $project->jira_key,
                    'project_name' => $project->name
                ]);

                // JiraService konfigurieren für dieses Projekt
                $this->jiraService->setProjectConfig([
                    'base_url' => $project->jira_base_url,
                    'project_key' => $project->jira_key,
                    'jql_filter' => $project->jql_filter
                ]);

                // Tickets laden
                $tickets = $this->jiraService->fetchTickets();
                $newTickets = 0;

                foreach ($tickets as $ticketData) {
                    $existingTicket = Ticket::where('jira_key', $ticketData['key'])->first();
                    
                    if (!$existingTicket) {
                        // Neues Ticket erstellen
                        Ticket::create([
                            'jira_key' => $ticketData['key'],
                            'summary' => $ticketData['summary'],
                            'description' => $ticketData['description'] ?? '',
                            'jira_status' => $ticketData['status'],
                            'priority' => $ticketData['priority'] ?? 'Medium',
                            'assignee' => $ticketData['assignee'] ?? null,
                            'reporter' => $ticketData['reporter'] ?? null,
                            'labels' => $ticketData['labels'] ?? [],
                            'jira_created_at' => Carbon::parse($ticketData['created']),
                            'jira_updated_at' => Carbon::parse($ticketData['updated']),
                            'project_id' => $project->id,
                            'status' => 'pending'
                        ]);
                        
                        $newTickets++;
                    }
                }

                $totalTicketsLoaded += $newTickets;

                $this->logger->debug('Tickets für Projekt geladen', [
                    'project_key' => $project->jira_key,
                    'new_tickets' => $newTickets,
                    'total_tickets' => count($tickets)
                ]);

            } catch (Exception $e) {
                $this->logger->error('Fehler beim Laden von Tickets für Projekt', [
                    'project_key' => $project->jira_key,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->logger->info('Ticket-Loading abgeschlossen', [
            'total_new_tickets' => $totalTicketsLoaded,
            'projects_checked' => $activeProjects->count()
        ]);
    }

    /**
     * Verarbeitet verfügbare Tickets
     */
    private function processAvailableTickets(): void
    {
        // Hole nächstes verfügbares Ticket
        $ticket = Ticket::where('status', 'pending')
                        ->whereHas('project', function($query) {
                            $query->where('bot_enabled', true);
                        })
                        ->whereHas('repository', function($query) {
                            $query->where('bot_enabled', true);
                        })
                        ->orderBy('created_at', 'asc')
                        ->first();

        if (!$ticket) {
            $this->logger->debug('Keine verfügbaren Tickets zum Verarbeiten');
            return;
        }

        $this->logger->info('Verarbeite Ticket', [
            'ticket_key' => $ticket->jira_key,
            'session_id' => $this->currentSession->session_id,
            'remaining_hours' => $this->currentSession->remaining_hours
        ]);

        $startTime = Carbon::now();

        try {
            // Ticket mit Session verknüpfen
            $ticket->update(['bot_session_id' => $this->currentSession->id]);

            // Ticket verarbeiten
            $result = $this->ticketProcessor->processTicket($ticket->jira_key);

            $processingTime = Carbon::now()->diffInSeconds($startTime);
            $hoursConsumed = $processingTime / 3600; // Sekunden in Stunden umrechnen

            // Stunden in Session verbuchen
            $this->currentSession->consumeHours($hoursConsumed, $result['success']);

            // Ticket-Stunden-Verbrauch speichern
            $ticket->update([
                'hours_consumed' => $hoursConsumed,
                'billing_status' => 'calculated'
            ]);

            $this->logger->info('Ticket erfolgreich verarbeitet', [
                'ticket_key' => $ticket->jira_key,
                'success' => $result['success'],
                'hours_consumed' => round($hoursConsumed, 4),
                'remaining_hours' => $this->currentSession->remaining_hours
            ]);

        } catch (Exception $e) {
            $processingTime = Carbon::now()->diffInSeconds($startTime);
            $hoursConsumed = $processingTime / 3600;

            // Auch bei Fehlern Stunden verbuchen
            $this->currentSession->consumeHours($hoursConsumed, false);

            $ticket->update([
                'hours_consumed' => $hoursConsumed,
                'billing_status' => 'calculated',
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            $this->logger->error('Ticket-Verarbeitung fehlgeschlagen', [
                'ticket_key' => $ticket->jira_key,
                'error' => $e->getMessage(),
                'hours_consumed' => round($hoursConsumed, 4)
            ]);
        }
    }

    /**
     * Prüft Warnungen und Session-Status
     */
    private function checkWarningsAndStatus(): void
    {
        // 75% Warnung
        if ($this->currentSession->shouldSend75Warning()) {
            $this->sendWarningEmail(75);
            $this->currentSession->update(['warning_75_sent' => true]);
        }

        // 90% Warnung
        if ($this->currentSession->shouldSend90Warning()) {
            $this->sendWarningEmail(90);
            $this->currentSession->update(['warning_90_sent' => true]);
        }

        // Session abgelaufen?
        if ($this->currentSession->isExpired()) {
            $this->handleSessionExpiry();
            $this->isRunning = false;
        }
    }

    /**
     * Behandelt Session-Ablauf
     */
    private function handleSessionExpiry(): void
    {
        $this->logger->info('Bot-Session abgelaufen', [
            'session_id' => $this->currentSession->session_id,
            'customer_email' => $this->currentSession->customer_email
        ]);

        // Session als abgelaufen markieren
        $this->currentSession->markExpired();

        // Expiry-Email senden
        if (!$this->currentSession->expiry_notification_sent) {
            $this->sendExpiryEmail();
            $this->currentSession->update(['expiry_notification_sent' => true]);
        }

        $this->isRunning = false;
    }

    /**
     * Sendet Warn-Email
     */
    private function sendWarningEmail(int $percentage): void
    {
        $this->logger->info("Sende {$percentage}% Warnung", [
            'session_id' => $this->currentSession->session_id,
            'customer_email' => $this->currentSession->customer_email
        ]);

        $report = $this->currentSession->generateReport();

        $this->emailService->sendWarningEmail(
            $this->currentSession->customer_email,
            $percentage,
            $report
        );
    }

    /**
     * Sendet Expiry-Email
     */
    private function sendExpiryEmail(): void
    {
        $this->logger->info('Sende Expiry-Email', [
            'session_id' => $this->currentSession->session_id,
            'customer_email' => $this->currentSession->customer_email
        ]);

        $report = $this->currentSession->generateReport();

        // Email an Kunden
        $this->emailService->sendExpiryEmail(
            $this->currentSession->customer_email,
            $report
        );

        // Email an interne Adresse
        $this->emailService->sendInternalExpiryNotification(
            'hours-expired@octomind.com',
            $report
        );
    }

    /**
     * Löst Session auf
     */
    private function resolveSession(?string $sessionId): ?BotSession
    {
        if ($sessionId) {
            return BotSession::where('session_id', $sessionId)->first();
        }

        // Aktive Session finden
        return BotSession::active()->orderBy('last_activity_at', 'desc')->first();
    }

    /**
     * Erstellt neue Bot-Session
     */
    public function createSession(
        string $customerEmail, 
        float $purchasedHours, 
        ?string $customerName = null
    ): BotSession {
        $session = BotSession::createSession($customerEmail, $purchasedHours, $customerName);

        $this->logger->info('Neue Bot-Session erstellt', [
            'session_id' => $session->session_id,
            'customer_email' => $customerEmail,
            'purchased_hours' => $purchasedHours
        ]);

        return $session;
    }

    /**
     * Holt aktuelle Session-Informationen
     */
    public function getSessionStatus(?string $sessionId = null): ?array
    {
        $session = $sessionId 
            ? BotSession::where('session_id', $sessionId)->first()
            : $this->currentSession;

        return $session?->generateReport();
    }

    /**
     * Holt alle Sessions
     */
    public function getAllSessions(int $limit = 50): array
    {
        return BotSession::orderBy('created_at', 'desc')
                         ->limit($limit)
                         ->get()
                         ->map(fn($session) => $session->generateReport())
                         ->toArray();
    }

    /**
     * Prüft ob Bot läuft
     */
    public function isRunning(): bool
    {
        return $this->isRunning && $this->currentSession?->canBeActive();
    }
} 