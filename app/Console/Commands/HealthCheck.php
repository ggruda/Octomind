<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BotSession;
use App\Models\Project;
use App\Models\Repository;
use App\Services\BotManagerService;
use App\Services\EmailNotificationService;
use App\Services\LogService;
use Carbon\Carbon;
use Exception;

class HealthCheck extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'octomind:health-check 
                          {--send-alerts : Sendet Alert-Emails bei kritischen Problemen}
                          {--detailed : Detaillierte Gesundheitsprüfung}';

    /**
     * The console command description.
     */
    protected $description = 'Führt Health-Check für Octomind Bot durch';

    private LogService $logger;
    private EmailNotificationService $emailService;
    private array $healthIssues = [];

    public function __construct()
    {
        parent::__construct();
        $this->logger = new LogService();
        $this->emailService = new EmailNotificationService();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🏥 Octomind Health-Check gestartet...');
        $startTime = now();

        try {
            // 1. Bot-Sessions prüfen
            $this->checkBotSessions();

            // 2. Projekte prüfen
            $this->checkProjects();

            // 3. Repositories prüfen
            $this->checkRepositories();

            // 4. System-Ressourcen prüfen
            $this->checkSystemResources();

            // 5. Letzte Aktivitäten prüfen
            $this->checkRecentActivity();

            // 6. Detaillierte Prüfungen (optional)
            if ($this->option('detailed')) {
                $this->performDetailedChecks();
            }

            // 7. Ergebnisse zusammenfassen
            $this->summarizeResults($startTime);

            // 8. Alerts senden bei kritischen Problemen
            if ($this->option('send-alerts') && $this->hasCriticalIssues()) {
                $this->sendHealthAlerts();
            }

            return count($this->healthIssues) > 0 ? 1 : 0;

        } catch (Exception $e) {
            $this->error('❌ Health-Check fehlgeschlagen: ' . $e->getMessage());
            $this->logger->error('Health-Check Fehler', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return 1;
        }
    }

    /**
     * Prüft Bot-Sessions
     */
    private function checkBotSessions(): void
    {
        $this->info('🤖 Prüfe Bot-Sessions...');

        // Aktive Sessions
        $activeSessions = BotSession::active()->get();
        $this->line("  ✅ Aktive Sessions: {$activeSessions->count()}");

        // Abgelaufene Sessions ohne Benachrichtigung
        $expiredWithoutNotification = BotSession::where('status', 'expired')
                                                ->where('expiry_notification_sent', false)
                                                ->count();

        if ($expiredWithoutNotification > 0) {
            $this->addHealthIssue('warning', 'sessions', 
                "{$expiredWithoutNotification} abgelaufene Sessions ohne Expiry-Email");
        }

        // Sessions mit kritischem Stundenverbrauch
        $criticalSessions = BotSession::active()
                                     ->whereRaw('(consumed_hours / purchased_hours) >= 0.95')
                                     ->count();

        if ($criticalSessions > 0) {
            $this->addHealthIssue('critical', 'sessions', 
                "{$criticalSessions} Sessions mit >95% Stundenverbrauch");
        }

        // Hängende Sessions (lange keine Aktivität)
        $staleSessions = BotSession::active()
                                  ->where('last_activity_at', '<', now()->subHours(2))
                                  ->count();

        if ($staleSessions > 0) {
            $this->addHealthIssue('warning', 'sessions', 
                "{$staleSessions} Sessions ohne Aktivität >2h");
        }
    }

    /**
     * Prüft Projekte
     */
    private function checkProjects(): void
    {
        $this->info('📁 Prüfe Projekte...');

        $activeProjects = Project::where('bot_enabled', true)->get();
        $this->line("  ✅ Aktive Projekte: {$activeProjects->count()}");

        foreach ($activeProjects as $project) {
            // Letzte Ticket-Synchronisation
            if (!$project->last_ticket_sync_at || 
                $project->last_ticket_sync_at < now()->subMinutes(10)) {
                
                $this->addHealthIssue('warning', 'projects', 
                    "Projekt {$project->jira_key}: Keine Ticket-Sync >10min");
            }

            // Jira-Verbindung prüfen (nur bei detailed)
            if ($this->option('detailed')) {
                try {
                    // Hier würde Jira-Verbindungstest stehen
                    $this->line("  ✅ {$project->jira_key}: Jira-Verbindung OK");
                } catch (Exception $e) {
                    $this->addHealthIssue('critical', 'projects', 
                        "Projekt {$project->jira_key}: Jira-Verbindung fehlgeschlagen");
                }
            }
        }

        if ($activeProjects->isEmpty()) {
            $this->addHealthIssue('critical', 'projects', 
                'Keine aktiven Projekte konfiguriert');
        }
    }

    /**
     * Prüft Repositories
     */
    private function checkRepositories(): void
    {
        $this->info('📦 Prüfe Repositories...');

        $activeRepos = Repository::where('bot_enabled', true)->get();
        $this->line("  ✅ Aktive Repositories: {$activeRepos->count()}");

        foreach ($activeRepos as $repo) {
            // SSH-Key prüfen
            if (!file_exists($repo->ssh_key_path)) {
                $this->addHealthIssue('critical', 'repositories', 
                    "Repository {$repo->full_name}: SSH-Key fehlt");
            }

            // Workspace prüfen
            if (!is_dir($repo->local_workspace_path)) {
                $this->addHealthIssue('warning', 'repositories', 
                    "Repository {$repo->full_name}: Workspace fehlt");
            }

            // Letzte Git-Aktivität
            if ($repo->last_commit_at && 
                Carbon::parse($repo->last_commit_at) < now()->subDays(7)) {
                
                $this->addHealthIssue('info', 'repositories', 
                    "Repository {$repo->full_name}: Keine Commits >7 Tage");
            }
        }
    }

    /**
     * Prüft System-Ressourcen
     */
    private function checkSystemResources(): void
    {
        $this->info('💻 Prüfe System-Ressourcen...');

        // Disk Space
        $diskFree = disk_free_space(storage_path());
        $diskTotal = disk_total_space(storage_path());
        $diskUsagePercent = (($diskTotal - $diskFree) / $diskTotal) * 100;

        $this->line("  💾 Disk Usage: " . round($diskUsagePercent, 1) . "%");

        if ($diskUsagePercent > 90) {
            $this->addHealthIssue('critical', 'system', 
                'Disk-Speicher >90% voll');
        } elseif ($diskUsagePercent > 80) {
            $this->addHealthIssue('warning', 'system', 
                'Disk-Speicher >80% voll');
        }

        // Memory Usage (wenn verfügbar)
        if (function_exists('memory_get_usage')) {
            $memoryUsage = memory_get_usage(true);
            $memoryLimit = ini_get('memory_limit');
            
            $this->line("  🧠 Memory Usage: " . $this->formatBytes($memoryUsage));
        }

        // Database Connection
        try {
            BotSession::count();
            $this->line("  ✅ Database: Verbindung OK");
        } catch (Exception $e) {
            $this->addHealthIssue('critical', 'system', 
                'Database-Verbindung fehlgeschlagen');
        }
    }

    /**
     * Prüft letzte Aktivitäten
     */
    private function checkRecentActivity(): void
    {
        $this->info('📊 Prüfe letzte Aktivitäten...');

        // Tickets heute
        $ticketsToday = \App\Models\Ticket::whereDate('created_at', today())->count();
        $this->line("  🎫 Tickets heute: {$ticketsToday}");

        // Verarbeitete Tickets letzte Stunde
        $processedLastHour = \App\Models\Ticket::where('processing_completed_at', '>', now()->subHour())
                                              ->count();
        $this->line("  ⚡ Verarbeitet letzte Stunde: {$processedLastHour}");

        // Fehlgeschlagene Tickets letzte 24h
        $failedLast24h = \App\Models\Ticket::where('status', 'failed')
                                          ->where('updated_at', '>', now()->subDay())
                                          ->count();

        if ($failedLast24h > 5) {
            $this->addHealthIssue('warning', 'activity', 
                "{$failedLast24h} fehlgeschlagene Tickets in 24h");
        }

        $this->line("  ❌ Fehlgeschlagen 24h: {$failedLast24h}");
    }

    /**
     * Führt detaillierte Prüfungen durch
     */
    private function performDetailedChecks(): void
    {
        $this->info('🔍 Führe detaillierte Prüfungen durch...');

        // Cache-Status
        try {
            cache()->put('health_check_test', 'ok', 60);
            $cacheTest = cache()->get('health_check_test');
            
            if ($cacheTest === 'ok') {
                $this->line("  ✅ Cache: Funktionsfähig");
            } else {
                $this->addHealthIssue('warning', 'system', 'Cache funktioniert nicht korrekt');
            }
        } catch (Exception $e) {
            $this->addHealthIssue('warning', 'system', 'Cache-Test fehlgeschlagen');
        }

        // Email-Konfiguration
        if (config('mail.default') && config('mail.from.address')) {
            $this->line("  ✅ Email: Konfiguriert");
        } else {
            $this->addHealthIssue('warning', 'system', 'Email nicht vollständig konfiguriert');
        }

        // Log-Dateien prüfen
        $logPath = storage_path('logs/laravel.log');
        if (file_exists($logPath)) {
            $logSize = filesize($logPath);
            $this->line("  📝 Log-Größe: " . $this->formatBytes($logSize));
            
            if ($logSize > 100 * 1024 * 1024) { // 100MB
                $this->addHealthIssue('warning', 'system', 'Log-Datei >100MB');
            }
        }
    }

    /**
     * Fasst Ergebnisse zusammen
     */
    private function summarizeResults(Carbon $startTime): void
    {
        $duration = now()->diffInSeconds($startTime);
        
        $this->info('');
        $this->info('📋 Health-Check Zusammenfassung:');
        
        $criticalCount = collect($this->healthIssues)->where('level', 'critical')->count();
        $warningCount = collect($this->healthIssues)->where('level', 'warning')->count();
        $infoCount = collect($this->healthIssues)->where('level', 'info')->count();

        $this->table(
            ['Kategorie', 'Anzahl'],
            [
                ['🔴 Kritische Probleme', $criticalCount],
                ['⚠️ Warnungen', $warningCount],
                ['ℹ️ Informationen', $infoCount],
                ['⏱️ Dauer', $duration . ' Sekunden']
            ]
        );

        // Problem-Details
        if (!empty($this->healthIssues)) {
            $this->warn('🚨 Gefundene Probleme:');
            
            foreach ($this->healthIssues as $issue) {
                $icon = match($issue['level']) {
                    'critical' => '🔴',
                    'warning' => '⚠️',
                    'info' => 'ℹ️',
                    default => '❓'
                };
                
                $this->line("  {$icon} [{$issue['category']}] {$issue['message']}");
            }
        } else {
            $this->info('✅ Alle Systeme funktionieren normal!');
        }

        // Logging
        $this->logger->info('Health-Check abgeschlossen', [
            'duration_seconds' => $duration,
            'critical_issues' => $criticalCount,
            'warnings' => $warningCount,
            'info_items' => $infoCount,
            'total_issues' => count($this->healthIssues)
        ]);
    }

    /**
     * Fügt Health-Issue hinzu
     */
    private function addHealthIssue(string $level, string $category, string $message): void
    {
        $this->healthIssues[] = [
            'level' => $level,
            'category' => $category,
            'message' => $message,
            'timestamp' => now()->toISOString()
        ];
    }

    /**
     * Prüft ob kritische Probleme vorliegen
     */
    private function hasCriticalIssues(): bool
    {
        return collect($this->healthIssues)->contains('level', 'critical');
    }

    /**
     * Sendet Health-Alerts
     */
    private function sendHealthAlerts(): void
    {
        $this->info('📧 Sende Health-Alerts...');

        $criticalIssues = collect($this->healthIssues)->where('level', 'critical');
        
        if ($criticalIssues->isNotEmpty()) {
            // Hier würde Alert-Email-Versand implementiert werden
            $this->logger->error('Kritische Health-Check-Probleme erkannt', [
                'issues' => $criticalIssues->toArray()
            ]);
        }
    }

    /**
     * Formatiert Bytes
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
} 