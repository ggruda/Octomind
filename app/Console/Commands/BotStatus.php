<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BotManagerService;
use App\Models\BotSession;
use App\Models\Project;
use App\Models\Repository;
use Carbon\Carbon;

class BotStatus extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'octomind:bot:status 
                          {--session-id= : Status für spezifische Session}
                          {--all : Alle Sessions anzeigen}
                          {--projects : Projekt-Status anzeigen}
                          {--repositories : Repository-Status anzeigen}
                          {--detailed : Detaillierte Informationen anzeigen}';

    /**
     * The console command description.
     */
    protected $description = 'Zeigt den Status des Octomind Bots und der Sessions';

    private BotManagerService $botManager;

    public function __construct()
    {
        parent::__construct();
        $this->botManager = new BotManagerService();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('📊 Octomind Bot Status');
        $this->line('');

        // Spezifische Session
        if ($sessionId = $this->option('session-id')) {
            return $this->showSessionStatus($sessionId);
        }

        // Alle Sessions
        if ($this->option('all')) {
            return $this->showAllSessions();
        }

        // Projekt-Status
        if ($this->option('projects')) {
            return $this->showProjectStatus();
        }

        // Repository-Status
        if ($this->option('repositories')) {
            return $this->showRepositoryStatus();
        }

        // Standard: Aktuelle Session + Übersicht
        return $this->showOverview();
    }

    /**
     * Zeigt Übersicht
     */
    private function showOverview(): int
    {
        // 1. Bot-Status
        $isRunning = $this->botManager->isRunning();
        $this->info('🤖 Bot-Status: ' . ($isRunning ? '✅ Aktiv' : '🛑 Gestoppt'));

        // 2. Aktive Sessions
        $activeSessions = BotSession::active()->get();
        $this->info("📋 Aktive Sessions: {$activeSessions->count()}");

        if ($activeSessions->isNotEmpty()) {
            $this->table(
                ['Session-ID', 'Kunde', 'Verbleibende Stunden', 'Tickets', 'Erfolgsrate', 'Letztes Update'],
                $activeSessions->map(function ($session) {
                    $report = $session->generateReport();
                    return [
                        substr($session->session_id, 0, 20) . '...',
                        $session->customer_email,
                        $session->remaining_hours,
                        $session->tickets_processed,
                        $report['tickets']['success_rate'] . '%',
                        $session->last_activity_at ? $session->last_activity_at->diffForHumans() : 'Nie'
                    ];
                })->toArray()
            );
        }

        // 3. Heute's Statistiken
        $this->showTodayStats();

        // 4. System-Gesundheit
        $this->showSystemHealth();

        return 0;
    }

    /**
     * Zeigt Status einer spezifischen Session
     */
    private function showSessionStatus(string $sessionId): int
    {
        $session = BotSession::where('session_id', $sessionId)->first();

        if (!$session) {
            $this->error("❌ Session '{$sessionId}' nicht gefunden");
            return 1;
        }

        $report = $session->generateReport();

        $this->info("📋 Session: {$sessionId}");
        $this->line('');

        // Kunden-Info
        $this->info('👤 Kunde:');
        $this->table(
            ['Eigenschaft', 'Wert'],
            [
                ['Email', $report['customer']['email']],
                ['Name', $report['customer']['name'] ?? 'Nicht angegeben'],
                ['Session erstellt', $session->created_at->format('d.m.Y H:i:s')]
            ]
        );

        // Stunden-Info
        $this->info('⏰ Stunden:');
        $this->table(
            ['Eigenschaft', 'Wert'],
            [
                ['Gekauft', $report['hours']['purchased'] . ' Stunden'],
                ['Verbraucht', $report['hours']['consumed'] . ' Stunden'],
                ['Verbleibend', $report['hours']['remaining'] . ' Stunden'],
                ['Verbrauch', $report['hours']['consumption_percentage'] . '%']
            ]
        );

        // Ticket-Performance
        $this->info('🎫 Tickets:');
        $this->table(
            ['Eigenschaft', 'Wert'],
            [
                ['Verarbeitet', $report['tickets']['total_processed']],
                ['Erfolgreich', $report['tickets']['successful']],
                ['Fehlgeschlagen', $report['tickets']['failed']],
                ['Erfolgsrate', $report['tickets']['success_rate'] . '%']
            ]
        );

        // Performance-Metriken
        $this->info('📈 Performance:');
        $this->table(
            ['Eigenschaft', 'Wert'],
            [
                ['Ø Zeit pro Ticket', $report['performance']['avg_hours_per_ticket'] . ' Stunden'],
                ['Geschätzt verbleibend', $report['performance']['estimated_remaining_tickets'] . ' Tickets']
            ]
        );

        // Status
        $this->info('🔄 Status:');
        $statusColor = match($session->status) {
            'active' => '✅',
            'paused' => '⏸️',
            'expired' => '🛑',
            'cancelled' => '❌',
            default => '❓'
        };

        $this->table(
            ['Eigenschaft', 'Wert'],
            [
                ['Aktueller Status', $statusColor . ' ' . ucfirst($session->status)],
                ['Gestartet', $report['status']['started_at'] ?? 'Nie'],
                ['Letztes Update', $report['status']['last_activity'] ?? 'Nie'],
                ['Abgelaufen', $report['status']['expired_at'] ?? 'Nein']
            ]
        );

        // Warnungen
        $warnings = [];
        if ($session->shouldSend75Warning()) {
            $warnings[] = '⚠️ 75% der Stunden verbraucht';
        }
        if ($session->shouldSend90Warning()) {
            $warnings[] = '🔴 90% der Stunden verbraucht';
        }
        if ($session->isExpired()) {
            $warnings[] = '🛑 Session abgelaufen';
        }

        if (!empty($warnings)) {
            $this->warn('⚠️ Warnungen:');
            foreach ($warnings as $warning) {
                $this->line("  {$warning}");
            }
        }

        // Detaillierte Infos bei --detailed
        if ($this->option('detailed')) {
            $this->showDetailedSessionInfo($session);
        }

        return 0;
    }

    /**
     * Zeigt alle Sessions
     */
    private function showAllSessions(): int
    {
        $sessions = BotSession::orderBy('created_at', 'desc')->limit(50)->get();

        if ($sessions->isEmpty()) {
            $this->warn('⚠️ Keine Sessions gefunden');
            return 0;
        }

        $this->info("📋 Alle Sessions (letzte 50):");

        $this->table(
            ['Session-ID', 'Kunde', 'Status', 'Gekauft', 'Verbraucht', 'Verbleibend', 'Tickets', 'Erfolgsrate', 'Erstellt'],
            $sessions->map(function ($session) {
                $report = $session->generateReport();
                $statusIcon = match($session->status) {
                    'active' => '✅',
                    'paused' => '⏸️',
                    'expired' => '🛑',
                    'cancelled' => '❌',
                    default => '❓'
                };

                return [
                    substr($session->session_id, -12),
                    substr($session->customer_email, 0, 25) . (strlen($session->customer_email) > 25 ? '...' : ''),
                    $statusIcon . ' ' . ucfirst($session->status),
                    $session->purchased_hours . 'h',
                    $session->consumed_hours . 'h',
                    $session->remaining_hours . 'h',
                    $session->tickets_processed,
                    $report['tickets']['success_rate'] . '%',
                    $session->created_at->format('d.m H:i')
                ];
            })->toArray()
        );

        // Zusammenfassung
        $totalSessions = $sessions->count();
        $activeSessions = $sessions->where('status', 'active')->count();
        $expiredSessions = $sessions->where('status', 'expired')->count();

        $this->info("📊 Zusammenfassung: {$totalSessions} Sessions ({$activeSessions} aktiv, {$expiredSessions} abgelaufen)");

        return 0;
    }

    /**
     * Zeigt Projekt-Status
     */
    private function showProjectStatus(): int
    {
        $projects = Project::with(['repositories', 'tickets'])->get();

        if ($projects->isEmpty()) {
            $this->warn('⚠️ Keine Projekte konfiguriert');
            return 0;
        }

        $this->info('📁 Projekt-Status:');

        $this->table(
            ['Projekt', 'Bot', 'Repositories', 'Tickets', 'Erfolgsrate', 'Letztes Ticket'],
            $projects->map(function ($project) {
                $totalTickets = $project->tickets->count();
                $successfulTickets = $project->tickets->where('status', 'completed')->count();
                $successRate = $totalTickets > 0 ? round(($successfulTickets / $totalTickets) * 100, 1) : 0;
                $lastTicket = $project->tickets->sortByDesc('created_at')->first();

                return [
                    $project->jira_key,
                    $project->bot_enabled ? '✅' : '❌',
                    $project->repositories->count(),
                    $totalTickets,
                    $successRate . '%',
                    $lastTicket ? $lastTicket->created_at->diffForHumans() : 'Nie'
                ];
            })->toArray()
        );

        return 0;
    }

    /**
     * Zeigt Repository-Status
     */
    private function showRepositoryStatus(): int
    {
        $repositories = Repository::with(['projects', 'tickets'])->get();

        if ($repositories->isEmpty()) {
            $this->warn('⚠️ Keine Repositories konfiguriert');
            return 0;
        }

        $this->info('📦 Repository-Status:');

        $this->table(
            ['Repository', 'Bot', 'Framework', 'SSH', 'Projekte', 'Tickets', 'Letzter Commit'],
            $repositories->map(function ($repository) {
                $sshStatus = file_exists($repository->ssh_key_path) ? '✅' : '❌';
                $lastCommit = $repository->last_commit_at ? Carbon::parse($repository->last_commit_at)->diffForHumans() : 'Nie';

                return [
                    substr($repository->full_name, 0, 30),
                    $repository->bot_enabled ? '✅' : '❌',
                    $repository->framework_type ?? 'Unbekannt',
                    $sshStatus,
                    $repository->projects->count(),
                    $repository->tickets->count(),
                    $lastCommit
                ];
            })->toArray()
        );

        return 0;
    }

    /**
     * Zeigt heutige Statistiken
     */
    private function showTodayStats(): void
    {
        $today = Carbon::today();
        
        $todayTickets = \App\Models\Ticket::whereDate('created_at', $today)->count();
        $todaySuccessful = \App\Models\Ticket::whereDate('processing_completed_at', $today)
                                           ->where('status', 'completed')
                                           ->count();
        $todayFailed = \App\Models\Ticket::whereDate('processing_completed_at', $today)
                                        ->where('status', 'failed')
                                        ->count();

        $this->info('📅 Heute:');
        $this->table(
            ['Metrik', 'Wert'],
            [
                ['Neue Tickets', $todayTickets],
                ['Erfolgreich verarbeitet', $todaySuccessful],
                ['Fehlgeschlagen', $todayFailed],
                ['Erfolgsrate', $todayTickets > 0 ? round(($todaySuccessful / $todayTickets) * 100, 1) . '%' : '0%']
            ]
        );
    }

    /**
     * Zeigt System-Gesundheit
     */
    private function showSystemHealth(): void
    {
        $this->info('🏥 System-Gesundheit:');

        $checks = [
            ['Database', $this->checkDatabase()],
            ['Projects', $this->checkProjects()],
            ['Repositories', $this->checkRepositories()],
            ['SSH Keys', $this->checkSSHKeys()],
            ['Email Config', $this->checkEmailConfig()]
        ];

        $this->table(
            ['Check', 'Status'],
            $checks
        );
    }

    /**
     * Zeigt detaillierte Session-Infos
     */
    private function showDetailedSessionInfo(BotSession $session): void
    {
        $this->info('🔍 Detaillierte Informationen:');

        // Letzte Tickets
        $recentTickets = $session->tickets()
                                ->orderBy('created_at', 'desc')
                                ->limit(10)
                                ->get();

        if ($recentTickets->isNotEmpty()) {
            $this->info('🎫 Letzte Tickets:');
            $this->table(
                ['Ticket', 'Status', 'Stunden', 'Erstellt'],
                $recentTickets->map(function ($ticket) {
                    return [
                        $ticket->jira_key,
                        $ticket->status,
                        $ticket->hours_consumed ? round($ticket->hours_consumed, 2) . 'h' : '-',
                        $ticket->created_at->format('d.m H:i')
                    ];
                })->toArray()
            );
        }

        // Bot-Konfiguration
        if ($session->bot_config) {
            $this->info('⚙️ Bot-Konfiguration:');
            foreach ($session->bot_config as $key => $value) {
                $this->line("  {$key}: " . (is_array($value) ? json_encode($value) : $value));
            }
        }
    }

    /**
     * System-Checks
     */
    private function checkDatabase(): string
    {
        try {
            BotSession::count();
            return '✅ OK';
        } catch (\Exception $e) {
            return '❌ Fehler';
        }
    }

    private function checkProjects(): string
    {
        $activeProjects = Project::where('bot_enabled', true)->count();
        return $activeProjects > 0 ? "✅ {$activeProjects} aktiv" : '⚠️ Keine aktiven';
    }

    private function checkRepositories(): string
    {
        $activeRepos = Repository::where('bot_enabled', true)->count();
        return $activeRepos > 0 ? "✅ {$activeRepos} aktiv" : '⚠️ Keine aktiven';
    }

    private function checkSSHKeys(): string
    {
        $reposWithKeys = Repository::where('bot_enabled', true)
                                  ->get()
                                  ->filter(fn($repo) => file_exists($repo->ssh_key_path))
                                  ->count();
        $totalRepos = Repository::where('bot_enabled', true)->count();
        
        return $totalRepos > 0 && $reposWithKeys === $totalRepos 
            ? '✅ Alle OK' 
            : "⚠️ {$reposWithKeys}/{$totalRepos}";
    }

    private function checkEmailConfig(): string
    {
        $configured = config('mail.default') && config('mail.from.address');
        return $configured ? '✅ Konfiguriert' : '⚠️ Nicht konfiguriert';
    }
} 