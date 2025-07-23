<?php

namespace App\Services;

use App\DTOs\TicketDTO;
use App\Enums\AiProvider;
use Illuminate\Support\Facades\Http;
use Exception;

class CloudAIService
{
    private ConfigService $config;
    private LogService $logger;
    private string $primaryProvider;
    private string $fallbackProvider;

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
        
        $this->primaryProvider = $this->config->get('ai.primary_provider', 'openai');
        $this->fallbackProvider = $this->config->get('ai.fallback_provider', 'claude');
    }

    /**
     * Generiert eine Lösung basierend auf dem Prompt
     */
    public function generateSolution(string $prompt): array
    {
        $this->logger->info('Starte AI-Lösungsgenerierung', [
            'primary_provider' => $this->primaryProvider,
            'fallback_provider' => $this->fallbackProvider,
            'prompt_length' => strlen($prompt)
        ]);

        try {
            // Versuche zuerst den primären Provider
            $result = $this->callAIProvider($this->primaryProvider, $prompt, 'solution');
            
            if ($result['success']) {
                $this->logger->info('Lösung erfolgreich mit primärem Provider generiert', [
                    'provider' => $this->primaryProvider,
                    'confidence' => $result['confidence'] ?? 0
                ]);
                return $result;
            }

            // Fallback zum sekundären Provider
            $this->logger->warning('Primärer Provider fehlgeschlagen, versuche Fallback', [
                'primary_provider' => $this->primaryProvider,
                'fallback_provider' => $this->fallbackProvider,
                'error' => $result['error'] ?? 'Unknown error'
            ]);

            $fallbackResult = $this->callAIProvider($this->fallbackProvider, $prompt, 'solution');
            
            if ($fallbackResult['success']) {
                $this->logger->info('Lösung erfolgreich mit Fallback-Provider generiert', [
                    'provider' => $this->fallbackProvider,
                    'confidence' => $fallbackResult['confidence'] ?? 0
                ]);
                return $fallbackResult;
            }

            // Beide Provider fehlgeschlagen
            throw new Exception('Beide AI-Provider fehlgeschlagen: ' . ($fallbackResult['error'] ?? 'Unknown error'));

        } catch (Exception $e) {
            $this->logger->error('AI-Lösungsgenerierung fehlgeschlagen', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'solution' => null,
                'confidence' => 0
            ];
        }
    }

    /**
     * Führt Code-Änderungen basierend auf der AI-Lösung aus
     */
    public function executeCode(TicketDTO $ticket, array $solution): array
    {
        $this->logger->info('Starte Code-Ausführung', [
            'ticket_key' => $ticket->key,
            'solution_confidence' => $solution['confidence'] ?? 0
        ]);

        try {
            // Generiere detaillierten Ausführungsplan
            $executionPrompt = $this->buildExecutionPrompt($ticket, $solution);
            
            $executionResult = $this->callAIProvider(
                $this->primaryProvider, 
                $executionPrompt, 
                'code_execution'
            );

            if (!$executionResult['success']) {
                // Fallback-Provider versuchen
                $executionResult = $this->callAIProvider(
                    $this->fallbackProvider, 
                    $executionPrompt, 
                    'code_execution'
                );
            }

            if ($executionResult['success']) {
                // Simuliere Code-Änderungen (in echter Implementierung würde hier Git-Operations stattfinden)
                $changes = $this->processCodeChanges($ticket, $executionResult);
                
                $this->logger->info('Code-Ausführung erfolgreich', [
                    'ticket_key' => $ticket->key,
                    'files_changed' => count($changes['files']),
                    'branch' => $changes['branch']
                ]);

                return [
                    'success' => true,
                    'changes' => $changes,
                    'execution_log' => $executionResult['execution_log'] ?? [],
                    'provider_used' => $executionResult['provider_used'] ?? $this->primaryProvider
                ];
            }

            throw new Exception('Code-Ausführung fehlgeschlagen: ' . ($executionResult['error'] ?? 'Unknown error'));

        } catch (Exception $e) {
            $this->logger->error('Code-Ausführung fehlgeschlagen', [
                'ticket_key' => $ticket->key,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'changes' => null
            ];
        }
    }

    /**
     * Ruft einen AI-Provider auf
     */
    private function callAIProvider(string $provider, string $prompt, string $type = 'general'): array
    {
        $startTime = microtime(true);
        
        try {
            switch ($provider) {
                case 'openai':
                    $result = $this->callOpenAI($prompt, $type);
                    break;
                case 'claude':
                    $result = $this->callClaude($prompt, $type);
                    break;
                default:
                    throw new Exception("Unbekannter AI-Provider: {$provider}");
            }

            $responseTime = (microtime(true) - $startTime) * 1000;
            
            $this->logger->performance('ai_api_call', $responseTime / 1000, [
                'provider' => $provider,
                'type' => $type,
                'response_time_ms' => $responseTime,
                'success' => $result['success'] ?? false
            ]);

            $result['provider_used'] = $provider;
            return $result;

        } catch (Exception $e) {
            $responseTime = (microtime(true) - $startTime) * 1000;
            
            $this->logger->error('AI-Provider-Aufruf fehlgeschlagen', [
                'provider' => $provider,
                'type' => $type,
                'error' => $e->getMessage(),
                'response_time_ms' => $responseTime
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'provider_used' => $provider
            ];
        }
    }

    /**
     * Ruft OpenAI API auf
     */
    private function callOpenAI(string $prompt, string $type): array
    {
        $apiKey = $this->config->get('auth.openai_api_key');
        if (!$apiKey) {
            throw new Exception('OpenAI API Key nicht konfiguriert');
        }

        $model = $this->config->get('ai.model_openai', 'gpt-4');
        $maxTokens = $this->config->get('ai.max_tokens', 4096);
        $temperature = $this->config->get('ai.temperature', 0.7);

        $systemPrompt = $this->getSystemPrompt($type);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'stream' => false
        ]);

        if (!$response->successful()) {
            throw new Exception("OpenAI API Fehler: HTTP {$response->status()} - " . $response->body());
        }

        $data = $response->json();
        
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new Exception('Unerwartete OpenAI API Antwort');
        }

        $content = $data['choices'][0]['message']['content'];
        $usage = $data['usage'] ?? [];

        return [
            'success' => true,
            'solution' => $content,
            'confidence' => $this->calculateConfidence($data, 'openai'),
            'steps' => $this->extractSteps($content),
            'metadata' => [
                'model' => $model,
                'tokens_used' => $usage['total_tokens'] ?? 0,
                'finish_reason' => $data['choices'][0]['finish_reason'] ?? 'unknown'
            ]
        ];
    }

    /**
     * Ruft Claude (Anthropic) API auf
     */
    private function callClaude(string $prompt, string $type): array
    {
        $apiKey = $this->config->get('auth.anthropic_api_key');
        if (!$apiKey) {
            throw new Exception('Anthropic API Key nicht konfiguriert');
        }

        $model = $this->config->get('ai.model_claude', 'claude-3-sonnet-20240229');
        $maxTokens = $this->config->get('ai.max_tokens', 4096);

        $systemPrompt = $this->getSystemPrompt($type);

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'anthropic-version' => '2023-06-01'
        ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ]);

        if (!$response->successful()) {
            throw new Exception("Claude API Fehler: HTTP {$response->status()} - " . $response->body());
        }

        $data = $response->json();
        
        if (!isset($data['content'][0]['text'])) {
            throw new Exception('Unerwartete Claude API Antwort');
        }

        $content = $data['content'][0]['text'];

        return [
            'success' => true,
            'solution' => $content,
            'confidence' => $this->calculateConfidence($data, 'claude'),
            'steps' => $this->extractSteps($content),
            'metadata' => [
                'model' => $model,
                'tokens_used' => $data['usage']['output_tokens'] ?? 0,
                'stop_reason' => $data['stop_reason'] ?? 'unknown'
            ]
        ];
    }

    /**
     * Generiert System-Prompt basierend auf dem Typ
     */
    private function getSystemPrompt(string $type): string
    {
        switch ($type) {
            case 'solution':
                return "Du bist ein erfahrener Software-Entwickler und AI-Assistent. " .
                       "Deine Aufgabe ist es, präzise und umsetzbare Lösungen für Jira-Tickets zu erstellen. " .
                       "Analysiere das Problem gründlich und erstelle einen detaillierten Lösungsplan mit konkreten Schritten. " .
                       "Berücksichtige Best Practices, Sicherheit und Maintainability. " .
                       "Antworte strukturiert mit: Problem-Analyse, Lösungsansatz, Implementierungsschritte, und mögliche Risiken.";

            case 'code_execution':
                return "Du bist ein AI-Code-Generator. Erstelle funktionsfähigen, sauberen Code basierend auf der gegebenen Lösung. " .
                       "Berücksichtige die Projektstruktur, verwende die richtigen Namespaces und Imports. " .
                       "Erstelle vollständige Dateien mit allen notwendigen Funktionen. " .
                       "Dokumentiere den Code angemessen und folge den Coding-Standards des Projekts. " .
                       "Gib eine JSON-Antwort mit: files (Array von Dateien mit path und content), summary (Zusammenfassung der Änderungen).";

            default:
                return "Du bist ein hilfreicher AI-Assistent für Software-Entwicklung. " .
                       "Antworte präzise, strukturiert und technisch korrekt.";
        }
    }

    /**
     * Berechnet Confidence-Score basierend auf AI-Response
     */
    private function calculateConfidence(array $data, string $provider): float
    {
        $confidence = 0.5; // Base confidence

        switch ($provider) {
            case 'openai':
                $finishReason = $data['choices'][0]['finish_reason'] ?? '';
                if ($finishReason === 'stop') {
                    $confidence += 0.3;
                }
                
                $logprobs = $data['choices'][0]['logprobs'] ?? null;
                if ($logprobs) {
                    // Vereinfachte Logprob-Analyse
                    $confidence += 0.2;
                }
                break;

            case 'claude':
                $stopReason = $data['stop_reason'] ?? '';
                if ($stopReason === 'end_turn') {
                    $confidence += 0.3;
                }
                break;
        }

        return min(1.0, $confidence);
    }

    /**
     * Extrahiert Schritte aus der AI-Antwort
     */
    private function extractSteps(string $content): array
    {
        $steps = [];
        
        // Suche nach nummerierten Listen
        if (preg_match_all('/^\d+\.\s*(.+)$/m', $content, $matches)) {
            $steps = $matches[1];
        }
        
        // Suche nach Bullet Points
        if (empty($steps) && preg_match_all('/^[-*]\s*(.+)$/m', $content, $matches)) {
            $steps = $matches[1];
        }

        // Fallback: Teile nach Absätzen
        if (empty($steps)) {
            $paragraphs = explode("\n\n", $content);
            $steps = array_filter(array_map('trim', $paragraphs));
        }

        return array_values($steps);
    }

    /**
     * Erstellt Execution-Prompt für Code-Generierung
     */
    private function buildExecutionPrompt(TicketDTO $ticket, array $solution): string
    {
        $repoInfo = $ticket->getRepositoryInfo();
        $complexity = $ticket->estimateComplexity();
        $skills = $ticket->identifyRequiredSkills();

        $prompt = "# Code-Ausführung für Jira-Ticket\n\n";
        $prompt .= "## Ticket-Information:\n";
        $prompt .= "- **Key:** {$ticket->key}\n";
        $prompt .= "- **Titel:** {$ticket->summary}\n";
        $prompt .= "- **Beschreibung:** {$ticket->description}\n";
        $prompt .= "- **Komplexität:** {$complexity}\n";
        $prompt .= "- **Benötigte Skills:** " . implode(', ', $skills) . "\n\n";

        if ($repoInfo) {
            $prompt .= "## Repository-Information:\n";
            $prompt .= "- **Repository:** {$repoInfo['full_name']}\n";
            $prompt .= "- **URL:** {$repoInfo['url']}\n\n";
        }

        $prompt .= "## AI-Lösung:\n";
        $prompt .= $solution['solution'] . "\n\n";

        $prompt .= "## Aufgabe:\n";
        $prompt .= "Erstelle vollständigen, funktionsfähigen Code basierend auf der obigen Lösung. ";
        $prompt .= "Berücksichtige die Projektstruktur und verwende Laravel-Best-Practices. ";
        $prompt .= "Antworte mit JSON im folgenden Format:\n\n";
        $prompt .= "```json\n";
        $prompt .= "{\n";
        $prompt .= '  "files": [' . "\n";
        $prompt .= '    {' . "\n";
        $prompt .= '      "path": "relative/path/to/file.php",' . "\n";
        $prompt .= '      "content": "<?php\\n\\n// Vollständiger Dateiinhalt...",' . "\n";
        $prompt .= '      "action": "create|modify|delete"' . "\n";
        $prompt .= '    }' . "\n";
        $prompt .= '  ],' . "\n";
        $prompt .= '  "summary": "Zusammenfassung der Änderungen",' . "\n";
        $prompt .= '  "tests": ["Liste von Test-Dateien falls erstellt"]' . "\n";
        $prompt .= "}\n";
        $prompt .= "```";

        return $prompt;
    }

    /**
     * Verarbeitet Code-Änderungen von der AI
     */
    private function processCodeChanges(TicketDTO $ticket, array $executionResult): array
    {
        $solution = $executionResult['solution'] ?? '';
        
        // Versuche JSON aus der Antwort zu extrahieren
        if (preg_match('/```json\s*(\{.*?\})\s*```/s', $solution, $matches)) {
            $jsonData = json_decode($matches[1], true);
            
            if ($jsonData && isset($jsonData['files'])) {
                return [
                    'files' => $jsonData['files'],
                    'summary' => $jsonData['summary'] ?? 'AI-generierte Änderungen',
                    'tests' => $jsonData['tests'] ?? [],
                    'branch' => $ticket->generateBranchName(),
                    'commit_message' => "[{$ticket->key}] " . ($jsonData['summary'] ?? $ticket->summary)
                ];
            }
        }

        // Fallback: Einfache Parsing-Logik
        return [
            'files' => [
                [
                    'path' => 'generated_solution.php',
                    'content' => $solution,
                    'action' => 'create'
                ]
            ],
            'summary' => 'AI-generierte Lösung für ' . $ticket->key,
            'tests' => [],
            'branch' => $ticket->generateBranchName(),
            'commit_message' => "[{$ticket->key}] {$ticket->summary}"
        ];
    }

    /**
     * Testet die Verbindung zu den AI-Providern
     */
    public function testConnections(): array
    {
        $results = [];

        // Teste OpenAI
        try {
            $openaiResult = $this->callAIProvider('openai', 'Test prompt: Antworte nur mit "OK"', 'test');
            $results['openai'] = [
                'success' => $openaiResult['success'],
                'message' => $openaiResult['success'] ? 'OpenAI verbunden' : $openaiResult['error'],
                'response_time_ms' => $openaiResult['response_time_ms'] ?? 0
            ];
        } catch (Exception $e) {
            $results['openai'] = [
                'success' => false,
                'message' => 'OpenAI-Test fehlgeschlagen: ' . $e->getMessage()
            ];
        }

        // Teste Claude
        try {
            $claudeResult = $this->callAIProvider('claude', 'Test prompt: Antworte nur mit "OK"', 'test');
            $results['claude'] = [
                'success' => $claudeResult['success'],
                'message' => $claudeResult['success'] ? 'Claude verbunden' : $claudeResult['error'],
                'response_time_ms' => $claudeResult['response_time_ms'] ?? 0
            ];
        } catch (Exception $e) {
            $results['claude'] = [
                'success' => false,
                'message' => 'Claude-Test fehlgeschlagen: ' . $e->getMessage()
            ];
        }

        return $results;
    }
} 