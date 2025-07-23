<?php

namespace App\Services;

use App\DTOs\TicketDTO;

class PromptBuilderService
{
    private ConfigService $config;
    private LogService $logger;

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
    }

    /**
     * Erstellt einen detaillierten Prompt für die AI basierend auf Ticket und Analyse
     */
    public function buildPrompt(TicketDTO $ticket, array $analysis): string
    {
        $this->logger->debug('Erstelle AI-Prompt für Ticket', [
            'ticket_key' => $ticket->key,
            'complexity' => $analysis['complexity'] ?? 'unknown',
            'required_skills' => $analysis['required_skills'] ?? []
        ]);

        $prompt = $this->buildBasePrompt();
        $prompt .= $this->buildTicketContext($ticket);
        $prompt .= $this->buildAnalysisContext($analysis);
        $prompt .= $this->buildRepositoryContext($ticket);
        $prompt .= $this->buildConstraints();
        $prompt .= $this->buildExpectedOutput();

        $this->logger->debug('Prompt erfolgreich erstellt', [
            'ticket_key' => $ticket->key,
            'prompt_length' => strlen($prompt),
            'sections' => ['base', 'ticket', 'analysis', 'repository', 'constraints', 'output']
        ]);

        return $prompt;
    }

    /**
     * Erstellt einen spezialisierten Prompt für Code-Review
     */
    public function buildCodeReviewPrompt(TicketDTO $ticket, array $codeChanges): string
    {
        $prompt = "# Code-Review für Jira-Ticket\n\n";
        $prompt .= "Du bist ein erfahrener Senior-Entwickler und führst ein Code-Review durch.\n\n";
        
        $prompt .= "## Ticket-Information:\n";
        $prompt .= "- **Key:** {$ticket->key}\n";
        $prompt .= "- **Titel:** {$ticket->summary}\n";
        $prompt .= "- **Beschreibung:** {$ticket->description}\n\n";

        $prompt .= "## Code-Änderungen:\n";
        $files = isset($codeChanges['files']) ? $codeChanges['files'] : [];
        foreach ($files as $file) {
            $prompt .= "### {$file['path']} ({$file['action']})\n";
            $prompt .= "```php\n{$file['content']}\n```\n\n";
        }

        $prompt .= "## Aufgabe:\n";
        $prompt .= "Führe ein detailliertes Code-Review durch und bewerte:\n";
        $prompt .= "1. **Funktionalität:** Löst der Code das Problem?\n";
        $prompt .= "2. **Code-Qualität:** Ist der Code sauber und gut strukturiert?\n";
        $prompt .= "3. **Sicherheit:** Gibt es Sicherheitsprobleme?\n";
        $prompt .= "4. **Performance:** Ist der Code performant?\n";
        $prompt .= "5. **Best Practices:** Werden Laravel/PHP Best Practices befolgt?\n";
        $prompt .= "6. **Tests:** Sind Tests erforderlich?\n\n";

        $prompt .= "Antworte mit einer strukturierten Bewertung und konkreten Verbesserungsvorschlägen.";

        return $prompt;
    }

    /**
     * Erstellt einen Prompt für Self-Healing bei Fehlern
     */
    public function buildSelfHealingPrompt(TicketDTO $ticket, array $error, array $previousAttempts): string
    {
        $prompt = "# Self-Healing für fehlgeschlagene Ticket-Verarbeitung\n\n";
        $prompt .= "Du bist ein AI-Debugging-Experte. Eine automatische Ticket-Verarbeitung ist fehlgeschlagen.\n\n";

        $prompt .= "## Ticket-Information:\n";
        $prompt .= "- **Key:** {$ticket->key}\n";
        $prompt .= "- **Titel:** {$ticket->summary}\n";
        $prompt .= "- **Beschreibung:** {$ticket->description}\n\n";

        $prompt .= "## Fehler-Information:\n";
        $prompt .= "- **Fehler:** " . (isset($error['message']) ? $error['message'] : 'Unknown error') . "\n";
        $prompt .= "- **Typ:** " . (isset($error['type']) ? $error['type'] : 'Unknown') . "\n";
        $prompt .= "- **Stack Trace:** " . (isset($error['trace']) ? $error['trace'] : 'Not available') . "\n\n";

        $prompt .= "## Vorherige Versuche:\n";
        foreach ($previousAttempts as $attempt) {
            $errorMsg = isset($attempt['error']) ? $attempt['error'] : 'Failed';
            $prompt .= "- **Versuch {$attempt['attempt']}:** {$errorMsg}\n";
        }

        $prompt .= "\n## Aufgabe:\n";
        $prompt .= "1. Analysiere den Fehler und identifiziere die Ursache\n";
        $prompt .= "2. Schlage eine alternative Lösungsstrategie vor\n";
        $prompt .= "3. Erstelle einen neuen, korrigierten Lösungsansatz\n";
        $prompt .= "4. Berücksichtige die vorherigen fehlgeschlagenen Versuche\n\n";

        $prompt .= "Antworte mit einer detaillierten Fehleranalyse und einem neuen Lösungsplan.";

        return $prompt;
    }

    /**
     * Erstellt einen Prompt für Test-Generierung
     */
    public function buildTestGenerationPrompt(TicketDTO $ticket, array $codeChanges): string
    {
        $prompt = "# Test-Generierung für Jira-Ticket\n\n";
        $prompt .= "Du bist ein Test-Spezialist und erstellst umfassende Tests für neue Features.\n\n";

        $prompt .= "## Ticket-Information:\n";
        $prompt .= "- **Key:** {$ticket->key}\n";
        $prompt .= "- **Titel:** {$ticket->summary}\n";
        $prompt .= "- **Beschreibung:** {$ticket->description}\n\n";

        $prompt .= "## Implementierte Änderungen:\n";
        $summary = isset($codeChanges['summary']) ? $codeChanges['summary'] : 'Code changes';
        $prompt .= "- **Summary:** {$summary}\n";
        $files = isset($codeChanges['files']) ? $codeChanges['files'] : [];
        $prompt .= "- **Dateien:** " . implode(', ', array_column($files, 'path')) . "\n\n";

        $prompt .= "## Aufgabe:\n";
        $prompt .= "Erstelle umfassende PHPUnit-Tests für die implementierten Änderungen:\n";
        $prompt .= "1. **Unit Tests:** Teste einzelne Funktionen/Methoden\n";
        $prompt .= "2. **Integration Tests:** Teste Zusammenspiel der Komponenten\n";
        $prompt .= "3. **Feature Tests:** Teste das gesamte Feature\n";
        $prompt .= "4. **Edge Cases:** Teste Grenzfälle und Fehlerbedingungen\n\n";

        $prompt .= "Verwende Laravel-Testing-Best-Practices und erstelle vollständige Test-Dateien.";

        return $prompt;
    }

    /**
     * Erstellt den Basis-Prompt mit allgemeinen Anweisungen
     */
    private function buildBasePrompt(): string
    {
        $prompt = "# Jira-Ticket Automatisierung\n\n";
        $prompt .= "Du bist ein hochqualifizierter Senior Software Engineer mit Expertise in:\n";
        $prompt .= "- PHP/Laravel Development\n";
        $prompt .= "- Clean Code Principles\n";
        $prompt .= "- Software Architecture\n";
        $prompt .= "- Database Design\n";
        $prompt .= "- API Development\n";
        $prompt .= "- Testing & Quality Assurance\n";
        $prompt .= "- Security Best Practices\n\n";

        $prompt .= "Deine Aufgabe ist es, Jira-Tickets zu analysieren und vollständige, ";
        $prompt .= "produktionsreife Lösungen zu erstellen.\n\n";

        return $prompt;
    }

    /**
     * Fügt Ticket-Kontext zum Prompt hinzu
     */
    private function buildTicketContext(TicketDTO $ticket): string
    {
        $prompt = "## 📋 Ticket-Information\n\n";
        $prompt .= "**Ticket-Key:** {$ticket->key}\n";
        $prompt .= "**Titel:** {$ticket->summary}\n";
        $prompt .= "**Status:** {$ticket->status}\n";
        $prompt .= "**Priorität:** {$ticket->priority}\n";
        $prompt .= "**Reporter:** {$ticket->reporter}\n";

        if ($ticket->assignee) {
            $prompt .= "**Assignee:** {$ticket->assignee}\n";
        }

        if (!empty($ticket->labels)) {
            $prompt .= "**Labels:** " . implode(', ', $ticket->labels) . "\n";
        }

        $prompt .= "**Erstellt:** {$ticket->created->format('Y-m-d H:i:s')}\n";
        $prompt .= "**Aktualisiert:** {$ticket->updated->format('Y-m-d H:i:s')}\n\n";

        $prompt .= "### Beschreibung:\n";
        $prompt .= $ticket->description . "\n\n";

        $prompt .= "**Jira-URL:** {$ticket->getJiraUrl()}\n\n";

        return $prompt;
    }

    /**
     * Fügt Analyse-Kontext zum Prompt hinzu
     */
    private function buildAnalysisContext(array $analysis): string
    {
        $prompt = "## 🔍 Ticket-Analyse\n\n";
        
        if (isset($analysis['complexity'])) {
            $prompt .= "**Geschätzte Komplexität:** {$analysis['complexity']}\n";
        }

        if (isset($analysis['estimated_time'])) {
            $prompt .= "**Geschätzte Zeit:** {$analysis['estimated_time']} Minuten\n";
        }

        if (isset($analysis['required_skills']) && !empty($analysis['required_skills'])) {
            $prompt .= "**Benötigte Skills:** " . implode(', ', $analysis['required_skills']) . "\n";
        }

        if (isset($analysis['dependencies']) && !empty($analysis['dependencies'])) {
            $prompt .= "**Abhängigkeiten:** " . implode(', ', $analysis['dependencies']) . "\n";
        }

        if (isset($analysis['risks']) && !empty($analysis['risks'])) {
            $prompt .= "**Identifizierte Risiken:**\n";
            foreach ($analysis['risks'] as $risk) {
                $prompt .= "- {$risk}\n";
            }
        }

        $prompt .= "\n";

        return $prompt;
    }

    /**
     * Fügt Repository-Kontext zum Prompt hinzu
     */
    private function buildRepositoryContext(TicketDTO $ticket): string
    {
        $repoInfo = $ticket->getRepositoryInfo();
        
        if (!$repoInfo) {
            return "## ⚠️ Repository-Information\n\nKein Repository verknüpft.\n\n";
        }

        $prompt = "## 🗂️ Repository-Information\n\n";
        $prompt .= "**Repository:** {$repoInfo['full_name']}\n";
        $prompt .= "**URL:** {$repoInfo['url']}\n";
        $prompt .= "**Owner:** {$repoInfo['owner']}\n";
        $prompt .= "**Name:** {$repoInfo['name']}\n\n";

        // Zusätzliche Repository-Informationen könnten hier hinzugefügt werden
        // z.B. durch GitHub API-Calls um README, Package.json, Composer.json zu holen

        return $prompt;
    }

    /**
     * Fügt Constraints und Sicherheitsregeln zum Prompt hinzu
     */
    private function buildConstraints(): string
    {
        $securityConfig = $this->config->get('security', []);
        
        $prompt = "## 🔒 Sicherheits- und Qualitätsrichtlinien\n\n";
        
        $prompt .= "### Erlaubte Dateierweiterungen:\n";
        $allowedExtensions = $securityConfig['allowed_file_extensions'] ?? ['php', 'js', 'vue', 'json'];
        foreach ($allowedExtensions as $ext) {
            $prompt .= "- .{$ext}\n";
        }

        $prompt .= "\n### Verbotene Pfade:\n";
        $forbiddenPaths = $securityConfig['forbidden_paths'] ?? ['.env', '.git', 'vendor', 'node_modules'];
        foreach ($forbiddenPaths as $path) {
            $prompt .= "- {$path}\n";
        }

        $prompt .= "\n### Gefährliche Operationen (VERBOTEN):\n";
        $dangerousOps = $securityConfig['dangerous_operations'] ?? ['delete', 'truncate', 'drop'];
        foreach ($dangerousOps as $op) {
            $prompt .= "- {$op}\n";
        }

        $prompt .= "\n### Code-Qualitätsstandards:\n";
        $prompt .= "- Verwende PSR-12 Coding Standards\n";
        $prompt .= "- Schreibe selbstdokumentierenden Code\n";
        $prompt .= "- Implementiere proper Error Handling\n";
        $prompt .= "- Verwende Type Hints wo möglich\n";
        $prompt .= "- Folge SOLID Principles\n";
        $prompt .= "- Keine hardcoded Werte - verwende Config/Env\n";
        $prompt .= "- Implementiere Logging für wichtige Operationen\n\n";

        return $prompt;
    }

    /**
     * Definiert das erwartete Output-Format
     */
    private function buildExpectedOutput(): string
    {
        $prompt = "## 📤 Erwartetes Output-Format\n\n";
        $prompt .= "Erstelle eine strukturierte Antwort mit folgenden Abschnitten:\n\n";

        $prompt .= "### 1. Problem-Analyse\n";
        $prompt .= "- Kurze Zusammenfassung des Problems\n";
        $prompt .= "- Identifizierte Anforderungen\n";
        $prompt .= "- Betroffene System-Komponenten\n\n";

        $prompt .= "### 2. Lösungsansatz\n";
        $prompt .= "- Gewählte Lösungsstrategie\n";
        $prompt .= "- Architektur-Entscheidungen\n";
        $prompt .= "- Verwendete Design Patterns\n\n";

        $prompt .= "### 3. Implementierungsplan\n";
        $prompt .= "- Schritt-für-Schritt Anleitung\n";
        $prompt .= "- Reihenfolge der Implementierung\n";
        $prompt .= "- Abhängigkeiten zwischen Schritten\n\n";

        $prompt .= "### 4. Code-Struktur\n";
        $prompt .= "- Neue Dateien die erstellt werden\n";
        $prompt .= "- Bestehende Dateien die geändert werden\n";
        $prompt .= "- Database-Änderungen (Migrations)\n\n";

        $prompt .= "### 5. Testing-Strategie\n";
        $prompt .= "- Unit Tests\n";
        $prompt .= "- Integration Tests\n";
        $prompt .= "- Manuelle Test-Szenarien\n\n";

        $prompt .= "### 6. Risiken & Überlegungen\n";
        $prompt .= "- Potenzielle Probleme\n";
        $prompt .= "- Performance-Auswirkungen\n";
        $prompt .= "- Sicherheits-Überlegungen\n";
        $prompt .= "- Rollback-Strategie\n\n";

        $prompt .= "Sei präzise, detailliert und praxisorientiert. ";
        $prompt .= "Die Lösung soll direkt umsetzbar sein.\n\n";

        return $prompt;
    }

    /**
     * Erstellt einen Prompt für spezifische Technologie-Stacks
     */
    public function buildTechStackPrompt(array $requiredSkills): string
    {
        $prompt = "## 🛠️ Technologie-Stack Kontext\n\n";
        
        foreach ($requiredSkills as $skill) {
            switch ($skill) {
                case 'php':
                    $prompt .= "**PHP/Laravel:**\n";
                    $prompt .= "- Verwende Laravel 11.x Features\n";
                    $prompt .= "- Nutze Eloquent ORM für Database-Operationen\n";
                    $prompt .= "- Implementiere Service Layer Pattern\n";
                    $prompt .= "- Verwende Laravel Validation\n\n";
                    break;

                case 'javascript':
                    $prompt .= "**JavaScript/Frontend:**\n";
                    $prompt .= "- Verwende moderne ES6+ Syntax\n";
                    $prompt .= "- Implementiere proper Error Handling\n";
                    $prompt .= "- Nutze async/await für asynchrone Operationen\n\n";
                    break;

                case 'database':
                    $prompt .= "**Database:**\n";
                    $prompt .= "- Erstelle Laravel Migrations\n";
                    $prompt .= "- Verwende proper Indexing\n";
                    $prompt .= "- Implementiere Foreign Key Constraints\n";
                    $prompt .= "- Nutze Database Transactions wo nötig\n\n";
                    break;

                case 'api':
                    $prompt .= "**API Development:**\n";
                    $prompt .= "- Implementiere RESTful API Principles\n";
                    $prompt .= "- Verwende Laravel API Resources\n";
                    $prompt .= "- Implementiere proper HTTP Status Codes\n";
                    $prompt .= "- Nutze API Rate Limiting\n\n";
                    break;
            }
        }

        return $prompt;
    }

    /**
     * Validiert und optimiert einen generierten Prompt
     */
    public function validatePrompt(string $prompt): array
    {
        $issues = [];
        $suggestions = [];

        // Längen-Validierung
        $length = strlen($prompt);
        $maxLength = $this->config->get('ai.max_tokens', 4096) * 4; // Grobe Schätzung: 4 chars per token

        if ($length > $maxLength) {
            $issues[] = "Prompt zu lang ({$length} chars, max {$maxLength})";
            $suggestions[] = "Kürze die Beschreibung oder teile in mehrere Prompts auf";
        }

        // Struktur-Validierung
        $requiredSections = ['Ticket-Information', 'Aufgabe', 'Output-Format'];
        foreach ($requiredSections as $section) {
            if (strpos($prompt, $section) === false) {
                $issues[] = "Fehlender Abschnitt: {$section}";
                $suggestions[] = "Füge den Abschnitt '{$section}' hinzu";
            }
        }

        // Qualitäts-Checks
        if (substr_count($prompt, '?') < 3) {
            $suggestions[] = "Füge mehr spezifische Fragen hinzu um bessere AI-Antworten zu erhalten";
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'suggestions' => $suggestions,
            'length' => $length,
            'estimated_tokens' => intval($length / 4)
        ];
    }
} 