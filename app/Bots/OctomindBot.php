<?php

namespace App\Bots;

use App\Services\ConfigService;
use App\Services\LogService;
use App\Services\JiraService;
use App\Services\PromptBuilderService;
use App\Services\CloudAIService;
use App\Services\GitHubService;
use App\Services\BotStatusService;
use App\Enums\BotStatus;
use App\Enums\TicketStatus;
use App\DTOs\TicketDTO;
use Exception;
use Carbon\Carbon;

class OctomindBot
{
    private ConfigService $config;
    private LogService $logger;
    private JiraService $jira;
    private PromptBuilderService $promptBuilder;
    private CloudAIService $cloudAI;
    private GitHubService $github;
    private BotStatusService $status;
    
    private BotStatus $currentStatus = BotStatus::IDLE;
    private array $processingQueue = [];
    private int $processedTickets = 0;
    private Carbon $startTime;

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
        $this->jira = new JiraService();
        $this->promptBuilder = new PromptBuilderService();
        $this->cloudAI = new CloudAIService();
        $this->github = new GitHubService();
        $this->status = new BotStatusService();
        $this->startTime = Carbon::now();
    }

    public function start(): void
    {
        $this->logger->botActivity('Bot wird gestartet', [
            'config_validation' => $this->validateConfiguration(),
            'start_time' => $this->startTime->toISOString(),
        ]);

        if (!$this->config->isBotEnabled()) {
            $this->logger->warning('Bot ist deaktiviert - BOT_ENABLED=false');
            $this->currentStatus = BotStatus::DISABLED;
            return;
        }

        $configErrors = $this->config->validateConfiguration();
        if (!empty($configErrors)) {
            $this->logger->error('Bot-Konfiguration ist fehlerhaft', ['errors' => $configErrors]);
            $this->currentStatus = BotStatus::ERROR;
            return;
        }

        $this->currentStatus = BotStatus::IDLE;
        $this->logger->info('Octomind Bot erfolgreich gestartet');
        
        // Starte den Hauptverarbeitungsloop
        $this->run();
    }

    private function run(): void
    {
        $fetchInterval = $this->config->get('jira.fetch_interval', 300);
        
        while ($this->currentStatus->isActive()) {
            try {
                $this->processingCycle();
                
                if ($this->config->get('bot.health_check_enabled', true)) {
                    $this->performHealthCheck();
                }
                
                // Warte für das nächste Intervall
                $this->logger->debug("Warte {$fetchInterval} Sekunden bis zum nächsten Zyklus");
                sleep($fetchInterval);
                
            } catch (Exception $e) {
                $this->logger->error('Fehler im Hauptverarbeitungsloop', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                $this->currentStatus = BotStatus::ERROR;
                sleep(60); // Warte 1 Minute bei Fehlern
            }
        }
    }

    private function processingCycle(): void
    {
        $this->logger->debug('Starte neuen Verarbeitungszyklus');
        
        // 1. Hole neue Tickets von Jira
        $tickets = $this->fetchNewTickets();
        
        if (empty($tickets)) {
            $this->logger->debug('Keine neuen Tickets gefunden');
            return;
        }
        
        $this->logger->info("Gefunden: " . count($tickets) . " neue Tickets");
        
        // 2. Verarbeite jeden Ticket
        foreach ($tickets as $ticket) {
            if (!$this->currentStatus->isActive()) {
                break;
            }
            
            $this->processTicket($ticket);
        }
    }

    private function fetchNewTickets(): array
    {
        try {
            $this->currentStatus = BotStatus::PROCESSING;
            $tickets = $this->jira->fetchTickets();
            
            // Filtere Tickets basierend auf Konfiguration
            $filteredTickets = array_filter($tickets, function (TicketDTO $ticket) {
                return $this->shouldProcessTicket($ticket);
            });
            
            return array_values($filteredTickets);
            
        } catch (Exception $e) {
            $this->logger->error('Fehler beim Abrufen der Tickets', [
                'error' => $e->getMessage()
            ]);
            return [];
        } finally {
            $this->currentStatus = BotStatus::IDLE;
        }
    }

    private function shouldProcessTicket(TicketDTO $ticket): bool
    {
        $requiredLabel = $this->config->get('jira.required_label');
        if ($requiredLabel && !$ticket->hasRequiredLabel($requiredLabel)) {
            $this->logger->debug("Ticket {$ticket->key} hat nicht das erforderliche Label: {$requiredLabel}");
            return false;
        }

        if ($this->config->get('jira.require_unassigned', true) && !$ticket->isUnassigned()) {
            $this->logger->debug("Ticket {$ticket->key} ist bereits zugewiesen");
            return false;
        }

        if (!$ticket->hasLinkedRepository()) {
            $this->logger->warning("Ticket {$ticket->key} hat kein verknüpftes Repository");
            return false;
        }

        return true;
    }

    private function processTicket(TicketDTO $ticket): void
    {
        $this->logger->ticketProcessing($ticket->key, 'Verarbeitung gestartet');
        
        try {
            $this->currentStatus = BotStatus::PROCESSING;
            
            // Phase 1: Ticket analysieren
            $this->updateTicketStatus($ticket->key, TicketStatus::ANALYZING);
            $analysis = $this->analyzeTicket($ticket);
            
            // Phase 2: Lösung generieren
            $this->updateTicketStatus($ticket->key, TicketStatus::GENERATING_SOLUTION);
            $solution = $this->generateSolution($ticket, $analysis);
            
            // Phase 3: Code ausführen (mit Retry-Logik)
            $this->updateTicketStatus($ticket->key, TicketStatus::EXECUTING);
            $executionResult = $this->executeWithRetry($ticket, $solution);
            
            if (!$executionResult['success']) {
                $this->updateTicketStatus($ticket->key, TicketStatus::FAILED);
                return;
            }
            
            // Phase 4: Pull Request erstellen
            $this->updateTicketStatus($ticket->key, TicketStatus::CREATING_PR);
            $prResult = $this->createPullRequest($ticket, $executionResult);
            
            if ($prResult['success']) {
                $this->updateTicketStatus($ticket->key, TicketStatus::COMPLETED);
                $this->processedTickets++;
                $this->logger->ticketProcessing($ticket->key, 'Erfolgreich abgeschlossen', [
                    'pr_url' => $prResult['pr_url']
                ]);
            } else {
                $this->updateTicketStatus($ticket->key, TicketStatus::REQUIRES_REVIEW);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Fehler bei der Verarbeitung von Ticket {$ticket->key}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->updateTicketStatus($ticket->key, TicketStatus::FAILED);
        } finally {
            $this->currentStatus = BotStatus::IDLE;
        }
    }

    private function analyzeTicket(TicketDTO $ticket): array
    {
        $this->logger->debug("Analysiere Ticket: {$ticket->key}");
        
        // Hier würde die detaillierte Ticket-Analyse stattfinden
        return [
            'ticket' => $ticket,
            'complexity' => 'medium',
            'estimated_time' => 30,
            'required_skills' => ['php', 'laravel']
        ];
    }

    private function generateSolution(TicketDTO $ticket, array $analysis): array
    {
        $this->logger->debug("Generiere Lösung für Ticket: {$ticket->key}");
        
        $prompt = $this->promptBuilder->buildPrompt($ticket, $analysis);
        $solution = $this->cloudAI->generateSolution($prompt);
        
        return $solution;
    }

    private function executeWithRetry(TicketDTO $ticket, array $solution): array
    {
        $maxAttempts = $this->config->get('retry.max_attempts', 3);
        $attempt = 1;
        
        while ($attempt <= $maxAttempts) {
            $this->logger->retryAttempt('code_execution', $attempt, $maxAttempts, [
                'ticket' => $ticket->key
            ]);
            
            try {
                if ($this->config->isSimulationMode()) {
                    $this->logger->info("SIMULATION MODE: Würde Code für Ticket {$ticket->key} ausführen");
                    return ['success' => true, 'simulation' => true];
                }
                
                $result = $this->cloudAI->executeCode($ticket, $solution);
                
                if ($result['success']) {
                    return $result;
                }
                
            } catch (Exception $e) {
                $this->logger->warning("Ausführungsversuch {$attempt} fehlgeschlagen", [
                    'ticket' => $ticket->key,
                    'error' => $e->getMessage()
                ]);
            }
            
            if ($attempt < $maxAttempts) {
                $delay = $this->calculateBackoffDelay($attempt);
                $this->logger->debug("Warte {$delay} Sekunden vor nächstem Versuch");
                sleep($delay);
            }
            
            $attempt++;
        }
        
        return ['success' => false, 'error' => 'Maximale Anzahl von Versuchen erreicht'];
    }

    private function createPullRequest(TicketDTO $ticket, array $executionResult): array
    {
        $this->logger->debug("Erstelle Pull Request für Ticket: {$ticket->key}");
        
        if ($this->config->isSimulationMode()) {
            $this->logger->info("SIMULATION MODE: Würde PR für Ticket {$ticket->key} erstellen");
            return ['success' => true, 'simulation' => true, 'pr_url' => 'https://github.com/simulation/pr'];
        }
        
        return $this->github->createPullRequest($ticket, $executionResult);
    }

    private function calculateBackoffDelay(int $attempt): int
    {
        $initialDelay = $this->config->get('retry.initial_delay', 5);
        $multiplier = $this->config->get('retry.backoff_multiplier', 2);
        $maxDelay = $this->config->get('retry.max_delay', 300);
        
        $delay = $initialDelay * pow($multiplier, $attempt - 1);
        
        return min($delay, $maxDelay);
    }

    private function updateTicketStatus(string $ticketKey, TicketStatus $status): void
    {
        $this->logger->ticketProcessing($ticketKey, $status->getDescription());
        
        // Hier würde der Status in der Datenbank aktualisiert werden
        // DB::table('tickets')->where('key', $ticketKey)->update(['status' => $status->value]);
    }

    private function performHealthCheck(): void
    {
        $this->status->performHealthCheck();
        
        $metrics = [
            'uptime' => Carbon::now()->diffInSeconds($this->startTime),
            'processed_tickets' => $this->processedTickets,
            'current_status' => $this->currentStatus->value,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];
        
        $this->logger->performance('health_check', 0, $metrics);
    }

    private function validateConfiguration(): array
    {
        return $this->config->validateConfiguration();
    }

    public function stop(): void
    {
        $this->logger->botActivity('Bot wird gestoppt');
        $this->currentStatus = BotStatus::DISABLED;
    }

    public function getStatus(): BotStatus
    {
        return $this->currentStatus;
    }

    public function getMetrics(): array
    {
        return [
            'status' => $this->currentStatus->value,
            'uptime' => Carbon::now()->diffInSeconds($this->startTime),
            'processed_tickets' => $this->processedTickets,
            'queue_size' => count($this->processingQueue),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];
    }
} 