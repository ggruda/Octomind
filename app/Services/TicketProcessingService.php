<?php

namespace App\Services;

use App\DTOs\TicketDTO;
use App\Models\Ticket;
use App\Models\Project;
use App\Models\Repository;
use App\Services\KnowledgeBaseService;
use App\Services\CodeReviewService;
use App\Services\ProjectService;
use App\Services\RepositoryService;
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
    private RepositoryInitializationService $repoInit;
    private ProjectService $projectService;
    private RepositoryService $repositoryService;

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
        $this->repoInit = new RepositoryInitializationService();
        $this->projectService = new ProjectService();
        $this->repositoryService = new RepositoryService();
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
            
            // 3. Projekt und Repository aus DB auflösen
            $this->resolveProjectAndRepository($ticketModel, $ticketDTO);
            
            // 4. Repository initialisieren/synchronisieren
            $this->ensureRepositoryReady($ticketModel->repository);
            
            // 5. IMMER neuen Branch erstellen
            $this->ensureCleanBranch($ticketDTO, $ticketModel->repository);
            
            // 6. Knowledge-Base aktualisieren
            $knowledgeBase = $this->knowledgeBase->updateKnowledgeBase($ticketDTO);
            
            // 7. Erweiterten Prompt mit Projekt-Kontext erstellen
            $prompt = $this->buildEnhancedPrompt($ticketDTO, $knowledgeBase, $ticketModel->repository);
            
            // 8. AI-Lösung generieren
            $aiSolution = $this->aiService->generateSolution($prompt);
            
            if (!$aiSolution['success']) {
                throw new Exception('AI-Lösungsgenerierung fehlgeschlagen: ' . $aiSolution['error']);
            }
            
            // 9. Code-Änderungen ausführen
            $executionResult = $this->aiService->executeCode($ticketDTO, $aiSolution);
            
            if (!$executionResult['success']) {
                throw new Exception('Code-Ausführung fehlgeschlagen: ' . $executionResult['error']);
            }
            
            // 10. Code-Review durchführen
            $reviewResult = $this->codeReview->performCodeReview($ticketDTO, $executionResult['changes']);
            
            // 11. Commit und Push
            $commitResult = $this->commitAndPushChanges($ticketDTO, $executionResult, $ticketModel->repository);
            
            // 12. Pull Request erstellen
            $prResult = $this->createPullRequest($ticketDTO, $commitResult, $ticketModel->repository);
            
            // 13. Jira-Ticket aktualisieren
            $this->updateJiraTicket($ticketDTO, $prResult);
            
            // 14. Ticket als erfolgreich markieren
            $processingTime = Carbon::now()->diffInSeconds($startTime);
            $ticketModel->markCompleted($processingTime, $prResult['pr_url'], $prResult['pr_number'], $commitResult['commit_hash']);
            
            // 15. Projekt-Statistiken aktualisieren
            $this->projectService->updateTicketStats($ticketModel->project, true);
            
            // 16. Repository-Statistiken aktualisieren
            $this->repositoryService->updateCommitStats($ticketModel->repository, $commitResult['commit_hash']);
            $this->repositoryService->updatePRStats($ticketModel->repository);

            $this->logger->info('Ticket-Verarbeitung erfolgreich abgeschlossen', [
                'ticket_key' => $ticketKey,
                'processing_time' => $processingTime,
                'pr_url' => $prResult['pr_url'],
                'project' => $ticketModel->project->jira_key,
                'repository' => $ticketModel->repository->full_name
            ]);

            return [
                'success' => true,
                'ticket_key' => $ticketKey,
                'processing_time' => $processingTime,
                'pr_url' => $prResult['pr_url'],
                'pr_number' => $prResult['pr_number'],
                'commit_hash' => $commitResult['commit_hash'],
                'project' => $ticketModel->project->jira_key,
                'repository' => $ticketModel->repository->full_name,
                'changes_count' => count($executionResult['changes'])
            ];

        } catch (Exception $e) {
            $processingTime = Carbon::now()->diffInSeconds($startTime);
            
            $this->logger->error('Ticket-Verarbeitung fehlgeschlagen', [
                'ticket_key' => $ticketKey,
                'processing_time' => $processingTime,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Ticket als fehlgeschlagen markieren
            if (isset($ticketModel)) {
                $ticketModel->markFailed($e->getMessage(), $processingTime);
                
                // Projekt-Statistiken aktualisieren
                if ($ticketModel->project) {
                    $this->projectService->updateTicketStats($ticketModel->project, false);
                }
            }

            return [
                'success' => false,
                'ticket_key' => $ticketKey,
                'processing_time' => $processingTime,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Löst Projekt und Repository für ein Ticket auf
     */
    private function resolveProjectAndRepository(Ticket $ticketModel, TicketDTO $ticketDTO): void
    {
        $this->logger->info('Löse Projekt und Repository für Ticket auf', [
            'ticket_key' => $ticketDTO->key
        ]);

        // 1. Projekt aus Jira-Key extrahieren und in DB finden
        $projectKey = $this->extractProjectKeyFromTicket($ticketDTO->key);
        $project = $this->projectService->findByJiraKey($projectKey);

        if (!$project) {
            throw new Exception("Projekt '{$projectKey}' nicht in Datenbank gefunden. Bitte erst erstellen mit: php artisan octomind:project create {$projectKey}");
        }

        if (!$project->bot_enabled) {
            throw new Exception("Bot ist für Projekt '{$projectKey}' deaktiviert");
        }

        // 2. Repository für dieses Ticket auflösen
        $repository = $this->projectService->resolveRepositoryForTicket($ticketModel);

        if (!$repository) {
            throw new Exception("Kein Repository für Ticket '{$ticketDTO->key}' gefunden. Bitte Repository mit Projekt verknüpfen.");
        }

        if (!$repository->bot_enabled) {
            throw new Exception("Bot ist für Repository '{$repository->full_name}' deaktiviert");
        }

        // 3. Ticket mit Projekt und Repository verknüpfen
        $ticketModel->update([
            'project_id' => $project->id,
            'repository_id' => $repository->id,
            'repository_url' => $repository->clone_url // Für Kompatibilität
        ]);

        $this->logger->info('Projekt und Repository erfolgreich aufgelöst', [
            'ticket_key' => $ticketDTO->key,
            'project' => $project->jira_key,
            'repository' => $repository->full_name
        ]);
    }

    /**
     * Stellt sicher, dass Repository bereit ist
     */
    private function ensureRepositoryReady(Repository $repository): void
    {
        $this->logger->info('Stelle sicher, dass Repository bereit ist', [
            'repository' => $repository->full_name
        ]);

        // 1. Prüfe ob Repository geklont ist, sonst klonen
        if (!file_exists($repository->local_workspace_path . '/.git')) {
            $this->logger->info('Repository nicht geklont, klone jetzt', [
                'repository' => $repository->full_name
            ]);

            $cloneResult = $this->repositoryService->cloneRepository($repository);
            
            if (!$cloneResult['success']) {
                throw new Exception('Repository-Kloning fehlgeschlagen: ' . $cloneResult['error']);
            }
        }

        // 2. Repository synchronisieren (git pull)
        if ($repository->is_stale) {
            $this->logger->info('Repository ist veraltet, synchronisiere', [
                'repository' => $repository->full_name
            ]);

            $syncResult = $this->repositoryService->syncRepository($repository);
            
            if (!$syncResult['success']) {
                throw new Exception('Repository-Synchronisation fehlgeschlagen: ' . $syncResult['error']);
            }
        }

        $this->logger->info('Repository ist bereit', [
            'repository' => $repository->full_name,
            'path' => $repository->local_workspace_path
        ]);
    }

    /**
     * Stellt sicher, dass ein sauberer Branch existiert
     */
    private function ensureCleanBranch(TicketDTO $ticket, Repository $repository): void
    {
        $this->logger->info('Stelle sauberen Branch sicher', [
            'ticket_key' => $ticket->key,
            'repository' => $repository->full_name
        ]);

        try {
            $repoPath = $repository->local_workspace_path;
            $branchName = $repository->getBranchName($ticket->key);

            // Sicherstellen, dass wir auf dem Standard-Branch sind
            $this->runGitCommand($repoPath, "checkout {$repository->default_branch}");
            
            // Neueste Änderungen pullen
            $this->runGitCommand($repoPath, "pull origin {$repository->default_branch}");
            
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
                'ticket_key' => $ticket->key,
                'repository' => $repository->full_name
            ]);

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Branch-Setup', [
                'ticket_key' => $ticket->key,
                'repository' => $repository->full_name,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Erstellt erweiterten Prompt mit Repository-Kontext
     */
    private function buildEnhancedPrompt(TicketDTO $ticket, array $knowledgeBase, Repository $repository): string
    {
        $this->logger->info('Erstelle erweiterten Prompt mit Repository-Kontext', [
            'ticket_key' => $ticket->key,
            'repository' => $repository->full_name,
            'framework' => $repository->framework_type
        ]);

        $repositoryContext = [
            'repository_name' => $repository->full_name,
            'framework_type' => $repository->framework_type,
            'package_manager' => $repository->package_manager,
            'default_branch' => $repository->default_branch,
            'allowed_file_extensions' => $repository->allowed_file_extensions,
            'forbidden_paths' => $repository->forbidden_paths,
            'workspace_path' => $repository->local_workspace_path
        ];

        return $this->promptBuilder->buildEnhancedPrompt($ticket, $knowledgeBase, $repositoryContext);
    }

    /**
     * Commit und Push mit Repository-spezifischer Konfiguration
     * WICHTIG: Wird nach JEDER Aufgabe ausgeführt (git add . && git commit && git push)
     */
    private function commitAndPushChanges(TicketDTO $ticket, array $executionResult, Repository $repository): array
    {
        $this->logger->info('🔄 Starte Git-Workflow: add -> commit -> push', [
            'ticket_key' => $ticket->key,
            'repository' => $repository->full_name,
            'changes_count' => count($executionResult['changes'])
        ]);

        try {
            $repoPath = $repository->local_workspace_path;
            $branchName = $repository->getBranchName($ticket->key);

            // Git-Konfiguration für Repository setzen
            $this->configureGitForRepository($repoPath, $repository);

            // SCHRITT 1: git add . (ALLE Änderungen hinzufügen)
            $this->logger->debug('🔹 Schritt 1: git add .', [
                'repository_path' => $repoPath
            ]);
            
            $addOutput = $this->runGitCommand($repoPath, 'add .');
            
            // Prüfen was hinzugefügt wurde
            $statusOutput = $this->runGitCommand($repoPath, 'status --porcelain');
            
            if (empty(trim($statusOutput))) {
                $this->logger->warning('⚠️ Keine Änderungen zum Committen gefunden', [
                    'ticket_key' => $ticket->key,
                    'repository' => $repository->full_name
                ]);
                
                // Trotzdem erfolgreich zurückgeben, da keine Änderungen = kein Fehler
                return [
                    'success' => true,
                    'commit_hash' => null,
                    'branch' => $branchName,
                    'commit_message' => 'Keine Änderungen',
                    'no_changes' => true
                ];
            }

            $this->logger->debug('✅ Git add erfolgreich', [
                'staged_changes' => explode("\n", trim($statusOutput))
            ]);

            // SCHRITT 2: git commit (Commit erstellen)
            $this->logger->debug('🔹 Schritt 2: git commit');
            
            $commitMessage = $this->buildCommitMessage($ticket, $executionResult);
            $commitOutput = $this->runGitCommand($repoPath, "commit -m \"{$commitMessage}\"");

            // Commit-Hash ermitteln
            $commitHash = trim($this->runGitCommand($repoPath, 'rev-parse HEAD'));
            
            $this->logger->debug('✅ Git commit erfolgreich', [
                'commit_hash' => substr($commitHash, 0, 8),
                'commit_message' => $commitMessage
            ]);

            // SCHRITT 3: git push (Änderungen hochladen)
            $this->logger->debug('🔹 Schritt 3: git push origin ' . $branchName);
            
            $pushOutput = $this->runGitCommand($repoPath, "push origin {$branchName}");
            
            $this->logger->debug('✅ Git push erfolgreich', [
                'branch' => $branchName,
                'remote' => 'origin'
            ]);

            // Erfolgreiche Zusammenfassung
            $this->logger->info('🎉 Git-Workflow erfolgreich abgeschlossen (add -> commit -> push)', [
                'ticket_key' => $ticket->key,
                'repository' => $repository->full_name,
                'commit_hash' => substr($commitHash, 0, 8),
                'branch' => $branchName,
                'changes_committed' => count($executionResult['changes'])
            ]);

            return [
                'success' => true,
                'commit_hash' => $commitHash,
                'branch' => $branchName,
                'commit_message' => $commitMessage,
                'git_add_output' => $addOutput,
                'git_commit_output' => $commitOutput,
                'git_push_output' => $pushOutput
            ];

        } catch (Exception $e) {
            $this->logger->error('❌ Git-Workflow fehlgeschlagen', [
                'ticket_key' => $ticket->key,
                'repository' => $repository->full_name,
                'error' => $e->getMessage(),
                'failed_step' => $this->determineFailedGitStep($e->getMessage())
            ]);
            
            // Zusätzliche Debug-Informationen bei Git-Fehlern
            try {
                $gitStatus = $this->runGitCommand($repoPath, 'status', false);
                $this->logger->debug('Git-Status bei Fehler', [
                    'git_status' => $gitStatus
                ]);
            } catch (Exception $statusException) {
                $this->logger->debug('Konnte Git-Status nicht ermitteln', [
                    'status_error' => $statusException->getMessage()
                ]);
            }
            
            throw new Exception("Git-Workflow fehlgeschlagen: {$e->getMessage()}");
        }
    }

    /**
     * Ermittelt welcher Git-Schritt fehlgeschlagen ist
     */
    private function determineFailedGitStep(string $errorMessage): string
    {
        if (str_contains($errorMessage, 'git add')) {
            return 'git_add';
        } elseif (str_contains($errorMessage, 'commit')) {
            return 'git_commit';
        } elseif (str_contains($errorMessage, 'push')) {
            return 'git_push';
        } else {
            return 'unknown';
        }
    }

    /**
     * Erstellt Pull Request mit Repository-spezifischer Konfiguration
     */
    private function createPullRequest(TicketDTO $ticket, array $commitResult, Repository $repository): array
    {
        $this->logger->info('Erstelle Pull Request', [
            'ticket_key' => $ticket->key,
            'repository' => $repository->full_name,
            'branch' => $commitResult['branch']
        ]);

        try {
            $prTitle = "🤖 {$ticket->key}: {$ticket->summary}";
            $prBody = $this->buildPRDescription($ticket, $commitResult, $repository);

            // GitHub-Service mit Repository-Konfiguration verwenden
            $prResult = $this->githubService->createPullRequest(
                $repository->owner,
                $repository->name,
                $commitResult['branch'],
                $repository->default_branch,
                $prTitle,
                $prBody,
                $repository->create_draft_prs
            );

            if (!$prResult['success']) {
                throw new Exception('PR-Erstellung fehlgeschlagen: ' . $prResult['error']);
            }

            $this->logger->info('Pull Request erfolgreich erstellt', [
                'ticket_key' => $ticket->key,
                'repository' => $repository->full_name,
                'pr_url' => $prResult['pr_url'],
                'pr_number' => $prResult['pr_number']
            ]);

            return $prResult;

        } catch (Exception $e) {
            $this->logger->error('PR-Erstellung fehlgeschlagen', [
                'ticket_key' => $ticket->key,
                'repository' => $repository->full_name,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Konfiguriert Git für Repository-spezifische Einstellungen
     */
    private function configureGitForRepository(string $repoPath, Repository $repository): void
    {
        $authorName = $this->config->get('repository.commit_author_name', 'Octomind Bot');
        $authorEmail = $this->config->get('repository.commit_author_email', 'bot@octomind.com');

        $commands = [
            "cd {$repoPath}",
            "git config user.name \"{$authorName}\"",
            "git config user.email \"{$authorEmail}\"",
            "git config init.defaultBranch {$repository->default_branch}"
        ];

        foreach ($commands as $command) {
            shell_exec($command . ' 2>&1');
        }

        $this->logger->debug('Git für Repository konfiguriert', [
            'repository' => $repository->full_name,
            'author' => $authorName,
            'email' => $authorEmail
        ]);
    }

    /**
     * Erstellt PR-Beschreibung mit Repository-Kontext
     */
    private function buildPRDescription(TicketDTO $ticket, array $commitResult, Repository $repository): string
    {
        $description = "## 🎫 Jira-Ticket\n\n";
        $description .= "**Ticket:** [{$ticket->key}]({$this->getJiraTicketUrl($ticket)})\n";
        $description .= "**Zusammenfassung:** {$ticket->summary}\n\n";

        if ($ticket->description) {
            $description .= "**Beschreibung:**\n{$ticket->description}\n\n";
        }

        $description .= "## 🔗 Repository-Informationen\n\n";
        $description .= "**Repository:** {$repository->full_name}\n";
        $description .= "**Framework:** " . ($repository->framework_type ?? 'Unbekannt') . "\n";
        $description .= "**Branch:** `{$commitResult['branch']}`\n";
        $description .= "**Commit:** `" . substr($commitResult['commit_hash'], 0, 8) . "`\n\n";

        $description .= "## 🤖 Automatisch generiert\n\n";
        $description .= "Dieser Pull Request wurde automatisch vom Octomind Bot erstellt.\n";
        
        if ($repository->create_draft_prs) {
            $description .= "\n⚠️ **Draft-Status:** Bitte Review durchführen bevor Merge.\n";
        }

        return $description;
    }

    /**
     * Extrahiert Projekt-Key aus Ticket-Key
     */
    private function extractProjectKeyFromTicket(string $ticketKey): string
    {
        // Beispiel: "PROJ-123" -> "PROJ"
        return explode('-', $ticketKey)[0];
    }

    /**
     * Holt oder erstellt Ticket-Model
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
            
            // Ticket wurde bereits in JiraService in DB gespeichert
            $ticket = Ticket::findByJiraKey($ticketKey);
        }
        
        return $ticket;
    }

    /**
     * Konvertiert Ticket-Model zu DTO
     */
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

    /**
     * Erstellt Commit-Message
     */
    private function buildCommitMessage(TicketDTO $ticket, array $executionResult): string
    {
        $message = "🤖 {$ticket->key}: {$ticket->summary}";
        
        if (count($executionResult['changes']) > 0) {
            $message .= "\n\nÄnderungen:";
            foreach ($executionResult['changes'] as $change) {
                $message .= "\n- {$change['file']}: {$change['description']}";
            }
        }
        
        $message .= "\n\nAutomatisch generiert vom Octomind Bot";
        
        return $message;
    }

    /**
     * Holt Jira-Ticket-URL
     */
    private function getJiraTicketUrl(TicketDTO $ticket): string
    {
        $baseUrl = $this->config->get('auth.jira_base_url');
        return rtrim($baseUrl, '/') . '/browse/' . $ticket->key;
    }

    /**
     * Führt Git-Befehl aus
     */
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
        $query = Ticket::query()
                      ->with(['project', 'repository'])
                      ->orderBy('created_at', 'desc')
                      ->limit($limit);
        
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
                'complexity' => $ticket->complexity_level,
                'project' => $ticket->project?->jira_key,
                'repository' => $ticket->repository?->full_name
            ];
        })->toArray();
    }

    public function getRecentTickets(): array
    {
        return $this->getTickets(10);
    }

    public function getTicketDetails(string $ticketKey): ?array
    {
        $ticket = Ticket::with(['project', 'repository'])->where('jira_key', $ticketKey)->first();
        
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
            'updated_at' => $ticket->updated_at,
            'project' => $ticket->project ? [
                'jira_key' => $ticket->project->jira_key,
                'name' => $ticket->project->name,
                'jira_url' => $ticket->project->jira_url
            ] : null,
            'repository' => $ticket->repository ? [
                'full_name' => $ticket->repository->full_name,
                'provider' => $ticket->repository->provider,
                'framework_type' => $ticket->repository->framework_type,
                'provider_url' => $ticket->repository->provider_url
            ] : null
        ];
    }
} 