<?php

namespace App\DTOs;

use Carbon\Carbon;

class TicketDTO
{
    public function __construct(
        public readonly string $key,
        public readonly string $summary,
        public readonly string $description,
        public readonly string $status,
        public readonly string $priority,
        public readonly ?string $assignee,
        public readonly string $reporter,
        public readonly Carbon $created,
        public readonly Carbon $updated,
        public readonly array $labels,
        public readonly ?string $repositoryUrl
    ) {}

    /**
     * Überprüft ob das Ticket das erforderliche Label hat
     */
    public function hasRequiredLabel(string $requiredLabel): bool
    {
        return in_array($requiredLabel, $this->labels);
    }

    /**
     * Überprüft ob das Ticket unassigned ist
     */
    public function isUnassigned(): bool
    {
        return empty($this->assignee);
    }

    /**
     * Überprüft ob das Ticket ein verknüpftes Repository hat
     */
    public function hasLinkedRepository(): bool
    {
        return !empty($this->repositoryUrl);
    }

    /**
     * Extrahiert Repository-Owner und -Name aus der URL
     */
    public function getRepositoryInfo(): ?array
    {
        if (!$this->repositoryUrl) {
            return null;
        }

        // GitHub URL Pattern: https://github.com/owner/repo
        if (preg_match('/github\.com\/([^\/]+)\/([^\/\s]+)/', $this->repositoryUrl, $matches)) {
            return [
                'owner' => $matches[1],
                'name' => $matches[2],
                'full_name' => $matches[1] . '/' . $matches[2],
                'url' => $this->repositoryUrl
            ];
        }

        return null;
    }

    /**
     * Generiert einen Branch-Namen basierend auf dem Ticket
     */
    public function generateBranchName(): string
    {
        $branchName = 'feature/' . strtolower($this->key);
        
        // Füge einen sauberen Summary-Teil hinzu
        $cleanSummary = preg_replace('/[^a-zA-Z0-9\s]/', '', $this->summary);
        $cleanSummary = preg_replace('/\s+/', '-', trim($cleanSummary));
        $cleanSummary = strtolower(substr($cleanSummary, 0, 30));
        
        if ($cleanSummary) {
            $branchName .= '-' . $cleanSummary;
        }

        return $branchName;
    }

    /**
     * Generiert einen PR-Titel basierend auf dem Ticket
     */
    public function generatePRTitle(): string
    {
        return "[{$this->key}] {$this->summary}";
    }

    /**
     * Generiert eine PR-Beschreibung basierend auf dem Ticket
     */
    public function generatePRDescription(): string
    {
        $description = "## Automatische Lösung für Jira-Ticket\n\n";
        $description .= "**Ticket:** [{$this->key}]({$this->getJiraUrl()})\n";
        $description .= "**Titel:** {$this->summary}\n\n";
        
        if ($this->description) {
            $description .= "### Beschreibung:\n";
            $description .= $this->description . "\n\n";
        }

        $description .= "### Details:\n";
        $description .= "- **Status:** {$this->status}\n";
        $description .= "- **Priorität:** {$this->priority}\n";
        $description .= "- **Reporter:** {$this->reporter}\n";
        
        if (!empty($this->labels)) {
            $description .= "- **Labels:** " . implode(', ', $this->labels) . "\n";
        }

        $description .= "\n---\n";
        $description .= "*Diese PR wurde automatisch vom Octomind Bot erstellt.*";

        return $description;
    }

    /**
     * Generiert die Jira-URL für das Ticket
     */
    public function getJiraUrl(): string
    {
        // Diese URL wird vom ConfigService bereitgestellt
        $baseUrl = config('services.jira.base_url', env('JIRA_BASE_URL'));
        return rtrim($baseUrl, '/') . '/browse/' . $this->key;
    }

    /**
     * Schätzt die Komplexität des Tickets basierend auf verschiedenen Faktoren
     */
    public function estimateComplexity(): string
    {
        $score = 0;

        // Basierend auf Beschreibungslänge
        $descriptionLength = strlen($this->description);
        if ($descriptionLength > 1000) {
            $score += 3;
        } elseif ($descriptionLength > 500) {
            $score += 2;
        } elseif ($descriptionLength > 100) {
            $score += 1;
        }

        // Basierend auf Priorität
        switch (strtolower($this->priority)) {
            case 'critical':
            case 'highest':
                $score += 3;
                break;
            case 'high':
                $score += 2;
                break;
            case 'medium':
                $score += 1;
                break;
            case 'low':
            case 'lowest':
                $score += 0;
                break;
        }

        // Basierend auf Keywords in Titel/Beschreibung
        $content = strtolower($this->summary . ' ' . $this->description);
        $complexKeywords = ['refactor', 'architecture', 'migration', 'database', 'security', 'performance'];
        $simpleKeywords = ['fix', 'update', 'change', 'add', 'remove'];

        foreach ($complexKeywords as $keyword) {
            if (strpos($content, $keyword) !== false) {
                $score += 2;
            }
        }

        foreach ($simpleKeywords as $keyword) {
            if (strpos($content, $keyword) !== false) {
                $score += 1;
            }
        }

        // Komplexitätsbewertung
        if ($score >= 8) {
            return 'very_high';
        } elseif ($score >= 6) {
            return 'high';
        } elseif ($score >= 4) {
            return 'medium';
        } elseif ($score >= 2) {
            return 'low';
        } else {
            return 'very_low';
        }
    }

    /**
     * Identifiziert die wahrscheinlich benötigten Technologien/Skills
     */
    public function identifyRequiredSkills(): array
    {
        $content = strtolower($this->summary . ' ' . $this->description);
        $skills = [];

        // Programming Languages
        $languages = [
            'php' => ['php', 'laravel', 'symfony', 'composer'],
            'javascript' => ['javascript', 'js', 'node', 'npm', 'yarn'],
            'typescript' => ['typescript', 'ts'],
            'python' => ['python', 'django', 'flask', 'pip'],
            'java' => ['java', 'spring', 'maven', 'gradle'],
            'csharp' => ['c#', 'csharp', '.net', 'dotnet'],
            'go' => ['golang', 'go'],
            'rust' => ['rust', 'cargo'],
        ];

        foreach ($languages as $language => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($content, $keyword) !== false) {
                    $skills[] = $language;
                    break;
                }
            }
        }

        // Frameworks & Technologies
        $technologies = [
            'database' => ['database', 'mysql', 'postgresql', 'sqlite', 'mongodb', 'redis'],
            'frontend' => ['react', 'vue', 'angular', 'html', 'css', 'scss', 'tailwind'],
            'api' => ['api', 'rest', 'graphql', 'endpoint'],
            'docker' => ['docker', 'container', 'kubernetes'],
            'testing' => ['test', 'testing', 'phpunit', 'jest', 'cypress'],
            'security' => ['security', 'auth', 'authentication', 'authorization'],
        ];

        foreach ($technologies as $tech => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($content, $keyword) !== false) {
                    $skills[] = $tech;
                    break;
                }
            }
        }

        return array_unique($skills);
    }

    /**
     * Konvertiert das DTO zu einem Array
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'summary' => $this->summary,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'assignee' => $this->assignee,
            'reporter' => $this->reporter,
            'created' => $this->created->toISOString(),
            'updated' => $this->updated->toISOString(),
            'labels' => $this->labels,
            'repository_url' => $this->repositoryUrl,
            'repository_info' => $this->getRepositoryInfo(),
            'complexity' => $this->estimateComplexity(),
            'required_skills' => $this->identifyRequiredSkills(),
            'branch_name' => $this->generateBranchName(),
            'pr_title' => $this->generatePRTitle(),
            'jira_url' => $this->getJiraUrl(),
        ];
    }

    /**
     * Erstellt eine JSON-Repräsentation
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
} 