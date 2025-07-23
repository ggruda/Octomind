<?php

namespace App\Services;

use App\Enums\BotStatus;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class BotStatusService
{
    private ConfigService $config;
    private LogService $logger;
    private array $metrics = [];

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
    }

    /**
     * Führt einen umfassenden Gesundheitscheck durch
     */
    public function performHealthCheck(): array
    {
        $this->logger->debug('Starte Bot-Gesundheitscheck');
        
        $checks = [
            'database' => $this->checkDatabase(),
            'memory' => $this->checkMemory(),
            'disk_space' => $this->checkDiskSpace(),
            'configuration' => $this->checkConfiguration(),
            'external_services' => $this->checkExternalServices(),
            'queue_health' => $this->checkQueueHealth(),
        ];

        $overallHealth = $this->calculateOverallHealth($checks);
        
        $healthReport = [
            'timestamp' => Carbon::now()->toISOString(),
            'overall_status' => $overallHealth['status'],
            'overall_score' => $overallHealth['score'],
            'checks' => $checks,
            'recommendations' => $this->generateRecommendations($checks),
        ];

        // Speichere Gesundheitsdaten
        $this->saveHealthMetrics($healthReport);
        
        // Log kritische Probleme
        if ($overallHealth['status'] === 'critical') {
            $this->logger->critical('Bot-Gesundheitscheck zeigt kritische Probleme', $healthReport);
        } elseif ($overallHealth['status'] === 'warning') {
            $this->logger->warning('Bot-Gesundheitscheck zeigt Warnungen', $healthReport);
        }

        return $healthReport;
    }

    /**
     * Überprüft die Datenbankverbindung und -leistung
     */
    private function checkDatabase(): array
    {
        try {
            $startTime = microtime(true);
            
            // Test basic connection
            DB::connection()->getPdo();
            
            // Test query performance
            $ticketCount = DB::table('tickets')->count();
            $logCount = DB::table('bot_logs')->count();
            
            $responseTime = (microtime(true) - $startTime) * 1000;
            
            $status = 'healthy';
            if ($responseTime > 500) {
                $status = 'warning';
            } elseif ($responseTime > 1000) {
                $status = 'critical';
            }

            return [
                'status' => $status,
                'response_time_ms' => round($responseTime, 2),
                'tickets_count' => $ticketCount,
                'logs_count' => $logCount,
                'message' => $status === 'healthy' ? 'Datenbankverbindung OK' : 'Datenbankverbindung langsam'
            ];

        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'error' => $e->getMessage(),
                'message' => 'Datenbankverbindung fehlgeschlagen'
            ];
        }
    }

    /**
     * Überprüft die Speichernutzung
     */
    private function checkMemory(): array
    {
        $memoryUsage = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        
        $usagePercent = ($memoryUsage / $memoryLimit) * 100;
        $peakPercent = ($peakMemory / $memoryLimit) * 100;
        
        $status = 'healthy';
        if ($usagePercent > 70) {
            $status = 'warning';
        } elseif ($usagePercent > 85) {
            $status = 'critical';
        }

        return [
            'status' => $status,
            'current_usage_bytes' => $memoryUsage,
            'current_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
            'peak_usage_bytes' => $peakMemory,
            'peak_usage_mb' => round($peakMemory / 1024 / 1024, 2),
            'limit_bytes' => $memoryLimit,
            'limit_mb' => round($memoryLimit / 1024 / 1024, 2),
            'usage_percent' => round($usagePercent, 2),
            'peak_percent' => round($peakPercent, 2),
            'message' => $status === 'healthy' ? 'Speichernutzung normal' : 'Hohe Speichernutzung'
        ];
    }

    /**
     * Überprüft den verfügbaren Festplattenspeicher
     */
    private function checkDiskSpace(): array
    {
        try {
            $storagePath = $this->config->get('repository.storage_path', storage_path());
            $freeBytes = disk_free_space($storagePath);
            $totalBytes = disk_total_space($storagePath);
            
            if ($freeBytes === false || $totalBytes === false) {
                throw new Exception('Kann Festplattenspeicher nicht ermitteln');
            }
            
            $usedBytes = $totalBytes - $freeBytes;
            $usagePercent = ($usedBytes / $totalBytes) * 100;
            
            $status = 'healthy';
            if ($usagePercent > 80) {
                $status = 'warning';
            } elseif ($usagePercent > 90) {
                $status = 'critical';
            }

            return [
                'status' => $status,
                'free_bytes' => $freeBytes,
                'free_gb' => round($freeBytes / 1024 / 1024 / 1024, 2),
                'total_bytes' => $totalBytes,
                'total_gb' => round($totalBytes / 1024 / 1024 / 1024, 2),
                'used_bytes' => $usedBytes,
                'used_gb' => round($usedBytes / 1024 / 1024 / 1024, 2),
                'usage_percent' => round($usagePercent, 2),
                'path' => $storagePath,
                'message' => $status === 'healthy' ? 'Ausreichend Speicherplatz' : 'Wenig Speicherplatz verfügbar'
            ];

        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'error' => $e->getMessage(),
                'message' => 'Festplattenspeicher-Check fehlgeschlagen'
            ];
        }
    }

    /**
     * Überprüft die Bot-Konfiguration
     */
    private function checkConfiguration(): array
    {
        $errors = $this->config->validateConfiguration();
        
        $status = empty($errors) ? 'healthy' : 'critical';
        
        return [
            'status' => $status,
            'errors' => $errors,
            'error_count' => count($errors),
            'message' => $status === 'healthy' ? 'Konfiguration vollständig' : 'Konfigurationsfehler gefunden'
        ];
    }

    /**
     * Überprüft externe Services (Jira, GitHub, AI-Provider)
     */
    private function checkExternalServices(): array
    {
        $services = [];
        
        // Jira-Verbindung testen
        try {
            $jiraService = new JiraService();
            $jiraResult = $jiraService->testConnection();
            $services['jira'] = [
                'status' => $jiraResult['success'] ? 'healthy' : 'critical',
                'message' => $jiraResult['message'],
                'response_time_ms' => 0 // Würde vom JiraService zurückgegeben
            ];
        } catch (Exception $e) {
            $services['jira'] = [
                'status' => 'critical',
                'message' => 'Jira-Service nicht verfügbar: ' . $e->getMessage()
            ];
        }

        // GitHub-Verbindung testen
        try {
            $githubService = new GitHubService();
            $githubResult = $githubService->testConnection();
            $services['github'] = [
                'status' => $githubResult['success'] ? 'healthy' : 'critical',
                'message' => $githubResult['message'],
                'response_time_ms' => $githubResult['response_time_ms'] ?? 0
            ];
        } catch (Exception $e) {
            $services['github'] = [
                'status' => 'critical',
                'message' => 'GitHub-Service nicht verfügbar: ' . $e->getMessage()
            ];
        }

        // AI-Provider testen
        try {
            $aiService = new CloudAIService();
            $aiResults = $aiService->testConnections();
            
            $services['openai'] = [
                'status' => $aiResults['openai']['success'] ? 'healthy' : 'critical',
                'message' => $aiResults['openai']['message'],
                'response_time_ms' => $aiResults['openai']['response_time_ms'] ?? 0
            ];
            
            $services['claude'] = [
                'status' => $aiResults['claude']['success'] ? 'healthy' : 'critical',
                'message' => $aiResults['claude']['message'],
                'response_time_ms' => $aiResults['claude']['response_time_ms'] ?? 0
            ];
        } catch (Exception $e) {
            $services['openai'] = [
                'status' => 'critical',
                'message' => 'AI-Service nicht verfügbar: ' . $e->getMessage()
            ];
            $services['claude'] = [
                'status' => 'critical',
                'message' => 'AI-Service nicht verfügbar: ' . $e->getMessage()
            ];
        }

        $overallStatus = 'healthy';
        foreach ($services as $service) {
            if ($service['status'] === 'critical') {
                $overallStatus = 'critical';
                break;
            } elseif ($service['status'] === 'warning') {
                $overallStatus = 'warning';
            }
        }

        return [
            'status' => $overallStatus,
            'services' => $services,
            'message' => $overallStatus === 'healthy' ? 'Alle Services erreichbar' : 'Einige Services haben Probleme'
        ];
    }

    /**
     * Überprüft die Queue-Gesundheit
     */
    private function checkQueueHealth(): array
    {
        try {
            // Überprüfe offene Tickets
            $pendingTickets = DB::table('tickets')
                ->whereIn('status', ['pending', 'analyzing', 'generating_solution', 'executing'])
                ->count();

            // Überprüfe fehlgeschlagene Tickets
            $failedTickets = DB::table('tickets')
                ->where('status', 'failed')
                ->where('updated_at', '>', Carbon::now()->subHours(24))
                ->count();

            // Überprüfe Retry-Versuche
            $retryAttempts = DB::table('retry_attempts')
                ->where('status', 'pending')
                ->count();

            $status = 'healthy';
            if ($pendingTickets > 10 || $failedTickets > 5) {
                $status = 'warning';
            } elseif ($pendingTickets > 20 || $failedTickets > 10) {
                $status = 'critical';
            }

            return [
                'status' => $status,
                'pending_tickets' => $pendingTickets,
                'failed_tickets_24h' => $failedTickets,
                'pending_retries' => $retryAttempts,
                'message' => $status === 'healthy' ? 'Queue läuft normal' : 'Queue-Backlog erkannt'
            ];

        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'error' => $e->getMessage(),
                'message' => 'Queue-Status-Check fehlgeschlagen'
            ];
        }
    }

    /**
     * Berechnet den Gesamt-Gesundheitsstatus
     */
    private function calculateOverallHealth(array $checks): array
    {
        $scores = [
            'healthy' => 100,
            'warning' => 60,
            'critical' => 0
        ];

        $totalScore = 0;
        $maxScore = 0;
        
        foreach ($checks as $check) {
            $score = $scores[$check['status']] ?? 0;
            $totalScore += $score;
            $maxScore += 100;
        }

        $overallScore = $maxScore > 0 ? ($totalScore / $maxScore) * 100 : 0;
        
        $status = 'healthy';
        if ($overallScore < 80) {
            $status = 'warning';
        }
        if ($overallScore < 50) {
            $status = 'critical';
        }

        return [
            'status' => $status,
            'score' => round($overallScore, 2)
        ];
    }

    /**
     * Generiert Empfehlungen basierend auf den Gesundheitschecks
     */
    private function generateRecommendations(array $checks): array
    {
        $recommendations = [];

        if ($checks['memory']['status'] !== 'healthy') {
            $recommendations[] = 'Speichernutzung optimieren oder Memory Limit erhöhen';
        }

        if ($checks['disk_space']['status'] !== 'healthy') {
            $recommendations[] = 'Festplattenspeicher freigeben oder Repository-Storage-Path überprüfen';
        }

        if ($checks['database']['status'] !== 'healthy') {
            $recommendations[] = 'Datenbankperformance optimieren oder Verbindung überprüfen';
        }

        if ($checks['external_services']['status'] !== 'healthy') {
            $recommendations[] = 'Externe Service-Verbindungen überprüfen (Jira, GitHub, AI-Provider)';
        }

        if ($checks['queue_health']['status'] !== 'healthy') {
            $recommendations[] = 'Queue-Backlog reduzieren oder fehlgeschlagene Tickets überprüfen';
        }

        return $recommendations;
    }

    /**
     * Speichert Gesundheitsmetriken in der Datenbank
     */
    private function saveHealthMetrics(array $healthReport): void
    {
        try {
            // Hier würden die Metriken in eine separate Tabelle gespeichert
            // Für jetzt nur als Log-Eintrag
            $this->logger->info('Bot-Gesundheitscheck abgeschlossen', [
                'overall_status' => $healthReport['overall_status'],
                'overall_score' => $healthReport['overall_score'],
                'checks_summary' => array_map(fn($check) => $check['status'], $healthReport['checks'])
            ]);

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Speichern der Gesundheitsmetriken', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Parst Memory Limit String zu Bytes
     */
    private function parseMemoryLimit(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        $unit = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $value = (int) $memoryLimit;

        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }

    /**
     * Holt aktuelle Bot-Metriken
     */
    public function getCurrentMetrics(): array
    {
        return [
            'timestamp' => Carbon::now()->toISOString(),
            'uptime' => $this->getUptime(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'processed_tickets_today' => $this->getProcessedTicketsToday(),
            'failed_tickets_today' => $this->getFailedTicketsToday(),
            'average_processing_time' => $this->getAverageProcessingTime(),
        ];
    }

    private function getUptime(): int
    {
        // Placeholder - würde echte Uptime berechnen
        return 0;
    }

    private function getProcessedTicketsToday(): int
    {
        try {
            return DB::table('tickets')
                ->where('status', 'completed')
                ->whereDate('updated_at', Carbon::today())
                ->count();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getFailedTicketsToday(): int
    {
        try {
            return DB::table('tickets')
                ->where('status', 'failed')
                ->whereDate('updated_at', Carbon::today())
                ->count();
        } catch (Exception $e) {
            return 0;
        }
    }

    private function getAverageProcessingTime(): float
    {
        // Placeholder - würde echte Verarbeitungszeit berechnen
        return 0.0;
    }
} 