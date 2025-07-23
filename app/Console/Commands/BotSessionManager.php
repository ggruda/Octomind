<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BotManagerService;
use App\Models\BotSession;
use App\Services\EmailNotificationService;
use Exception;

class BotSessionManager extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'octomind:bot:session 
                          {action : create|list|show|pause|resume|extend|delete}
                          {--session-id= : Session-ID für Aktionen}
                          {--customer-email= : Kunden-Email für neue Session}
                          {--hours= : Stunden für neue Session oder Erweiterung}
                          {--customer-name= : Kunden-Name für neue Session}
                          {--force : Erzwinge Aktion ohne Bestätigung}
                          {--send-test-email : Sendet Test-Email}';

    /**
     * The console command description.
     */
    protected $description = 'Verwaltet Bot-Sessions (erstellen, anzeigen, pausieren, erweitern)';

    private BotManagerService $botManager;
    private EmailNotificationService $emailService;

    public function __construct()
    {
        parent::__construct();
        $this->botManager = new BotManagerService();
        $this->emailService = new EmailNotificationService();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action');

        return match($action) {
            'create' => $this->createSession(),
            'list' => $this->listSessions(),
            'show' => $this->showSession(),
            'pause' => $this->pauseSession(),
            'resume' => $this->resumeSession(),
            'extend' => $this->extendSession(),
            'delete' => $this->deleteSession(),
            default => $this->error("❌ Unbekannte Aktion: {$action}. Verfügbar: create, list, show, pause, resume, extend, delete") ?: 1
        };
    }

    /**
     * Erstellt neue Session
     */
    private function createSession(): int
    {
        $customerEmail = $this->option('customer-email');
        $hours = $this->option('hours');

        if (!$customerEmail) {
            $customerEmail = $this->ask('Kunden-Email');
        }

        if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $this->error('❌ Ungültige Email-Adresse');
            return 1;
        }

        if (!$hours) {
            $hours = $this->ask('Gekaufte Stunden', '10');
        }

        if (!is_numeric($hours) || $hours <= 0) {
            $this->error('❌ Ungültige Stundenanzahl');
            return 1;
        }

        $customerName = $this->option('customer-name');
        if (!$customerName) {
            $customerName = $this->ask('Kunden-Name (optional)');
        }

        try {
            $session = $this->botManager->createSession(
                $customerEmail,
                (float) $hours,
                $customerName ?: null
            );

            $this->info('✅ Session erfolgreich erstellt:');
            $this->table(
                ['Eigenschaft', 'Wert'],
                [
                    ['Session-ID', $session->session_id],
                    ['Kunde', $customerEmail],
                    ['Name', $customerName ?: 'Nicht angegeben'],
                    ['Gekaufte Stunden', $hours],
                    ['Status', 'active'],
                    ['Erstellt', $session->created_at->format('d.m.Y H:i:s')]
                ]
            );

            // Test-Email senden?
            if ($this->option('send-test-email')) {
                $this->info('📧 Sende Test-Email...');
                $success = $this->emailService->sendTestEmail($customerEmail, 'warning');
                
                if ($success) {
                    $this->info('✅ Test-Email gesendet');
                } else {
                    $this->warn('⚠️ Test-Email konnte nicht gesendet werden');
                }
            }

            return 0;

        } catch (Exception $e) {
            $this->error('❌ Fehler beim Erstellen der Session: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Listet Sessions auf
     */
    private function listSessions(): int
    {
        $sessions = BotSession::orderBy('created_at', 'desc')->limit(20)->get();

        if ($sessions->isEmpty()) {
            $this->warn('⚠️ Keine Sessions gefunden');
            return 0;
        }

        $this->info('📋 Bot-Sessions (letzte 20):');

        $this->table(
            ['Session-ID', 'Kunde', 'Status', 'Gekauft', 'Verbraucht', 'Verbleibend', 'Tickets', 'Erstellt'],
            $sessions->map(function ($session) {
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
                    $session->created_at->format('d.m H:i')
                ];
            })->toArray()
        );

        // Statistiken
        $totalSessions = $sessions->count();
        $activeSessions = $sessions->where('status', 'active')->count();
        $pausedSessions = $sessions->where('status', 'paused')->count();
        $expiredSessions = $sessions->where('status', 'expired')->count();

        $this->info("📊 Status: {$activeSessions} aktiv, {$pausedSessions} pausiert, {$expiredSessions} abgelaufen");

        return 0;
    }

    /**
     * Zeigt Session-Details
     */
    private function showSession(): int
    {
        $sessionId = $this->option('session-id');

        if (!$sessionId) {
            $sessionId = $this->ask('Session-ID');
        }

        $session = BotSession::where('session_id', $sessionId)->first();

        if (!$session) {
            $this->error("❌ Session '{$sessionId}' nicht gefunden");
            return 1;
        }

        $report = $session->generateReport();

        $this->info("📋 Session-Details: {$sessionId}");
        $this->line('');

        // Basis-Informationen
        $this->table(
            ['Eigenschaft', 'Wert'],
            [
                ['Session-ID', $session->session_id],
                ['Kunde Email', $session->customer_email],
                ['Kunde Name', $session->customer_name ?? 'Nicht angegeben'],
                ['Status', $this->getStatusWithIcon($session->status)],
                ['Erstellt', $session->created_at->format('d.m.Y H:i:s')],
                ['Letztes Update', $session->last_activity_at ? $session->last_activity_at->format('d.m.Y H:i:s') : 'Nie']
            ]
        );

        // Stunden-Details
        $this->info('⏰ Stunden-Verbrauch:');
        $this->table(
            ['Metrik', 'Wert'],
            [
                ['Gekaufte Stunden', $session->purchased_hours . ' Stunden'],
                ['Verbrauchte Stunden', $session->consumed_hours . ' Stunden'],
                ['Verbleibende Stunden', $session->remaining_hours . ' Stunden'],
                ['Verbrauch in %', $report['hours']['consumption_percentage'] . '%']
            ]
        );

        // Ticket-Performance
        $this->info('🎫 Ticket-Performance:');
        $this->table(
            ['Metrik', 'Wert'],
            [
                ['Verarbeitet gesamt', $session->tickets_processed],
                ['Erfolgreich', $session->tickets_successful],
                ['Fehlgeschlagen', $session->tickets_failed],
                ['Erfolgsrate', $report['tickets']['success_rate'] . '%'],
                ['Ø Zeit pro Ticket', $report['performance']['avg_hours_per_ticket'] . ' Stunden'],
                ['Geschätzt verbleibend', $report['performance']['estimated_remaining_tickets'] . ' Tickets']
            ]
        );

        // Warnungen
        $this->showSessionWarnings($session);

        // Letzte Tickets
        $this->showRecentTickets($session);

        return 0;
    }

    /**
     * Pausiert Session
     */
    private function pauseSession(): int
    {
        $sessionId = $this->resolveSessionId();
        if (!$sessionId) return 1;

        $session = BotSession::where('session_id', $sessionId)->first();

        if (!$session) {
            $this->error("❌ Session '{$sessionId}' nicht gefunden");
            return 1;
        }

        if ($session->status === 'paused') {
            $this->warn("⚠️ Session ist bereits pausiert");
            return 0;
        }

        if ($session->status !== 'active') {
            $this->error("❌ Nur aktive Sessions können pausiert werden (aktueller Status: {$session->status})");
            return 1;
        }

        if (!$this->option('force') && !$this->confirm("Session '{$sessionId}' pausieren?")) {
            $this->info('❌ Abgebrochen');
            return 0;
        }

        try {
            $session->pause();
            $this->info("✅ Session '{$sessionId}' pausiert");
            return 0;

        } catch (Exception $e) {
            $this->error('❌ Fehler beim Pausieren: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Reaktiviert Session
     */
    private function resumeSession(): int
    {
        $sessionId = $this->resolveSessionId();
        if (!$sessionId) return 1;

        $session = BotSession::where('session_id', $sessionId)->first();

        if (!$session) {
            $this->error("❌ Session '{$sessionId}' nicht gefunden");
            return 1;
        }

        if ($session->status === 'active') {
            $this->warn("⚠️ Session ist bereits aktiv");
            return 0;
        }

        if (!$session->canBeActive()) {
            $this->error("❌ Session kann nicht reaktiviert werden (Status: {$session->status}, Verbleibende Stunden: {$session->remaining_hours})");
            return 1;
        }

        try {
            $session->resume();
            $this->info("✅ Session '{$sessionId}' reaktiviert");
            return 0;

        } catch (Exception $e) {
            $this->error('❌ Fehler beim Reaktivieren: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Erweitert Session um weitere Stunden
     */
    private function extendSession(): int
    {
        $sessionId = $this->resolveSessionId();
        if (!$sessionId) return 1;

        $hours = $this->option('hours');
        if (!$hours) {
            $hours = $this->ask('Zusätzliche Stunden');
        }

        if (!is_numeric($hours) || $hours <= 0) {
            $this->error('❌ Ungültige Stundenanzahl');
            return 1;
        }

        $session = BotSession::where('session_id', $sessionId)->first();

        if (!$session) {
            $this->error("❌ Session '{$sessionId}' nicht gefunden");
            return 1;
        }

        $this->info("Session '{$sessionId}' um {$hours} Stunden erweitern?");
        $this->table(
            ['Aktuell', 'Wert'],
            [
                ['Gekaufte Stunden', $session->purchased_hours],
                ['Verbleibende Stunden', $session->remaining_hours],
                ['Nach Erweiterung', $session->purchased_hours + $hours],
                ['Neue verbleibende Stunden', $session->remaining_hours + $hours]
            ]
        );

        if (!$this->option('force') && !$this->confirm('Erweiterung durchführen?')) {
            $this->info('❌ Abgebrochen');
            return 0;
        }

        try {
            $session->update([
                'purchased_hours' => $session->purchased_hours + $hours,
                'remaining_hours' => $session->remaining_hours + $hours,
                'status' => 'active' // Reaktiviere falls expired
            ]);

            $this->info("✅ Session '{$sessionId}' um {$hours} Stunden erweitert");
            return 0;

        } catch (Exception $e) {
            $this->error('❌ Fehler beim Erweitern: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Löscht Session
     */
    private function deleteSession(): int
    {
        $sessionId = $this->resolveSessionId();
        if (!$sessionId) return 1;

        $session = BotSession::where('session_id', $sessionId)->first();

        if (!$session) {
            $this->error("❌ Session '{$sessionId}' nicht gefunden");
            return 1;
        }

        $this->warn("⚠️ Session '{$sessionId}' löschen?");
        $this->table(
            ['Eigenschaft', 'Wert'],
            [
                ['Kunde', $session->customer_email],
                ['Status', $session->status],
                ['Verarbeitete Tickets', $session->tickets_processed],
                ['Verbleibende Stunden', $session->remaining_hours]
            ]
        );

        if (!$this->option('force') && !$this->confirm('Wirklich löschen? (Tickets bleiben erhalten)')) {
            $this->info('❌ Abgebrochen');
            return 0;
        }

        try {
            // Tickets von Session trennen (aber nicht löschen)
            $session->tickets()->update(['bot_session_id' => null]);
            
            $session->delete();
            
            $this->info("✅ Session '{$sessionId}' gelöscht");
            return 0;

        } catch (Exception $e) {
            $this->error('❌ Fehler beim Löschen: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Hilfsmethoden
     */
    private function resolveSessionId(): ?string
    {
        $sessionId = $this->option('session-id');

        if (!$sessionId) {
            $sessionId = $this->ask('Session-ID');
        }

        return $sessionId;
    }

    private function getStatusWithIcon(string $status): string
    {
        $icon = match($status) {
            'active' => '✅',
            'paused' => '⏸️',
            'expired' => '🛑',
            'cancelled' => '❌',
            default => '❓'
        };

        return $icon . ' ' . ucfirst($status);
    }

    private function showSessionWarnings(BotSession $session): void
    {
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

        if ($session->status === 'paused') {
            $warnings[] = '⏸️ Session ist pausiert';
        }

        if (!empty($warnings)) {
            $this->warn('⚠️ Warnungen:');
            foreach ($warnings as $warning) {
                $this->line("  {$warning}");
            }
            $this->line('');
        }
    }

    private function showRecentTickets(BotSession $session): void
    {
        $recentTickets = $session->tickets()
                                ->orderBy('created_at', 'desc')
                                ->limit(5)
                                ->get();

        if ($recentTickets->isNotEmpty()) {
            $this->info('🎫 Letzte 5 Tickets:');
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
    }
} 