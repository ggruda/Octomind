<?php

namespace App\Console\Commands;

use App\Services\SSHKeyManagementService;
use Illuminate\Console\Command;

class ManageSSHKeys extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'octomind:ssh-keys 
                           {action : Action to perform (init|status|rotate|test)}
                           {--force : Force key regeneration}';

    /**
     * The console command description.
     */
    protected $description = 'Manage SSH keys for the Octomind Bot';

    private SSHKeyManagementService $sshManager;

    public function __construct()
    {
        parent::__construct();
        $this->sshManager = new SSHKeyManagementService();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action');

        $this->displayBanner();

        return match ($action) {
            'init' => $this->initializeKeys(),
            'status' => $this->showStatus(),
            'rotate' => $this->rotateKeys(),
            'test' => $this->testConnections(),
            default => $this->showHelp()
        };
    }

    private function displayBanner(): void
    {
        $this->info('');
        $this->info('🔐 Octomind Bot SSH Key Management');
        $this->info('=====================================');
        $this->info('');
    }

    private function initializeKeys(): int
    {
        $this->info('🔧 Initialisiere SSH-Keys...');

        if ($this->sshManager->isConfigured() && !$this->option('force')) {
            $this->warn('⚠️  SSH-Keys bereits konfiguriert. Verwende --force zum Überschreiben.');
            return Command::SUCCESS;
        }

        $result = $this->sshManager->initializeSSHKeys();

        if (!$result['success']) {
            $this->error('❌ SSH-Key-Initialisierung fehlgeschlagen:');
            $this->error('   ' . $result['error']);
            return Command::FAILURE;
        }

        $this->info('✅ SSH-Keys erfolgreich initialisiert!');
        $this->info('');

        if ($result['action'] === 'generated') {
            $this->displayNewKeyInstructions($result);
        } else {
            $this->info('📋 Bestehende Keys verwendet');
            $this->displayKeyInfo($result);
        }

        return Command::SUCCESS;
    }

    private function showStatus(): int
    {
        $this->info('📊 SSH-Key-Status:');
        $this->info('');

        $status = $this->sshManager->getStatus();

        // Konfigurationsstatus
        $configStatus = $status['configured'] ? '✅ Konfiguriert' : '❌ Nicht konfiguriert';
        $this->info("Status: {$configStatus}");

        // Keys vorhanden
        $keysStatus = $status['keys_exist'] ? '✅ Vorhanden' : '❌ Nicht vorhanden';
        $this->info("Keys: {$keysStatus}");

        if ($status['keys_exist']) {
            $this->info('');
            $this->info('📁 Pfade:');
            $this->info("   Private Key: {$status['private_key_path']}");
            $this->info("   Public Key:  {$status['public_key_path']}");
            $this->info("   SSH Dir:     {$status['ssh_dir']}");

            if ($status['fingerprint']) {
                $this->info('');
                $this->info('🔑 Key-Details:');
                $this->info("   Fingerprint: {$status['fingerprint']}");
            }

            if ($status['public_key']) {
                $this->info('');
                $this->info('📋 Public Key:');
                $this->line($status['public_key']);
            }
        }

        return Command::SUCCESS;
    }

    private function rotateKeys(): int
    {
        $this->warn('⚠️  Key-Rotation wird alte Keys durch neue ersetzen!');
        
        if (!$this->confirm('Möchten Sie fortfahren?')) {
            $this->info('Abgebrochen.');
            return Command::SUCCESS;
        }

        $this->info('🔄 Rotiere SSH-Keys...');

        $result = $this->sshManager->rotateKeys();

        if (!$result['success']) {
            $this->error('❌ Key-Rotation fehlgeschlagen:');
            $this->error('   ' . $result['error']);
            return Command::FAILURE;
        }

        $this->info('✅ Keys erfolgreich rotiert!');
        $this->displayNewKeyInstructions($result);

        return Command::SUCCESS;
    }

    private function testConnections(): int
    {
        $this->info('🧪 Teste SSH-Verbindungen...');
        $this->info('');

        if (!$this->sshManager->isConfigured()) {
            $this->error('❌ SSH-Keys nicht konfiguriert. Führe zuerst "octomind:ssh-keys init" aus.');
            return Command::FAILURE;
        }

        $results = $this->sshManager->testSSHConnections();

        foreach ($results as $provider => $result) {
            $status = $result['success'] ? '✅' : '❌';
            $this->info("{$status} {$provider}: {$result['message']}");
            
            if (!$result['success'] && $this->option('verbose')) {
                $this->warn("   Output: {$result['output']}");
            }
        }

        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $totalCount = count($results);

        $this->info('');
        $this->info("📊 Ergebnis: {$successCount}/{$totalCount} Verbindungen erfolgreich");

        if ($successCount === 0) {
            $this->error('');
            $this->error('❌ Keine SSH-Verbindungen erfolgreich!');
            $this->error('   Stelle sicher, dass die Deploy Keys zu den Repositories hinzugefügt wurden.');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function showHelp(): int
    {
        $this->error('❌ Unbekannte Aktion. Verfügbare Aktionen:');
        $this->info('');
        $this->info('  init     - SSH-Keys initialisieren');
        $this->info('  status   - SSH-Key-Status anzeigen');
        $this->info('  rotate   - SSH-Keys rotieren (neue generieren)');
        $this->info('  test     - SSH-Verbindungen testen');
        $this->info('');
        $this->info('Beispiele:');
        $this->info('  php artisan octomind:ssh-keys init');
        $this->info('  php artisan octomind:ssh-keys status');
        $this->info('  php artisan octomind:ssh-keys test');

        return Command::FAILURE;
    }

    private function displayNewKeyInstructions(array $result): void
    {
        $this->warn('');
        $this->warn('🚨 WICHTIG: Deploy Keys zu Repositories hinzufügen!');
        $this->warn('');

        $this->displayKeyInfo($result);

        $this->info('');
        $this->info('📋 Deployment-Anweisungen:');
        $this->info('');

        foreach ($result['instructions'] as $provider => $instructions) {
            $this->info("🔹 {$instructions['title']}:");
            foreach ($instructions['steps'] as $step) {
                $this->info("   {$step}");
            }
            $this->info('');
        }

        $this->warn('⚠️  Der Bot kann erst nach dem Hinzufügen der Deploy Keys funktionieren!');
    }

    private function displayKeyInfo(array $result): void
    {
        $this->info('🔑 Key-Details:');
        $this->info("   Fingerprint: {$result['fingerprint']}");
        $this->info('');
        $this->info('📋 Public Key (zum Kopieren):');
        $this->info('');
        $this->line($result['public_key']);
        $this->info('');
    }
} 