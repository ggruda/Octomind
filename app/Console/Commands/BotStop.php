<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BotManagerService;
use App\Models\BotSession;
use Exception;

class BotStop extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'octomind:bot:stop 
                          {--session-id= : Spezifische Session stoppen}
                          {--all : Alle aktiven Sessions stoppen}
                          {--force : Erzwinge Stop ohne Bestätigung}';

    /**
     * The console command description.
     */
    protected $description = 'Stoppt den Octomind Bot';

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
        $this->info('🛑 Octomind Bot wird gestoppt...');

        try {
            if ($this->option('all')) {
                return $this->stopAllSessions();
            }

            $sessionId = $this->option('session-id');
            return $this->stopSession($sessionId);

        } catch (Exception $e) {
            $this->error('❌ Fehler beim Stoppen des Bots: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * Stoppt eine spezifische Session
     */
    private function stopSession(?string $sessionId): int
    {
        if ($sessionId) {
            $session = BotSession::where('session_id', $sessionId)->first();
            
            if (!$session) {
                $this->error("❌ Session '{$sessionId}' nicht gefunden");
                return 1;
            }
            
            if ($session->status !== 'active') {
                $this->warn("⚠️ Session '{$sessionId}' ist bereits gestoppt (Status: {$session->status})");
                return 0;
            }
        } else {
            // Aktive Session finden
            $session = BotSession::active()->orderBy('last_activity_at', 'desc')->first();
            
            if (!$session) {
                $this->warn('⚠️ Keine aktive Session gefunden');
                return 0;
            }
            
            $sessionId = $session->session_id;
        }

        // Bestätigung (außer bei --force)
        if (!$this->option('force')) {
            $report = $session->generateReport();
            
            $this->info("Session-Info:");
            $this->table(
                ['Eigenschaft', 'Wert'],
                [
                    ['Session-ID', $sessionId],
                    ['Kunde', $report['customer']['email']],
                    ['Verbleibende Stunden', $report['hours']['remaining']],
                    ['Verarbeitete Tickets', $report['tickets']['total_processed']],
                    ['Status', $report['status']['current']]
                ]
            );

            if (!$this->confirm("Möchten Sie diese Session wirklich stoppen?")) {
                $this->info('❌ Abgebrochen');
                return 0;
            }
        }

        // Session stoppen
        $result = $this->botManager->stop();

        if ($result['success']) {
            $this->info("✅ Session '{$sessionId}' erfolgreich gestoppt");
            
            if ($report = $result['session_report']) {
                $this->displaySessionReport($report);
            }
            
            return 0;
        } else {
            $this->error("❌ Fehler beim Stoppen der Session: " . ($result['error'] ?? 'Unbekannter Fehler'));
            return 1;
        }
    }

    /**
     * Stoppt alle aktiven Sessions
     */
    private function stopAllSessions(): int
    {
        $activeSessions = BotSession::active()->get();

        if ($activeSessions->isEmpty()) {
            $this->warn('⚠️ Keine aktiven Sessions gefunden');
            return 0;
        }

        $this->info("Gefunden: {$activeSessions->count()} aktive Session(s)");

        // Bestätigung (außer bei --force)
        if (!$this->option('force')) {
            $this->table(
                ['Session-ID', 'Kunde', 'Verbleibende Stunden', 'Status'],
                $activeSessions->map(function ($session) {
                    return [
                        $session->session_id,
                        $session->customer_email,
                        $session->remaining_hours,
                        $session->status
                    ];
                })->toArray()
            );

            if (!$this->confirm("Möchten Sie alle {$activeSessions->count()} Session(s) stoppen?")) {
                $this->info('❌ Abgebrochen');
                return 0;
            }
        }

        $stoppedCount = 0;
        $errorCount = 0;

        foreach ($activeSessions as $session) {
            try {
                $session->pause();
                $stoppedCount++;
                
                $this->line("✅ Session '{$session->session_id}' gestoppt");
                
            } catch (Exception $e) {
                $errorCount++;
                $this->error("❌ Fehler bei Session '{$session->session_id}': " . $e->getMessage());
            }
        }

        $this->info("📊 Zusammenfassung: {$stoppedCount} gestoppt, {$errorCount} Fehler");

        return $errorCount > 0 ? 1 : 0;
    }

    /**
     * Zeigt Session-Report
     */
    private function displaySessionReport(array $report): void
    {
        $this->info('📊 Session-Report:');
        
        $this->table(
            ['Kategorie', 'Details'],
            [
                ['Kunde', $report['customer']['email'] . ($report['customer']['name'] ? " ({$report['customer']['name']})" : '')],
                ['Stunden', "Verbraucht: {$report['hours']['consumed']} / {$report['hours']['purchased']} ({$report['hours']['consumption_percentage']}%)"],
                ['Tickets', "Verarbeitet: {$report['tickets']['total_processed']} (Erfolg: {$report['tickets']['successful']}, Fehler: {$report['tickets']['failed']})"],
                ['Erfolgsrate', $report['tickets']['success_rate'] . '%'],
                ['Durchschnitt/Ticket', $report['performance']['avg_hours_per_ticket'] . ' Stunden'],
                ['Geschätzt verbleibend', $report['performance']['estimated_remaining_tickets'] . ' Tickets'],
                ['Letztes Update', $report['status']['last_activity'] ?? 'Nie']
            ]
        );
    }
} 