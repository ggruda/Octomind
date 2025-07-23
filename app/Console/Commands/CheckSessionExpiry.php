<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BotSession;
use App\Services\EmailNotificationService;
use App\Services\LogService;
use Exception;

class CheckSessionExpiry extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'octomind:check-session-expiry 
                          {--send-notifications : Sendet Benachrichtigungen bei Ablauf}
                          {--force-expire= : Forciert Ablauf einer spezifischen Session}';

    /**
     * The console command description.
     */
    protected $description = 'Prüft Bot-Sessions auf Stunden-Ablauf und deaktiviert sie automatisch';

    private EmailNotificationService $emailService;
    private LogService $logger;

    public function __construct()
    {
        parent::__construct();
        $this->emailService = new EmailNotificationService();
        $this->logger = new LogService();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('⏰ Prüfe Session-Abläufe...');

        try {
            // 1. Force-Expire einer spezifischen Session
            if ($forceSessionId = $this->option('force-expire')) {
                return $this->forceExpireSession($forceSessionId);
            }

            // 2. Alle Sessions auf Ablauf prüfen
            $expiredSessions = $this->checkAndExpireSessions();

            // 3. Warnungen für Sessions nahe dem Ablauf senden
            $warningsSent = $this->checkAndSendWarnings();

            // 4. Zusammenfassung
            $this->info('📊 Session-Expiry-Check abgeschlossen:');
            $this->table(
                ['Kategorie', 'Anzahl'],
                [
                    ['Abgelaufene Sessions', $expiredSessions],
                    ['Warnungen gesendet', $warningsSent],
                    ['Aktive Sessions', BotSession::active()->count()],
                    ['Sessions mit Stunden', BotSession::active()->where('remaining_hours', '>', 0)->count()]
                ]
            );

            return 0;

        } catch (Exception $e) {
            $this->error('❌ Session-Expiry-Check fehlgeschlagen: ' . $e->getMessage());
            
            $this->logger->error('Session-Expiry-Check Fehler', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return 1;
        }
    }

    /**
     * Prüft Sessions und markiert abgelaufene als expired
     */
    private function checkAndExpireSessions(): int
    {
        $this->info('🔍 Suche nach abgelaufenen Sessions...');

        // Sessions die keine Stunden mehr haben oder als expired markiert werden sollten
        $sessionsToExpire = BotSession::where('status', 'active')
                                     ->where('remaining_hours', '<=', 0)
                                     ->get();

        $expiredCount = 0;

        foreach ($sessionsToExpire as $session) {
            try {
                $this->expireSession($session);
                $expiredCount++;

                $this->line("  🛑 Session {$session->session_id} abgelaufen ({$session->customer_email})");

            } catch (Exception $e) {
                $this->error("  ❌ Fehler beim Ablaufen von Session {$session->session_id}: " . $e->getMessage());
            }
        }

        if ($expiredCount > 0) {
            $this->info("✅ {$expiredCount} Session(s) als abgelaufen markiert");
        } else {
            $this->info("✅ Keine abgelaufenen Sessions gefunden");
        }

        return $expiredCount;
    }

    /**
     * Prüft und sendet Warnungen für Sessions nahe dem Ablauf
     */
    private function checkAndSendWarnings(): int
    {
        if (!$this->option('send-notifications')) {
            return 0;
        }

        $this->info('📧 Prüfe Warnungen für Sessions nahe dem Ablauf...');

        $warningsSent = 0;

        // 75% Warnungen
        $sessions75 = BotSession::active()
                               ->where('warning_75_sent', false)
                               ->whereRaw('(consumed_hours / purchased_hours) >= 0.75')
                               ->get();

        foreach ($sessions75 as $session) {
            try {
                $report = $session->generateReport();
                $this->emailService->sendWarningEmail(
                    $session->customer_email,
                    75,
                    $report
                );

                $session->update(['warning_75_sent' => true]);
                $warningsSent++;

                $this->line("  📧 75%-Warnung gesendet an {$session->customer_email}");

            } catch (Exception $e) {
                $this->error("  ❌ Fehler beim Senden der 75%-Warnung: " . $e->getMessage());
            }
        }

        // 90% Warnungen
        $sessions90 = BotSession::active()
                               ->where('warning_90_sent', false)
                               ->whereRaw('(consumed_hours / purchased_hours) >= 0.90')
                               ->get();

        foreach ($sessions90 as $session) {
            try {
                $report = $session->generateReport();
                $this->emailService->sendWarningEmail(
                    $session->customer_email,
                    90,
                    $report
                );

                $session->update(['warning_90_sent' => true]);
                $warningsSent++;

                $this->line("  📧 90%-Warnung gesendet an {$session->customer_email}");

            } catch (Exception $e) {
                $this->error("  ❌ Fehler beim Senden der 90%-Warnung: " . $e->getMessage());
            }
        }

        return $warningsSent;
    }

    /**
     * Lässt eine Session ablaufen
     */
    private function expireSession(BotSession $session): void
    {
        $this->logger->info('Session läuft ab', [
            'session_id' => $session->session_id,
            'customer_email' => $session->customer_email,
            'remaining_hours' => $session->remaining_hours
        ]);

        // Session als abgelaufen markieren
        $session->markExpired();

        // Expiry-Email senden (falls noch nicht gesendet)
        if (!$session->expiry_notification_sent && $this->option('send-notifications')) {
            try {
                $report = $session->generateReport();

                // Email an Kunden
                $this->emailService->sendExpiryEmail(
                    $session->customer_email,
                    $report
                );

                // Email an interne Adresse
                $this->emailService->sendInternalExpiryNotification(
                    config('octomind.email.internal_expiry_email', 'hours-expired@octomind.com'),
                    $report
                );

                $session->update(['expiry_notification_sent' => true]);

                $this->logger->info('Expiry-Emails gesendet', [
                    'session_id' => $session->session_id,
                    'customer_email' => $session->customer_email
                ]);

            } catch (Exception $e) {
                $this->logger->error('Fehler beim Senden der Expiry-Emails', [
                    'session_id' => $session->session_id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Forciert den Ablauf einer spezifischen Session
     */
    private function forceExpireSession(string $sessionId): int
    {
        $this->warn("⚠️ Forciere Ablauf von Session: {$sessionId}");

        $session = BotSession::where('session_id', $sessionId)->first();

        if (!$session) {
            $this->error("❌ Session '{$sessionId}' nicht gefunden");
            return 1;
        }

        if ($session->status === 'expired') {
            $this->warn("⚠️ Session ist bereits abgelaufen");
            return 0;
        }

        if (!$this->confirm("Session '{$sessionId}' ({$session->customer_email}) wirklich ablaufen lassen?")) {
            $this->info('❌ Abgebrochen');
            return 0;
        }

        try {
            $this->expireSession($session);
            $this->info("✅ Session '{$sessionId}' erfolgreich abgelaufen");
            return 0;

        } catch (Exception $e) {
            $this->error("❌ Fehler beim Ablaufen der Session: " . $e->getMessage());
            return 1;
        }
    }
} 