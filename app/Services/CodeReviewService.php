<?php

namespace App\Services;

use App\DTOs\TicketDTO;
use Exception;

class CodeReviewService
{
    private ConfigService $config;
    private LogService $logger;
    private CloudAIService $aiService;
    private PromptBuilderService $promptBuilder;

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
        $this->aiService = new CloudAIService();
        $this->promptBuilder = new PromptBuilderService();
    }

    /**
     * Führt automatisches Code-Review durch bevor Änderungen gepusht werden
     */
    public function performCodeReview(TicketDTO $ticket, array $changes): array
    {
        $this->logger->info('Starte automatisches Code-Review', [
            'ticket_key' => $ticket->key,
            'files_count' => count($changes['files'] ?? [])
        ]);

        try {
            // 1. Code-Analyse durchführen
            $codeAnalysis = $this->analyzeCodeChanges($changes);
            
            // 2. AI-basiertes Review
            $aiReview = $this->performAIReview($ticket, $changes);
            
            // 3. Automatische Tests durchführen
            $testResults = $this->runAutomatedTests($changes);
            
            // 4. Sicherheits-Checks
            $securityCheck = $this->performSecurityCheck($changes);
            
            // 5. Code-Quality-Metriken
            $qualityMetrics = $this->calculateQualityMetrics($changes);
            
            // 6. Gesamt-Bewertung
            $overallScore = $this->calculateOverallScore([
                'code_analysis' => $codeAnalysis,
                'ai_review' => $aiReview,
                'tests' => $testResults,
                'security' => $securityCheck,
                'quality' => $qualityMetrics
            ]);

            $reviewResult = [
                'success' => true,
                'ticket_key' => $ticket->key,
                'overall_score' => $overallScore,
                'approval_status' => $overallScore >= 0.7 ? 'approved' : 'needs_improvement',
                'reviews' => [
                    'code_analysis' => $codeAnalysis,
                    'ai_review' => $aiReview,
                    'tests' => $testResults,
                    'security' => $securityCheck,
                    'quality' => $qualityMetrics
                ],
                'recommendations' => $this->generateRecommendations($overallScore, [
                    $codeAnalysis, $aiReview, $testResults, $securityCheck, $qualityMetrics
                ]),
                'reviewed_at' => now()->toISOString()
            ];

            $this->logger->info('Code-Review abgeschlossen', [
                'ticket_key' => $ticket->key,
                'overall_score' => $overallScore,
                'approval_status' => $reviewResult['approval_status']
            ]);

            return $reviewResult;

        } catch (Exception $e) {
            $this->logger->error('Code-Review fehlgeschlagen', [
                'ticket_key' => $ticket->key,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'approval_status' => 'failed'
            ];
        }
    }

    /**
     * Analysiert Code-Änderungen
     */
    private function analyzeCodeChanges(array $changes): array
    {
        $analysis = [
            'files_analyzed' => 0,
            'lines_added' => 0,
            'lines_removed' => 0,
            'complexity_score' => 0,
            'issues' => [],
            'score' => 0.8 // Base score
        ];

        foreach ($changes['files'] ?? [] as $file) {
            $analysis['files_analyzed']++;
            
            // Datei-spezifische Analyse
            $fileAnalysis = $this->analyzeFile($file);
            
            $analysis['lines_added'] += $fileAnalysis['lines_added'];
            $analysis['lines_removed'] += $fileAnalysis['lines_removed'];
            $analysis['complexity_score'] += $fileAnalysis['complexity'];
            $analysis['issues'] = array_merge($analysis['issues'], $fileAnalysis['issues']);
        }

        // Score basierend auf Problemen anpassen
        if (count($analysis['issues']) > 0) {
            $analysis['score'] -= (count($analysis['issues']) * 0.1);
        }

        $analysis['score'] = max(0, min(1, $analysis['score']));

        return $analysis;
    }

    /**
     * Analysiert eine einzelne Datei
     */
    private function analyzeFile(array $file): array
    {
        $content = $file['content'] ?? '';
        $path = $file['path'] ?? '';
        
        $analysis = [
            'lines_added' => substr_count($content, "\n") + 1,
            'lines_removed' => 0, // Würde aus Git-Diff kommen
            'complexity' => 0,
            'issues' => []
        ];

        // PHP-spezifische Analyse
        if (str_ends_with($path, '.php')) {
            $analysis = array_merge($analysis, $this->analyzePHPFile($content, $path));
        }

        // JavaScript-spezifische Analyse
        if (str_ends_with($path, '.js') || str_ends_with($path, '.vue')) {
            $analysis = array_merge($analysis, $this->analyzeJavaScriptFile($content, $path));
        }

        return $analysis;
    }

    /**
     * Analysiert PHP-Dateien
     */
    private function analyzePHPFile(string $content, string $path): array
    {
        $issues = [];
        $complexity = 0;

        // Syntax-Check (vereinfacht)
        if (strpos($content, '<?php') === false && str_ends_with($path, '.php')) {
            $issues[] = [
                'type' => 'syntax',
                'severity' => 'error',
                'message' => 'PHP-Datei ohne <?php Tag',
                'file' => $path
            ];
        }

        // Security-Checks
        $dangerousFunctions = ['eval', 'exec', 'system', 'shell_exec', 'passthru'];
        foreach ($dangerousFunctions as $func) {
            if (strpos($content, $func . '(') !== false) {
                $issues[] = [
                    'type' => 'security',
                    'severity' => 'warning',
                    'message' => "Gefährliche Funktion '{$func}' verwendet",
                    'file' => $path
                ];
            }
        }

        // Komplexitäts-Berechnung (vereinfacht)
        $complexity += substr_count($content, 'if (');
        $complexity += substr_count($content, 'for (');
        $complexity += substr_count($content, 'while (');
        $complexity += substr_count($content, 'switch (');

        // Code-Quality-Checks
        if (strpos($content, 'var_dump') !== false || strpos($content, 'dd(') !== false) {
            $issues[] = [
                'type' => 'quality',
                'severity' => 'warning',
                'message' => 'Debug-Code gefunden (var_dump/dd)',
                'file' => $path
            ];
        }

        return [
            'complexity' => $complexity,
            'issues' => $issues
        ];
    }

    /**
     * Analysiert JavaScript/Vue-Dateien
     */
    private function analyzeJavaScriptFile(string $content, string $path): array
    {
        $issues = [];
        $complexity = 0;

        // Komplexitäts-Berechnung
        $complexity += substr_count($content, 'if (');
        $complexity += substr_count($content, 'for (');
        $complexity += substr_count($content, 'while (');

        // Console-Logs finden
        if (strpos($content, 'console.log') !== false) {
            $issues[] = [
                'type' => 'quality',
                'severity' => 'info',
                'message' => 'Console.log statements gefunden',
                'file' => $path
            ];
        }

        return [
            'complexity' => $complexity,
            'issues' => $issues
        ];
    }

    /**
     * Führt AI-basiertes Code-Review durch
     */
    private function performAIReview(TicketDTO $ticket, array $changes): array
    {
        try {
            $prompt = $this->promptBuilder->buildCodeReviewPrompt($ticket, $changes);
            $aiResult = $this->aiService->generateSolution($prompt);

            if ($aiResult['success']) {
                return [
                    'success' => true,
                    'score' => $aiResult['confidence'] ?? 0.7,
                    'review' => $aiResult['solution'],
                    'provider' => $aiResult['provider_used'] ?? 'unknown'
                ];
            } else {
                return [
                    'success' => false,
                    'score' => 0.5,
                    'error' => $aiResult['error'] ?? 'AI-Review fehlgeschlagen'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'score' => 0.5,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Führt automatische Tests durch
     */
    private function runAutomatedTests(array $changes): array
    {
        $testResult = [
            'success' => true,
            'tests_run' => 0,
            'tests_passed' => 0,
            'tests_failed' => 0,
            'score' => 0.8,
            'output' => ''
        ];

        try {
            // PHPUnit Tests
            if ($this->hasPhpTests()) {
                $phpunitResult = $this->runPhpUnitTests();
                $testResult = array_merge($testResult, $phpunitResult);
            }

            // JavaScript Tests (falls vorhanden)
            if ($this->hasJsTests()) {
                $jsTestResult = $this->runJavaScriptTests();
                // Merge JS test results
            }

            // Score basierend auf Test-Ergebnissen
            if ($testResult['tests_run'] > 0) {
                $testResult['score'] = $testResult['tests_passed'] / $testResult['tests_run'];
            }

        } catch (Exception $e) {
            $testResult['success'] = false;
            $testResult['error'] = $e->getMessage();
            $testResult['score'] = 0.3;
        }

        return $testResult;
    }

    /**
     * Prüft ob PHP-Tests vorhanden sind
     */
    private function hasPhpTests(): bool
    {
        return file_exists(base_path('phpunit.xml')) && 
               is_dir(base_path('tests'));
    }

    /**
     * Führt PHPUnit-Tests aus
     */
    private function runPhpUnitTests(): array
    {
        $command = 'cd ' . base_path() . ' && php artisan test --parallel';
        
        $output = [];
        $returnCode = 0;
        
        exec($command . ' 2>&1', $output, $returnCode);
        $outputString = implode("\n", $output);

        // Parse PHPUnit output (vereinfacht)
        $testsRun = 0;
        $testsPassed = 0;
        $testsFailed = 0;

        if (preg_match('/(\d+) tests?, (\d+) assertions?/', $outputString, $matches)) {
            $testsRun = (int)$matches[1];
            $testsPassed = $returnCode === 0 ? $testsRun : 0;
            $testsFailed = $testsRun - $testsPassed;
        }

        return [
            'tests_run' => $testsRun,
            'tests_passed' => $testsPassed,
            'tests_failed' => $testsFailed,
            'success' => $returnCode === 0,
            'output' => $outputString
        ];
    }

    /**
     * Prüft ob JavaScript-Tests vorhanden sind
     */
    private function hasJsTests(): bool
    {
        return file_exists(base_path('package.json'));
    }

    /**
     * Führt JavaScript-Tests aus
     */
    private function runJavaScriptTests(): array
    {
        // Implementierung für JS-Tests (Jest, Vitest, etc.)
        return [
            'tests_run' => 0,
            'tests_passed' => 0,
            'tests_failed' => 0,
            'success' => true
        ];
    }

    /**
     * Führt Sicherheits-Checks durch
     */
    private function performSecurityCheck(array $changes): array
    {
        $securityCheck = [
            'vulnerabilities_found' => 0,
            'issues' => [],
            'score' => 1.0 // Start mit perfektem Score
        ];

        foreach ($changes['files'] ?? [] as $file) {
            $content = $file['content'] ?? '';
            $path = $file['path'] ?? '';

            // SQL-Injection-Checks
            if (preg_match('/\$[a-zA-Z_]+\s*\.\s*["\']/', $content)) {
                $securityCheck['issues'][] = [
                    'type' => 'sql_injection',
                    'severity' => 'high',
                    'message' => 'Mögliche SQL-Injection-Schwachstelle',
                    'file' => $path
                ];
                $securityCheck['vulnerabilities_found']++;
            }

            // XSS-Checks
            if (strpos($content, 'echo $') !== false && strpos($content, 'htmlspecialchars') === false) {
                $securityCheck['issues'][] = [
                    'type' => 'xss',
                    'severity' => 'medium',
                    'message' => 'Mögliche XSS-Schwachstelle - ungefilterte Ausgabe',
                    'file' => $path
                ];
                $securityCheck['vulnerabilities_found']++;
            }

            // Hardcoded Secrets
            if (preg_match('/(password|secret|key|token)\s*=\s*["\'][^"\']{8,}["\']/', $content)) {
                $securityCheck['issues'][] = [
                    'type' => 'hardcoded_secret',
                    'severity' => 'high',
                    'message' => 'Hardcoded Secret/Password gefunden',
                    'file' => $path
                ];
                $securityCheck['vulnerabilities_found']++;
            }
        }

        // Score reduzieren basierend auf gefundenen Problemen
        if ($securityCheck['vulnerabilities_found'] > 0) {
            $securityCheck['score'] -= ($securityCheck['vulnerabilities_found'] * 0.2);
            $securityCheck['score'] = max(0, $securityCheck['score']);
        }

        return $securityCheck;
    }

    /**
     * Berechnet Code-Quality-Metriken
     */
    private function calculateQualityMetrics(array $changes): array
    {
        $metrics = [
            'maintainability_index' => 0,
            'cyclomatic_complexity' => 0,
            'code_coverage' => 0,
            'duplication_ratio' => 0,
            'score' => 0.8
        ];

        $totalComplexity = 0;
        $totalFiles = 0;

        foreach ($changes['files'] ?? [] as $file) {
            $content = $file['content'] ?? '';
            
            // Vereinfachte Komplexitäts-Berechnung
            $complexity = substr_count($content, 'if') + 
                         substr_count($content, 'for') + 
                         substr_count($content, 'while') +
                         substr_count($content, 'switch');
            
            $totalComplexity += $complexity;
            $totalFiles++;
        }

        if ($totalFiles > 0) {
            $metrics['cyclomatic_complexity'] = $totalComplexity / $totalFiles;
            
            // Maintainability Index (vereinfacht)
            $metrics['maintainability_index'] = max(0, 100 - ($metrics['cyclomatic_complexity'] * 5));
            
            // Score basierend auf Metriken
            if ($metrics['cyclomatic_complexity'] > 10) {
                $metrics['score'] -= 0.2;
            }
            if ($metrics['maintainability_index'] < 50) {
                $metrics['score'] -= 0.1;
            }
        }

        return $metrics;
    }

    /**
     * Berechnet Gesamt-Score
     */
    private function calculateOverallScore(array $reviews): float
    {
        $weights = [
            'code_analysis' => 0.25,
            'ai_review' => 0.30,
            'tests' => 0.20,
            'security' => 0.15,
            'quality' => 0.10
        ];

        $totalScore = 0;
        $totalWeight = 0;

        foreach ($weights as $category => $weight) {
            if (isset($reviews[$category]['score'])) {
                $totalScore += $reviews[$category]['score'] * $weight;
                $totalWeight += $weight;
            }
        }

        return $totalWeight > 0 ? $totalScore / $totalWeight : 0.5;
    }

    /**
     * Generiert Empfehlungen basierend auf Review-Ergebnissen
     */
    private function generateRecommendations(float $overallScore, array $reviews): array
    {
        $recommendations = [];

        if ($overallScore < 0.7) {
            $recommendations[] = [
                'type' => 'general',
                'priority' => 'high',
                'message' => 'Code-Review-Score ist unter dem Schwellenwert. Verbesserungen erforderlich.'
            ];
        }

        // Spezifische Empfehlungen basierend auf einzelnen Reviews
        foreach ($reviews as $review) {
            if (isset($review['issues'])) {
                foreach ($review['issues'] as $issue) {
                    if ($issue['severity'] === 'error') {
                        $recommendations[] = [
                            'type' => $issue['type'],
                            'priority' => 'high',
                            'message' => $issue['message'],
                            'file' => $issue['file'] ?? null
                        ];
                    }
                }
            }
        }

        // Positive Empfehlungen
        if ($overallScore >= 0.9) {
            $recommendations[] = [
                'type' => 'praise',
                'priority' => 'info',
                'message' => 'Exzellente Code-Qualität! Bereit für Production.'
            ];
        }

        return $recommendations;
    }
} 