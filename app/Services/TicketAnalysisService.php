<?php

namespace App\Services;

use App\DTOs\TicketDTO;
use App\Models\Ticket;
use App\Models\TicketTodo;
use Exception;

class TicketAnalysisService
{
    private CloudAIService $aiService;
    private LogService $logger;
    private ConfigService $config;

    public function __construct()
    {
        $this->aiService = new CloudAIService();
        $this->logger = new LogService();
        $this->config = ConfigService::getInstance();
    }

    /**
     * Analysiert Ticket-Komplexität und erstellt automatisch TODOs bei Bedarf
     */
    public function analyzeAndCreateTodos(TicketDTO $ticket): array
    {
        $this->logger->info('🧠 Starte intelligente Ticket-Analyse', [
            'ticket_key' => $ticket->key,
            'summary' => $ticket->summary
        ]);

        try {
            // 1. Basis-Komplexitätsanalyse
            $complexity = $this->calculateComplexityScore($ticket);
            
            // 2. AI-gestützte Analyse für komplexe Tickets
            $aiAnalysis = null;
            $todos = [];
            
            if ($complexity['level'] === 'high' || $complexity['level'] === 'very_high') {
                $this->logger->info('🚨 Komplexes Ticket erkannt - starte AI-Aufspaltung', [
                    'ticket_key' => $ticket->key,
                    'complexity_level' => $complexity['level'],
                    'complexity_score' => $complexity['score']
                ]);
                
                $aiAnalysis = $this->performAIAnalysis($ticket, $complexity);
                
                if ($aiAnalysis['success']) {
                    $todos = $this->createTodosFromAIAnalysis($ticket, $aiAnalysis);
                }
            }

            // 3. Fallback: Basis-TODOs für mittlere Komplexität
            if (empty($todos) && $complexity['level'] === 'medium') {
                $todos = $this->createBasicTodos($ticket, $complexity);
            }

            // 4. TODOs in Datenbank speichern
            if (!empty($todos)) {
                $this->storeTodos($ticket, $todos);
            }

            return [
                'success' => true,
                'complexity' => $complexity,
                'ai_analysis' => $aiAnalysis,
                'todos_created' => count($todos),
                'todos' => $todos,
                'requires_breakdown' => !empty($todos)
            ];

        } catch (Exception $e) {
            $this->logger->error('❌ Ticket-Analyse fehlgeschlagen', [
                'ticket_key' => $ticket->key,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'complexity' => $complexity ?? ['level' => 'unknown', 'score' => 0],
                'todos_created' => 0,
                'todos' => []
            ];
        }
    }

    /**
     * Berechnet detaillierten Komplexitäts-Score
     */
    private function calculateComplexityScore(TicketDTO $ticket): array
    {
        $score = 0;
        $factors = [];

        // 1. Beschreibungslänge (0-5 Punkte)
        $descriptionLength = strlen($ticket->description);
        if ($descriptionLength > 2000) {
            $score += 5;
            $factors[] = 'Sehr lange Beschreibung (>2000 Zeichen)';
        } elseif ($descriptionLength > 1000) {
            $score += 3;
            $factors[] = 'Lange Beschreibung (>1000 Zeichen)';
        } elseif ($descriptionLength > 500) {
            $score += 2;
            $factors[] = 'Mittlere Beschreibung (>500 Zeichen)';
        }

        // 2. Priorität (0-4 Punkte)
        switch (strtolower($ticket->priority)) {
            case 'critical':
            case 'highest':
                $score += 4;
                $factors[] = 'Kritische Priorität';
                break;
            case 'high':
                $score += 3;
                $factors[] = 'Hohe Priorität';
                break;
            case 'medium':
                $score += 2;
                $factors[] = 'Mittlere Priorität';
                break;
        }

        // 3. Komplexe Keywords (je 2-3 Punkte)
        $content = strtolower($ticket->summary . ' ' . $ticket->description);
        
        $architectureKeywords = ['architecture', 'refactor', 'redesign', 'restructure', 'framework'];
        $systemKeywords = ['system', 'integration', 'api', 'database', 'migration', 'deployment'];
        $securityKeywords = ['security', 'authentication', 'authorization', 'encryption', 'vulnerability'];
        $performanceKeywords = ['performance', 'optimization', 'scaling', 'caching', 'load'];
        
        foreach ($architectureKeywords as $keyword) {
            if (strpos($content, $keyword) !== false) {
                $score += 3;
                $factors[] = "Architektur-Keyword: {$keyword}";
            }
        }
        
        foreach ($systemKeywords as $keyword) {
            if (strpos($content, $keyword) !== false) {
                $score += 2;
                $factors[] = "System-Keyword: {$keyword}";
            }
        }
        
        foreach (array_merge($securityKeywords, $performanceKeywords) as $keyword) {
            if (strpos($content, $keyword) !== false) {
                $score += 2;
                $factors[] = "Spezialisiert-Keyword: {$keyword}";
            }
        }

        // 4. Multiple Funktionalitäten (je 1 Punkt)
        $functionalityIndicators = ['and', 'also', 'additionally', 'furthermore', 'plus', 'including'];
        foreach ($functionalityIndicators as $indicator) {
            if (substr_count($content, $indicator) > 0) {
                $score += substr_count($content, $indicator);
                $factors[] = "Multiple Funktionalitäten erkannt ({$indicator})";
            }
        }

        // 5. Listen und Aufzählungen (je 1 Punkt)
        $listCount = substr_count($ticket->description, '-') + substr_count($ticket->description, '*') + 
                    substr_count($ticket->description, '1.') + substr_count($ticket->description, '2.');
        if ($listCount > 3) {
            $score += min($listCount, 5);
            $factors[] = "Viele Aufzählungen/Listen ({$listCount})";
        }

        // Komplexitätslevel bestimmen
        if ($score >= 15) {
            $level = 'very_high';
        } elseif ($score >= 10) {
            $level = 'high';
        } elseif ($score >= 6) {
            $level = 'medium';
        } elseif ($score >= 3) {
            $level = 'low';
        } else {
            $level = 'very_low';
        }

        return [
            'score' => $score,
            'level' => $level,
            'factors' => $factors,
            'description_length' => $descriptionLength
        ];
    }

    /**
     * Führt AI-gestützte Analyse durch
     */
    private function performAIAnalysis(TicketDTO $ticket, array $complexity): array
    {
        $prompt = $this->buildAIAnalysisPrompt($ticket, $complexity);
        
        $this->logger->debug('🤖 Sende Ticket zur AI-Analyse', [
            'ticket_key' => $ticket->key,
            'prompt_length' => strlen($prompt)
        ]);

        try {
            $result = $this->aiService->generateSolution($prompt);
            
            if ($result['success']) {
                // Parse AI-Response für strukturierte TODOs
                $parsedTodos = $this->parseAIResponse($result['solution']);
                
                return [
                    'success' => true,
                    'raw_response' => $result['solution'],
                    'parsed_todos' => $parsedTodos,
                    'provider_used' => $result['provider_used'] ?? 'unknown',
                    'confidence' => $result['confidence'] ?? 0.8
                ];
            }
            
            return [
                'success' => false,
                'error' => $result['error'] ?? 'AI-Analyse fehlgeschlagen'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Erstellt AI-Analyse-Prompt
     */
    private function buildAIAnalysisPrompt(TicketDTO $ticket, array $complexity): string
    {
        $prompt = "# 🎯 TICKET-AUFSPALTUNG FÜR KOMPLEXES JIRA-TICKET\n\n";
        
        $prompt .= "## 📋 TICKET-INFORMATIONEN:\n";
        $prompt .= "**Ticket-ID:** {$ticket->key}\n";
        $prompt .= "**Titel:** {$ticket->summary}\n";
        $prompt .= "**Priorität:** {$ticket->priority}\n";
        $prompt .= "**Komplexitäts-Score:** {$complexity['score']} ({$complexity['level']})\n\n";
        
        $prompt .= "**Beschreibung:**\n{$ticket->description}\n\n";
        
        $prompt .= "## 🧠 DEINE AUFGABE:\n";
        $prompt .= "Analysiere dieses komplexe Ticket und spalte es in **3-8 konkrete, umsetzbare TODOs** auf.\n\n";
        
        $prompt .= "## 📝 ANFORDERUNGEN:\n";
        $prompt .= "- Jedes TODO soll **spezifisch und technisch umsetzbar** sein\n";
        $prompt .= "- TODOs sollen **logisch aufeinander aufbauen**\n";
        $prompt .= "- Schätze **realistische Zeitaufwände** (15min bis 4h pro TODO)\n";
        $prompt .= "- Identifiziere **Abhängigkeiten** zwischen TODOs\n";
        $prompt .= "- Priorisiere TODOs von **1 (höchste) bis 5 (niedrigste)**\n\n";
        
        $prompt .= "## 🎯 ANTWORT-FORMAT (JSON):\n";
        $prompt .= "```json\n";
        $prompt .= "{\n";
        $prompt .= '  "analysis": {' . "\n";
        $prompt .= '    "summary": "Kurze Zusammenfassung der Analyse",' . "\n";
        $prompt .= '    "main_components": ["Komponente 1", "Komponente 2", ...],' . "\n";
        $prompt .= '    "estimated_total_time_hours": 8.5,' . "\n";
        $prompt .= '    "risk_factors": ["Risiko 1", "Risiko 2", ...]' . "\n";
        $prompt .= '  },' . "\n";
        $prompt .= '  "todos": [' . "\n";
        $prompt .= '    {' . "\n";
        $prompt .= '      "title": "Spezifischer TODO-Titel",' . "\n";
        $prompt .= '      "description": "Detaillierte Beschreibung der Aufgabe",' . "\n";
        $prompt .= '      "priority": 1,' . "\n";
        $prompt .= '      "estimated_hours": 2.0,' . "\n";
        $prompt .= '      "category": "backend|frontend|database|testing|deployment",' . "\n";
        $prompt .= '      "dependencies": ["TODO-2", "TODO-3"],' . "\n";
        $prompt .= '      "acceptance_criteria": ["Kriterium 1", "Kriterium 2"]' . "\n";
        $prompt .= '    }' . "\n";
        $prompt .= '  ]' . "\n";
        $prompt .= "}\n";
        $prompt .= "```\n\n";
        
        $prompt .= "## 💡 BEISPIEL-KATEGORIEN:\n";
        $prompt .= "- **backend**: API-Entwicklung, Business-Logic, Services\n";
        $prompt .= "- **frontend**: UI-Komponenten, User-Interface, Styling\n";
        $prompt .= "- **database**: Schema-Änderungen, Migrations, Queries\n";
        $prompt .= "- **testing**: Unit-Tests, Integration-Tests, E2E-Tests\n";
        $prompt .= "- **deployment**: CI/CD, Konfiguration, Infrastructure\n\n";
        
        $prompt .= "Analysiere das Ticket jetzt und erstelle die strukturierten TODOs!";
        
        return $prompt;
    }

    /**
     * Parst AI-Response zu strukturierten TODOs
     */
    private function parseAIResponse(string $response): array
    {
        // Extrahiere JSON aus der Antwort
        if (preg_match('/```json\s*(\{.*?\})\s*```/s', $response, $matches)) {
            $jsonString = $matches[1];
        } else {
            // Fallback: Suche nach JSON-ähnlicher Struktur
            $jsonString = $response;
        }

        try {
            $data = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
            
            if (!isset($data['todos']) || !is_array($data['todos'])) {
                throw new Exception('Keine TODOs in AI-Response gefunden');
            }

            return $data;
            
        } catch (Exception $e) {
            $this->logger->warning('🚧 AI-Response konnte nicht geparst werden, verwende Fallback', [
                'error' => $e->getMessage(),
                'response_preview' => substr($response, 0, 200)
            ]);
            
            // Fallback: Erstelle einfache TODOs basierend auf Text
            return $this->createFallbackTodos($response);
        }
    }

    /**
     * Erstellt Fallback-TODOs wenn AI-Parsing fehlschlägt
     */
    private function createFallbackTodos(string $response): array
    {
        // Einfache Extraktion von Aufgaben aus dem Text
        $lines = explode("\n", $response);
        $todos = [];
        $priority = 1;

        foreach ($lines as $line) {
            $line = trim($line);
            
            // Suche nach Listen-Elementen
            if (preg_match('/^[-*]\s*(.+)/', $line, $matches) || 
                preg_match('/^\d+\.\s*(.+)/', $line, $matches)) {
                
                $title = trim($matches[1]);
                if (strlen($title) > 10) { // Nur sinnvolle Aufgaben
                    $todos[] = [
                        'title' => $title,
                        'description' => $title,
                        'priority' => min($priority, 5),
                        'estimated_hours' => 2.0,
                        'category' => 'backend',
                        'dependencies' => [],
                        'acceptance_criteria' => []
                    ];
                    $priority++;
                }
            }
        }

        return [
            'analysis' => [
                'summary' => 'Automatische Fallback-Analyse',
                'main_components' => ['Unbekannt'],
                'estimated_total_time_hours' => count($todos) * 2.0,
                'risk_factors' => ['AI-Parsing fehlgeschlagen']
            ],
            'todos' => array_slice($todos, 0, 6) // Max 6 TODOs
        ];
    }

    /**
     * Erstellt einfache TODOs für mittlere Komplexität
     */
    private function createBasicTodos(TicketDTO $ticket, array $complexity): array
    {
        $todos = [
            [
                'title' => 'Anforderungen analysieren und spezifizieren',
                'description' => 'Detaillierte Analyse der Ticket-Anforderungen und Erstellung einer technischen Spezifikation',
                'priority' => 1,
                'estimated_hours' => 1.0,
                'category' => 'planning',
                'dependencies' => [],
                'acceptance_criteria' => ['Spezifikation erstellt', 'Anforderungen geklärt']
            ],
            [
                'title' => 'Technische Implementierung',
                'description' => 'Hauptimplementierung basierend auf den analysierten Anforderungen',
                'priority' => 2,
                'estimated_hours' => 3.0,
                'category' => 'backend',
                'dependencies' => ['TODO-1'],
                'acceptance_criteria' => ['Code implementiert', 'Funktionalität getestet']
            ],
            [
                'title' => 'Tests und Qualitätssicherung',
                'description' => 'Erstellung und Ausführung von Tests zur Sicherstellung der Qualität',
                'priority' => 3,
                'estimated_hours' => 1.5,
                'category' => 'testing',
                'dependencies' => ['TODO-2'],
                'acceptance_criteria' => ['Tests erstellt', 'Alle Tests bestehen']
            ]
        ];

        return [
            'analysis' => [
                'summary' => 'Basis-Aufspaltung für mittlere Komplexität',
                'main_components' => ['Implementation', 'Testing'],
                'estimated_total_time_hours' => 5.5,
                'risk_factors' => []
            ],
            'todos' => $todos
        ];
    }

    /**
     * Erstellt TODOs aus AI-Analyse
     */
    private function createTodosFromAIAnalysis(TicketDTO $ticket, array $aiAnalysis): array
    {
        if (!isset($aiAnalysis['parsed_todos']['todos'])) {
            return [];
        }

        $todos = [];
        foreach ($aiAnalysis['parsed_todos']['todos'] as $index => $todo) {
            $todos[] = [
                'title' => $todo['title'] ?? "TODO #{$index}",
                'description' => $todo['description'] ?? '',
                'priority' => $todo['priority'] ?? 3,
                'estimated_hours' => $todo['estimated_hours'] ?? 2.0,
                'category' => $todo['category'] ?? 'backend',
                'dependencies' => $todo['dependencies'] ?? [],
                'acceptance_criteria' => $todo['acceptance_criteria'] ?? [],
                'ai_generated' => true
            ];
        }

        return $todos;
    }

    /**
     * Speichert TODOs in der Datenbank
     */
    private function storeTodos(TicketDTO $ticket, array $todos): void
    {
        $ticketModel = Ticket::where('jira_key', $ticket->key)->first();
        if (!$ticketModel) {
            throw new Exception("Ticket {$ticket->key} nicht in Datenbank gefunden");
        }

        // Lösche alte TODOs
        TicketTodo::where('ticket_id', $ticketModel->id)->delete();

        // Erstelle neue TODOs
        foreach ($todos as $index => $todo) {
            TicketTodo::create([
                'ticket_id' => $ticketModel->id,
                'title' => $todo['title'],
                'description' => $todo['description'],
                'priority' => $todo['priority'],
                'estimated_hours' => $todo['estimated_hours'],
                'category' => $todo['category'],
                'dependencies' => json_encode($todo['dependencies'] ?? []),
                'acceptance_criteria' => json_encode($todo['acceptance_criteria'] ?? []),
                'status' => 'pending',
                'order_index' => $index + 1,
                'ai_generated' => $todo['ai_generated'] ?? false
            ]);
        }

        $this->logger->info('✅ TODOs erfolgreich gespeichert', [
            'ticket_key' => $ticket->key,
            'todos_count' => count($todos)
        ]);
    }
} 