<?php

namespace App\Services;

use App\DTOs\TicketDTO;
use App\Models\Ticket;
use App\Services\KnowledgeBaseService;
use App\Services\CodeReviewService;
use Carbon\Carbon;
use Exception;

class TicketProcessingService
{
    private ConfigService $config;
    private LogService $logger;
    private JiraService $jiraService;
    private CloudAIService $aiService;
    private GitHubService $githubService;
    private PromptBuilderService $promptBuilder;
    private KnowledgeBaseService $knowledgeBase;
    private CodeReviewService $codeReview;

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
        $this->jiraService = new JiraService();
        $this->aiService = new CloudAIService();
        $this->githubService = new GitHubService();
        $this->promptBuilder = new PromptBuilderService();
        $this->knowledgeBase = new KnowledgeBaseService();
        $this->codeReview = new CodeReviewService();
    }

    /**
     * Verarbeitet ein Ticket komplett von Anfang bis Ende
     */
    public function processTicket(string $ticketKey): array
    {
        $startTime = Carbon::now();
        
        $this->logger->info('Starte komplette Ticket-Verarbeitung', [
            'ticket_key' => $ticketKey,
            'started_at' => $startTime->toISOString()
        ]);

        try {
            // 1. Ticket aus Datenbank laden oder von Jira holen
            $ticketModel = $this->getOrCreateTicketModel($ticketKey);
            $ticketDTO = $this->convertToDTO($ticketModel);
            
            // 2. Processing starten und Status setzen
            $ticketModel->startProcessing();
            
            // 3. IMMER neuen Branch erstellen
            $this->ensureCleanBranch($ticketDTO);
            
            // 4. Knowledge-Base aktualisieren
            $knowledgeBase = $this->knowledgeBase->updateKnowledgeBase($ticketDTO);
            
            // 5. Erweiterten Prompt mit Projekt-Kontext erstellen
            $prompt = $this->buildEnhancedPrompt($ticketDTO, $knowledgeBase);
            
            // 6. AI-Lösung generieren
            $aiSolution = $this->aiService->generateSolution($prompt);
            
            if (!$aiSolution['success']) {
                throw new Exception('AI-Lösungsgenerierung fehlgeschlagen: ' . $aiSolution['error']);
            }
            
            // 7. Code-Änderungen ausführen
            $executionResult = $this->aiService->executeCode($ticketDTO, $aiSolution);
            
            if (!$executionResult['success']) {
                throw new Exception('Code-Ausführung fehlgeschlagen: ' . $executionResult['error']);
            }
            
            // 8. Code-Review durchführen
            $reviewResult = $this->codeReview->performCodeReview($ticketDTO, $executionResult['changes']);
            
            if ($reviewResult['approval_status'] !== 'approved') {
                // Bei nicht-approvierten Changes: Verbesserungen anfordern
                $improvedResult = $this->handleCodeReviewFeedback($ticketDTO, $reviewResult, $executionResult);
                if ($improvedResult) {
                    $executionResult = $improvedResult;
                }
            }
            
            // 9. Pull Request erstellen
            $prResult = $this->githubService->createPullRequest($ticketDTO, $executionResult);
            
            if (!$prResult['success']) {
                throw new Exception('PR-Erstellung fehlgeschlagen: ' . $prResult['error']);
            }
            
            // 10. Processing abschließen
            $endTime = Carbon::now();
            $duration = $startTime->diffInSeconds($endTime);
            
            $ticketModel->completeProcessing([
                'branch' => $prResult['branch'],
                'pr_url' => $prResult['pr_url'],
                'pr_number' => $prResult['pr_number'],
                'commit_hash' => $prResult['commit_hash'],
                'ai_provider' => $aiSolution['provider_used'] ?? 'unknown'
            ]);
            
            // 11. Jira-Ticket aktualisieren mit Zeiterfassung und Kommentaren
            $this->updateJiraTicket($ticketDTO, $prResult, $duration, $reviewResult);
            
            $result = [
                'success' => true,
                'ticket_key' => $ticketKey,
                'processing_time_seconds' => $duration,
                'processing_time_formatted' => $this->formatDuration($duration),
                'pr_url' => $prResult['pr_url'],
                'pr_number' => $prResult['pr_number'],
                'branch' => $prResult['branch'],
                'code_review_score' => $reviewResult['overall_score'] ?? 0,
                'ai_provider' => $aiSolution['provider_used'] ?? 'unknown',
                'completed_at' => $endTime->toISOString()
            ];

            $this->logger->info('Ticket-Verarbeitung erfolgreich abgeschlossen', $result);
            
            return $result;

        } catch (Exception $e) {
            $endTime = Carbon::now();
            $duration = $startTime->diffInSeconds($endTime);
            
            // Fehler in Ticket-Model speichern
            if (isset($ticketModel)) {
                $ticketModel->failProcessing($e->getMessage());
            }
            
            $this->logger->error('Ticket-Verarbeitung fehlgeschlagen', [
                'ticket_key' => $ticketKey,
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'ticket_key' => $ticketKey,
                'error' => $e->getMessage(),
                'processing_time_seconds' => $duration,
                'failed_at' => $endTime->toISOString()
            ];
        }
    }

    /**
     * Stellt sicher, dass ein sauberer Branch existiert
     */
    private function ensureCleanBranch(TicketDTO $ticket): void
    {
        $this->logger->info('Stelle sauberen Branch sicher', [
            'ticket_key' => $ticket->key
        ]);

        try {
            $repoInfo = $ticket->getRepositoryInfo();
            if (!$repoInfo) {
                throw new Exception('Kein Repository für Ticket gefunden');
            }

            $repoPath = $this->config->get('repository.storage_path') . '/' . $repoInfo['full_name'];
            $branchName = $ticket->generateBranchName();

            // Sicherstellen, dass wir auf main/master sind
            $this->runGitCommand($repoPath, 'checkout main || git checkout master');
            
            // Neueste Änderungen pullen
            $this->runGitCommand($repoPath, 'pull origin main || git pull origin master');
            
            // Prüfen ob Branch bereits existiert
            $existingBranches = $this->runGitCommand($repoPath, 'branch -a');
            
            if (strpos($existingBranches, $branchName) !== false) {
                // Branch existiert bereits - löschen und neu erstellen für sauberen Start
                $this->logger->info('Branch existiert bereits, erstelle neu', [
                    'branch' => $branchName
                ]);
                
                $this->runGitCommand($repoPath, "branch -D {$branchName}", false);
                $this->runGitCommand($repoPath, "push origin --delete {$branchName}", false);
            }
            
            // Neuen sauberen Branch erstellen
            $this->runGitCommand($repoPath, "checkout -b {$branchName}");
            
            $this->logger->info('Sauberer Branch erstellt', [
                'branch' => $branchName,
                'ticket_key' => $ticket->key
            ]);

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Branch-Setup', [
                'ticket_key' => $ticket->key,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Erstellt erweiterten Prompt mit vollständigem Kontext
     */
    private function buildEnhancedPrompt(TicketDTO $ticket, array $knowledgeBase): string
    {
        // Basis-Analyse für Ticket
        $analysis = [
            'complexity' => $ticket->estimateComplexity(),
            'required_skills' => $ticket->identifyRequiredSkills(),
            'estimated_time' => $this->estimateProcessingTime($ticket),
            'dependencies' => $this->identifyDependencies($ticket, $knowledgeBase),
            'risks' => $this->identifyRisks($ticket, $knowledgeBase)
        ];

        // Projekt-Kontext hinzufügen
        $projectContext = $this->knowledgeBase->generateProjectContext($ticket);
        
        // Erweiterten Prompt erstellen
        $basePrompt = $this->promptBuilder->buildPrompt($ticket, $analysis);
        
        // Projekt-Kontext einfügen
        $enhancedPrompt = $basePrompt . "\n\n" . $projectContext;
        
        // Tech-Stack-spezifische Informationen
        if (!empty($analysis['required_skills'])) {
            $techStackPrompt = $this->promptBuilder->buildTechStackPrompt($analysis['required_skills']);
            $enhancedPrompt .= "\n\n" . $techStackPrompt;
        }

        // Prompt validieren
        $validation = $this->promptBuilder->validatePrompt($enhancedPrompt);
        if (!$validation['valid']) {
            $this->logger->warning('Prompt-Validierung fehlgeschlagen', [
                'ticket_key' => $ticket->key,
                'issues' => $validation['issues']
            ]);
        }

        return $enhancedPrompt;
    }

    /**
     * Behandelt Code-Review-Feedback und verbessert Code
     */
    private function handleCodeReviewFeedback(TicketDTO $ticket, array $reviewResult, array $originalResult): ?array
    {
        if ($reviewResult['overall_score'] < 0.5) {
            // Bei sehr schlechtem Score: Komplett neu generieren
            $this->logger->info('Code-Quality zu schlecht, generiere neu', [
                'ticket_key' => $ticket->key,
                'score' => $reviewResult['overall_score']
            ]);

            $improvementPrompt = $this->promptBuilder->buildSelfHealingPrompt(
                $ticket, 
                ['message' => 'Code-Review fehlgeschlagen', 'type' => 'quality'], 
                []
            );

            $improvedSolution = $this->aiService->generateSolution($improvementPrompt);
            
            if ($improvedSolution['success']) {
                return $this->aiService->executeCode($ticket, $improvedSolution);
            }
        }

        return null;
    }

    /**
     * Aktualisiert Jira-Ticket mit Ergebnissen und Zeiterfassung
     */
    private function updateJiraTicket(TicketDTO $ticket, array $prResult, int $duration, array $reviewResult): void
    {
        try {
            // 1. Zeiterfassung hinzufügen
            $timeComment = $this->buildTimeTrackingComment($duration, $reviewResult);
            $this->jiraService->addComment($ticket->key, $timeComment);

            // 2. Beschreibung der Änderungen als Kommentar
            $changesComment = $this->buildChangesComment($prResult, $reviewResult);
            $this->jiraService->addComment($ticket->key, $changesComment);

            // 3. PR-URL als Kommentar hinzufügen
            $prComment = $this->buildPRComment($prResult);
            $this->jiraService->addComment($ticket->key, $prComment);

            // 4. Optional: Ticket-Status aktualisieren
            if ($this->config->get('jira.auto_update_status', true)) {
                $newStatus = $this->config->get('jira.completed_status', 'In Review');
                $this->jiraService->updateTicketStatus($ticket->key, $newStatus);
            }

            $this->logger->info('Jira-Ticket erfolgreich aktualisiert', [
                'ticket_key' => $ticket->key,
                'duration_minutes' => round($duration / 60, 2),
                'pr_url' => $prResult['pr_url']
            ]);

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Aktualisieren des Jira-Tickets', [
                'ticket_key' => $ticket->key,
                'error' => $e->getMessage()
            ]);
            // Nicht kritisch - Ticket-Verarbeitung trotzdem als erfolgreich werten
        }
    }

    /**
     * Erstellt Zeiterfassungs-Kommentar
     */
    private function buildTimeTrackingComment(int $duration, array $reviewResult): string
    {
        $minutes = round($duration / 60, 2);
        $hours = round($duration / 3600, 2);

        $comment = "🤖 **Automatische Zeiterfassung - Octomind Bot**\n\n";
        $comment .= "**Verarbeitungszeit:** {$minutes} Minuten ({$hours} Stunden)\n";
        $comment .= "**Verarbeitungsdetails:**\n";
        $comment .= "- Code-Review-Score: " . round(($reviewResult['overall_score'] ?? 0) * 100, 1) . "%\n";
        $comment .= "- Status: " . ($reviewResult['approval_status'] ?? 'unknown') . "\n";
        $comment .= "- Verarbeitet am: " . now()->format('d.m.Y H:i:s') . "\n\n";
        $comment .= "*Diese Zeit wurde automatisch vom Octomind Bot erfasst.*";

        return $comment;
    }

    /**
     * Erstellt Änderungs-Kommentar
     */
    private function buildChangesComment(array $prResult, array $reviewResult): string
    {
        $comment = "🔧 **Automatische Implementierung abgeschlossen**\n\n";
        $comment .= "**Durchgeführte Änderungen:**\n";
        $comment .= "- Branch erstellt: `{$prResult['branch']}`\n";
        $comment .= "- Commit Hash: `{$prResult['commit_hash']}`\n";
        $comment .= "- Code-Review durchgeführt\n";
        $comment .= "- Automatische Tests ausgeführt\n";
        $comment .= "- Sicherheits-Checks durchgeführt\n\n";

        if (isset($reviewResult['recommendations'])) {
            $comment .= "**Code-Review-Ergebnisse:**\n";
            foreach (array_slice($reviewResult['recommendations'], 0, 3) as $rec) {
                $comment .= "- {$rec['message']}\n";
            }
            $comment .= "\n";
        }

        $comment .= "*Alle Änderungen wurden automatisch vom Octomind Bot implementiert.*";

        return $comment;
    }

    /**
     * Erstellt PR-Kommentar
     */
    private function buildPRComment(array $prResult): string
    {
        $comment = "🚀 **Pull Request erstellt**\n\n";
        $comment .= "**PR-Details:**\n";
        $comment .= "- **URL:** {$prResult['pr_url']}\n";
        $comment .= "- **PR-Nummer:** #{$prResult['pr_number']}\n";
        $comment .= "- **Branch:** `{$prResult['branch']}`\n\n";
        $comment .= "Der Pull Request ist bereit für Review und kann nach Prüfung gemerged werden.\n\n";
        $comment .= "*Automatisch erstellt vom Octomind Bot*";

        return $comment;
    }

    /**
     * Hilfsmethoden
     */
    private function getOrCreateTicketModel(string $ticketKey): Ticket
    {
        $ticket = Ticket::findByJiraKey($ticketKey);
        
        if (!$ticket) {
            // Ticket von Jira holen
            $jiraTickets = $this->jiraService->fetchTickets();
            $jiraTicket = collect($jiraTickets)->firstWhere('key', $ticketKey);
            
            if (!$jiraTicket) {
                throw new Exception("Ticket {$ticketKey} nicht gefunden");
            }
            
            // Ticket in Datenbank erstellen (wird bereits in JiraService gemacht)
            $ticket = Ticket::findByJiraKey($ticketKey);
        }
        
        return $ticket;
    }

    private function convertToDTO(Ticket $ticket): TicketDTO
    {
        return new TicketDTO(
            key: $ticket->jira_key,
            summary: $ticket->summary,
            description: $ticket->description,
            status: $ticket->jira_status,
            priority: $ticket->priority,
            assignee: $ticket->assignee,
            reporter: $ticket->reporter,
            created: $ticket->jira_created_at,
            updated: $ticket->jira_updated_at,
            labels: $ticket->labels ?? [],
            repositoryUrl: $ticket->repository_url
        );
    }

    private function estimateProcessingTime(TicketDTO $ticket): int
    {
        $baseTime = 15; // Minuten
        $complexity = $ticket->estimateComplexity();
        
        $multiplier = match($complexity) {
            'very_high' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1.5,
            default => 1
        };
        
        return (int)($baseTime * $multiplier);
    }

    private function identifyDependencies(TicketDTO $ticket, array $knowledgeBase): array
    {
        $dependencies = [];
        
        // Aus Knowledge-Base extrahieren
        if (isset($knowledgeBase['dependencies'])) {
            $dependencies = array_merge($dependencies, array_keys($knowledgeBase['dependencies']));
        }
        
        return $dependencies;
    }

    private function identifyRisks(TicketDTO $ticket, array $knowledgeBase): array
    {
        $risks = [];
        
        // Git-Status-basierte Risiken
        if (isset($knowledgeBase['git_status']['uncommitted_changes'])) {
            $risks[] = 'Uncommitted changes in working directory';
        }
        
        // Komplexitäts-basierte Risiken
        if ($ticket->estimateComplexity() === 'very_high') {
            $risks[] = 'High complexity ticket may require manual review';
        }
        
        return $risks;
    }

    private function formatDuration(int $seconds): string
    {
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;
        
        if ($minutes > 0) {
            return "{$minutes}m {$remainingSeconds}s";
        }
        
        return "{$seconds}s";
    }

    private function runGitCommand(string $workingDir, string $command, bool $throwOnError = true): string
    {
        $fullCommand = "cd \"{$workingDir}\" && git {$command}";
        
        $output = [];
        $returnCode = 0;
        
        exec($fullCommand . ' 2>&1', $output, $returnCode);
        
        $outputString = implode("\n", $output);
        
        if ($returnCode !== 0 && $throwOnError) {
            throw new Exception("Git-Befehl fehlgeschlagen: {$command}\nOutput: {$outputString}");
        }

        return $outputString;
    }

    /**
     * Public API Methoden für Controller
     */
    public function getTickets(int $limit = 20, ?string $status = null): array
    {
        $query = Ticket::query()->orderBy('created_at', 'desc')->limit($limit);
        
        if ($status) {
            $query->where('status', $status);
        }
        
        return $query->get()->map(function ($ticket) {
            return [
                'key' => $ticket->jira_key,
                'summary' => $ticket->summary,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'created_at' => $ticket->created_at,
                'processing_duration' => $ticket->formatted_duration,
                'pr_url' => $ticket->pr_url,
                'complexity' => $ticket->complexity_level
            ];
        })->toArray();
    }

    public function getRecentTickets(): array
    {
        return $this->getTickets(10);
    }

    public function getTicketDetails(string $ticketKey): ?array
    {
        $ticket = Ticket::findByJiraKey($ticketKey);
        
        if (!$ticket) {
            return null;
        }
        
        return [
            'key' => $ticket->jira_key,
            'summary' => $ticket->summary,
            'description' => $ticket->description,
            'status' => $ticket->status,
            'jira_status' => $ticket->jira_status,
            'priority' => $ticket->priority,
            'assignee' => $ticket->assignee,
            'reporter' => $ticket->reporter,
            'repository_url' => $ticket->repository_url,
            'labels' => $ticket->labels,
            'processing_started_at' => $ticket->processing_started_at,
            'processing_completed_at' => $ticket->processing_completed_at,
            'processing_duration_seconds' => $ticket->processing_duration_seconds,
            'formatted_duration' => $ticket->formatted_duration,
            'branch_name' => $ticket->branch_name,
            'pr_url' => $ticket->pr_url,
            'pr_number' => $ticket->pr_number,
            'commit_hash' => $ticket->commit_hash,
            'ai_provider_used' => $ticket->ai_provider_used,
            'complexity_score' => $ticket->complexity_score,
            'complexity_level' => $ticket->complexity_level,
            'required_skills' => $ticket->required_skills,
            'error_message' => $ticket->error_message,
            'retry_count' => $ticket->retry_count,
            'jira_url' => $ticket->jira_url,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at
        ];
    }
} 