<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BotManagerService;
use App\Models\BotSession;
use Exception;

class BotStart extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'octomind:bot:start 
                          {--session-id= : Spezifische Session-ID verwenden}
                          {--customer-email= : Neue Session für Kunde erstellen}
                          {--hours= : Stunden für neue Session}
                          {--customer-name= : Name für neue Session}
                          {--daemon : Bot im Daemon-Modus starten}
                          {--debug : Debug-Modus aktivieren}';

    /**
     * The console command description.
     */
    protected $description = 'Startet den Octomind Bot für automatische Ticket-Verarbeitung';

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
        $this->info('🤖 Octomind Bot wird gestartet...');

        try {
            // 1. Session auflösen oder erstellen
            $sessionId = $this->resolveOrCreateSession();
            
            if (!$sessionId) {
                $this->error('❌ Keine gültige Session verfügbar');
                return 1;
            }

            // 2. Bot starten
            $result = $this->botManager->start($sessionId);

            if (!$result['success']) {
                $this->error('❌ Bot konnte nicht gestartet werden: ' . $result['error']);
                return 1;
            }

            $this->info('✅ Bot erfolgreich gestartet');
            $this->displaySessionInfo($result);

            // 3. Daemon-Modus oder einmaliger Start
            if ($this->option('daemon')) {
                $this->info('🔄 Starte Bot im Daemon-Modus...');
                $this->runDaemonMode();
            } else {
                $this->info('ℹ️ Bot gestartet. Verwende --daemon für kontinuierlichen Betrieb.');
            }

            return 0;

        } catch (Exception $e) {
            $this->error('❌ Fehler beim Starten des Bots: ' . $e->getMessage());
            
            if ($this->option('debug')) {
                $this->error('Debug-Info: ' . $e->getTraceAsString());
            }
            
            return 1;
        }
    }

    /**
     * Löst Session auf oder erstellt neue
     */
    private function resolveOrCreateSession(): ?string
    {
        // 1. Spezifische Session-ID angegeben?
        if ($sessionId = $this->option('session-id')) {
            $session = BotSession::where('session_id', $sessionId)->first();
            
            if (!$session) {
                $this->error("❌ Session '{$sessionId}' nicht gefunden");
                return null;
            }
            
            if (!$session->canBeActive()) {
                $this->error("❌ Session '{$sessionId}' ist abgelaufen oder deaktiviert");
                return null;
            }
            
            $this->info("📋 Verwende Session: {$sessionId}");
            return $sessionId;
        }

        // 2. Neue Session erstellen?
        if ($customerEmail = $this->option('customer-email')) {
            $hours = $this->option('hours');
            
            if (!$hours || $hours <= 0) {
                $this->error('❌ --hours muss angegeben werden und > 0 sein');
                return null;
            }

            $session = $this->botManager->createSession(
                $customerEmail,
                (float) $hours,
                $this->option('customer-name')
            );

            $this->info("✅ Neue Session erstellt: {$session->session_id}");
            return $session->session_id;
        }

        // 3. Aktive Session suchen
        $activeSession = BotSession::active()->orderBy('last_activity_at', 'desc')->first();
        
        if ($activeSession) {
            $this->info("📋 Verwende aktive Session: {$activeSession->session_id}");
            return $activeSession->session_id;
        }

        // 4. Interaktive Session-Erstellung
        if ($this->confirm('Keine aktive Session gefunden. Neue Session erstellen?')) {
            return $this->createInteractiveSession();
        }

        return null;
    }

    /**
     * Erstellt Session interaktiv
     */
    private function createInteractiveSession(): ?string
    {
        $customerEmail = $this->ask('Kunden-Email');
        
        if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $this->error('❌ Ungültige Email-Adresse');
            return null;
        }

        $hours = $this->ask('Gekaufte Stunden', '10');
        
        if (!is_numeric($hours) || $hours <= 0) {
            $this->error('❌ Ungültige Stundenanzahl');
            return null;
        }

        $customerName = $this->ask('Kunden-Name (optional)');

        $session = $this->botManager->createSession(
            $customerEmail,
            (float) $hours,
            $customerName ?: null
        );

        $this->info("✅ Session erstellt: {$session->session_id}");
        return $session->session_id;
    }

    /**
     * Zeigt Session-Informationen
     */
    private function displaySessionInfo(array $result): void
    {
        $status = $this->botManager->getSessionStatus($result['session_id']);
        
        if (!$status) {
            return;
        }

        $this->table(
            ['Eigenschaft', 'Wert'],
            [
                ['Session-ID', $status['session_id']],
                ['Kunde', $status['customer']['email']],
                ['Verbleibende Stunden', $status['hours']['remaining']],
                ['Verbrauch', $status['hours']['consumption_percentage'] . '%'],
                ['Verarbeitete Tickets', $status['tickets']['total_processed']],
                ['Erfolgsrate', $status['tickets']['success_rate'] . '%'],
                ['Status', $status['status']['current']]
            ]
        );
    }

    /**
     * Führt Bot im Daemon-Modus aus
     */
    private function runDaemonMode(): void
    {
        $this->info('🔄 Bot läuft im Daemon-Modus. Drücke Ctrl+C zum Beenden.');
        
        // Signal-Handler für graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
            pcntl_signal(SIGINT, [$this, 'handleShutdown']);
        }

        try {
            // Hauptschleife starten
            $this->botManager->runMainLoop();
            
        } catch (Exception $e) {
            $this->error('❌ Daemon-Modus fehlgeschlagen: ' . $e->getMessage());
            
            if ($this->option('debug')) {
                $this->error('Debug-Info: ' . $e->getTraceAsString());
            }
        }

        $this->info('🛑 Bot-Daemon beendet');
    }

    /**
     * Behandelt Shutdown-Signal
     */
    public function handleShutdown(): void
    {
        $this->info('🛑 Shutdown-Signal empfangen. Bot wird gestoppt...');
        
        $result = $this->botManager->stop();
        
        if ($result['success']) {
            $this->info('✅ Bot erfolgreich gestoppt');
            
            if ($report = $result['session_report']) {
                $this->info("📊 Session-Report: {$report['tickets']['total_processed']} Tickets verarbeitet");
            }
        } else {
            $this->error('❌ Fehler beim Stoppen des Bots');
        }

        exit(0);
    }
} 