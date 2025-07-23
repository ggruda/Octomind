<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Bots\OctomindBot;
use App\Services\ConfigService;
use App\Services\LogService;

class StartOctomindBot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'octomind:start 
                            {--simulate : Run in simulation mode}
                            {--debug : Enable verbose logging}
                            {--config-check : Only validate configuration and exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Startet den Octomind AI Automation Bot';

    private ConfigService $config;
    private LogService $logger;

    public function __construct()
    {
        parent::__construct();
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->displayBanner();
        
        // Override config with command line options
        if ($this->option('simulate')) {
            putenv('BOT_SIMULATE_MODE=true');
            $this->config->reload();
        }
        
        if ($this->option('debug')) {
            putenv('BOT_VERBOSE_LOGGING=true');
            $this->config->reload();
        }

        // Configuration validation
        $this->info('🔍 Überprüfe Bot-Konfiguration...');
        $configErrors = $this->config->validateConfiguration();
        
        if (!empty($configErrors)) {
            $this->error('❌ Konfigurationsfehler gefunden:');
            foreach ($configErrors as $error) {
                $this->error("   • {$error}");
            }
            return Command::FAILURE;
        }
        
        $this->info('✅ Konfiguration ist gültig');
        
        if ($this->option('config-check')) {
            $this->displayConfiguration();
            return Command::SUCCESS;
        }

        // Display current configuration
        $this->displayConfiguration();
        
        // Ask for confirmation if not in simulation mode
        if (!$this->config->isSimulationMode()) {
            if (!$this->confirm('⚠️  Bot läuft im LIVE-Modus und wird echte Änderungen vornehmen. Fortfahren?')) {
                $this->warn('Bot-Start abgebrochen.');
                return Command::FAILURE;
            }
        }

        // Start the bot
        $this->info('🚀 Starte Octomind Bot...');
        
        try {
            $bot = new OctomindBot();
            
            // Setup signal handling for graceful shutdown
            if (extension_loaded('pcntl')) {
                pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
                pcntl_signal(SIGINT, [$this, 'handleShutdown']);
                $this->botInstance = $bot;
            }
            
            $bot->start();
            
        } catch (\Exception $e) {
            $this->error('❌ Fehler beim Starten des Bots:');
            $this->error($e->getMessage());
            $this->logger->error('Bot startup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }

    private function displayBanner(): void
    {
        $this->line('');
        $this->line('  ╔═══════════════════════════════════════╗');
        $this->line('  ║           🤖 OCTOMIND BOT             ║');
        $this->line('  ║     AI-Powered Automation System     ║');
        $this->line('  ╚═══════════════════════════════════════╝');
        $this->line('');
    }

    private function displayConfiguration(): void
    {
        $this->line('📋 <comment>Aktuelle Konfiguration:</comment>');
        $this->line('');
        
        // Bot Status
        $enabled = $this->config->isBotEnabled() ? '✅ Aktiviert' : '❌ Deaktiviert';
        $simulation = $this->config->isSimulationMode() ? '🧪 Simulation' : '🔴 Live-Modus';
        $verbose = $this->config->isVerboseLogging() ? '📝 Verbose' : '📄 Standard';
        
        $this->line("   Bot Status:        {$enabled}");
        $this->line("   Modus:            {$simulation}");
        $this->line("   Logging:          {$verbose} ({$this->config->getLogLevel()})");
        $this->line('');
        
        // Jira Configuration
        $jiraConfig = $this->config->getJiraConfig();
        $this->line('   <comment>Jira Integration:</comment>');
        $this->line("   └─ Projekt:       {$jiraConfig['project_key']}");
        $this->line("   └─ Intervall:     {$jiraConfig['fetch_interval']}s");
        $this->line("   └─ Label:         {$jiraConfig['required_label']}");
        $this->line('');
        
        // AI Configuration
        $aiConfig = $this->config->getAiConfig();
        $this->line('   <comment>AI Provider:</comment>');
        $this->line("   └─ Primary:       {$aiConfig['primary_provider']}");
        $this->line("   └─ Fallback:      {$aiConfig['fallback_provider']}");
        $this->line("   └─ Cloud AI:      {$aiConfig['cloud_ai_provider']}");
        $this->line('');
        
        // Repository Configuration
        $repoConfig = $this->config->getRepositoryConfig();
        $this->line('   <comment>Repository:</comment>');
        $this->line("   └─ Storage:       {$repoConfig['storage_path']}");
        $this->line("   └─ Author:        {$repoConfig['commit_author_name']}");
        $this->line("   └─ Draft PRs:     " . ($repoConfig['create_draft_prs'] ? 'Ja' : 'Nein'));
        $this->line('');
        
        // Security Configuration
        $securityConfig = $this->config->getSecurityConfig();
        $this->line('   <comment>Sicherheit:</comment>');
        $this->line("   └─ Erlaubte Dateien: " . implode(', ', array_slice($securityConfig['allowed_file_extensions'], 0, 5)) . '...');
        $this->line("   └─ Review erforderlich: " . ($securityConfig['require_review'] ? 'Ja' : 'Nein'));
        $this->line('');
    }

    private $botInstance = null;

    public function handleShutdown($signal): void
    {
        $this->line('');
        $this->warn('🛑 Shutdown-Signal empfangen. Bot wird gestoppt...');
        
        if ($this->botInstance) {
            $this->botInstance->stop();
        }
        
        $this->info('✅ Bot erfolgreich gestoppt.');
        exit(0);
    }
}
