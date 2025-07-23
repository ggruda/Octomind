<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;

class ConfigService
{
    private static ?ConfigService $instance = null;
    private array $config = [];

    private function __construct()
    {
        $this->loadConfiguration();
    }

    public static function getInstance(): ConfigService
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadConfiguration(): void
    {
        // Bot Core Configuration
        $this->config = [
            // Core Bot Settings
            'bot' => [
                'enabled' => env('BOT_ENABLED', false),
                'simulate_mode' => env('BOT_SIMULATE_MODE', true),
                'verbose_logging' => env('BOT_VERBOSE_LOGGING', false),
                'log_level' => env('BOT_LOG_LEVEL', 'info'),
                'health_check_enabled' => env('BOT_HEALTH_CHECK_ENABLED', true),
                'monitoring_enabled' => env('BOT_MONITORING_ENABLED', true),
            ],

            // Authentication
            'auth' => [
                'github_token' => env('GITHUB_TOKEN'),
                'openai_api_key' => env('OPENAI_API_KEY'),
                'anthropic_api_key' => env('ANTHROPIC_API_KEY'),
                'jira_username' => env('JIRA_USERNAME'),
                'jira_api_token' => env('JIRA_API_TOKEN'),
                'jira_base_url' => env('JIRA_BASE_URL'),
            ],

            // Jira Configuration
            'jira' => [
                'project_key' => env('JIRA_PROJECT_KEY'),
                'fetch_interval' => env('JIRA_FETCH_INTERVAL', 300), // 5 minutes
                'required_label' => env('BOT_JIRA_REQUIRED_LABEL', 'ai-bot'),
                'require_unassigned' => env('BOT_JIRA_REQUIRE_UNASSIGNED', true),
                'allowed_statuses' => explode(',', env('BOT_JIRA_ALLOWED_STATUSES', 'Open,In Progress,To Do')),
            ],

            // Repository Management
            'repository' => [
                'storage_path' => env('BOT_REPOSITORY_STORAGE_PATH', storage_path('app/repositories')),
                'commit_author_name' => env('BOT_COMMIT_AUTHOR_NAME', 'Octomind Bot'),
                'commit_author_email' => env('BOT_COMMIT_AUTHOR_EMAIL', 'bot@octomind.com'),
                'create_draft_prs' => env('BOT_CREATE_DRAFT_PRS', true),
                'auto_merge_enabled' => env('BOT_AUTO_MERGE_ENABLED', false),
            ],

            // AI Configuration
            'ai' => [
                'primary_provider' => env('AI_PRIMARY_PROVIDER', 'openai'),
                'fallback_provider' => env('AI_FALLBACK_PROVIDER', 'claude'),
                'cloud_ai_provider' => env('CLOUD_AI_PROVIDER', 'openai'),
                'max_tokens' => env('AI_MAX_TOKENS', 4096),
                'temperature' => env('AI_TEMPERATURE', 0.7),
                'model_openai' => env('OPENAI_MODEL', 'gpt-4'),
                'model_claude' => env('ANTHROPIC_MODEL', 'claude-3-sonnet-20240229'),
            ],

            // Retry & Error Handling
            'retry' => [
                'max_attempts' => env('BOT_RETRY_MAX_ATTEMPTS', 3),
                'backoff_multiplier' => env('BOT_RETRY_BACKOFF_MULTIPLIER', 2),
                'initial_delay' => env('BOT_RETRY_INITIAL_DELAY', 5),
                'max_delay' => env('BOT_RETRY_MAX_DELAY', 300),
                'self_healing_enabled' => env('BOT_SELF_HEALING_ENABLED', true),
                'self_healing_max_rounds' => env('BOT_SELF_HEALING_MAX_ROUNDS', 5),
            ],

            // Security Rules
            'security' => [
                'allowed_file_extensions' => explode(',', env('BOT_ALLOWED_FILE_EXTENSIONS', 'php,js,ts,vue,blade.php,json,yaml,yml,md')),
                'forbidden_paths' => explode(',', env('BOT_FORBIDDEN_PATHS', '.env,.git,vendor,node_modules')),
                'max_file_size' => env('BOT_MAX_FILE_SIZE', 1048576), // 1MB
                'require_review' => env('BOT_REQUIRE_HUMAN_REVIEW', true),
                'dangerous_operations' => explode(',', env('BOT_DANGEROUS_OPERATIONS', 'delete,truncate,drop')),
            ],

            // Performance & Limits
            'performance' => [
                'max_concurrent_jobs' => env('BOT_MAX_CONCURRENT_JOBS', 3),
                'queue_timeout' => env('BOT_QUEUE_TIMEOUT', 1800), // 30 minutes
                'memory_limit' => env('BOT_MEMORY_LIMIT', '512M'),
                'execution_timeout' => env('BOT_EXECUTION_TIMEOUT', 600), // 10 minutes
            ],

            // Notification & Reporting
            'notifications' => [
                'slack_webhook' => env('SLACK_WEBHOOK_URL'),
                'email_notifications' => env('BOT_EMAIL_NOTIFICATIONS', false),
                'success_notifications' => env('BOT_SUCCESS_NOTIFICATIONS', true),
                'error_notifications' => env('BOT_ERROR_NOTIFICATIONS', true),
            ],
        ];
    }

    public function get(string $key, $default = null)
    {
        return data_get($this->config, $key, $default);
    }

    public function getBotConfig(): array
    {
        return $this->config['bot'] ?? [];
    }

    public function getAuthConfig(): array
    {
        return $this->config['auth'] ?? [];
    }

    public function getJiraConfig(): array
    {
        return $this->config['jira'] ?? [];
    }

    public function getRepositoryConfig(): array
    {
        return $this->config['repository'] ?? [];
    }

    public function getAiConfig(): array
    {
        return $this->config['ai'] ?? [];
    }

    public function getRetryConfig(): array
    {
        return $this->config['retry'] ?? [];
    }

    public function getSecurityConfig(): array
    {
        return $this->config['security'] ?? [];
    }

    public function getPerformanceConfig(): array
    {
        return $this->config['performance'] ?? [];
    }

    public function getNotificationConfig(): array
    {
        return $this->config['notifications'] ?? [];
    }

    public function isBotEnabled(): bool
    {
        return $this->get('bot.enabled', false);
    }

    public function isSimulationMode(): bool
    {
        return $this->get('bot.simulate_mode', true);
    }

    public function isVerboseLogging(): bool
    {
        return $this->get('bot.verbose_logging', false);
    }

    public function getLogLevel(): string
    {
        return $this->get('bot.log_level', 'info');
    }

    public function validateConfiguration(): array
    {
        $errors = [];

        // Check required authentication
        if (!$this->get('auth.github_token')) {
            $errors[] = 'GITHUB_TOKEN is required';
        }

        if (!$this->get('auth.openai_api_key') && !$this->get('auth.anthropic_api_key')) {
            $errors[] = 'At least one AI provider (OPENAI_API_KEY or ANTHROPIC_API_KEY) is required';
        }

        if (!$this->get('auth.jira_username') || !$this->get('auth.jira_api_token') || !$this->get('auth.jira_base_url')) {
            $errors[] = 'Jira configuration (JIRA_USERNAME, JIRA_API_TOKEN, JIRA_BASE_URL) is required';
        }

        if (!$this->get('jira.project_key')) {
            $errors[] = 'JIRA_PROJECT_KEY is required';
        }

        // Check storage path
        $storagePath = $this->get('repository.storage_path');
        if (!is_dir(dirname($storagePath))) {
            $errors[] = "Repository storage directory does not exist: " . dirname($storagePath);
        }

        return $errors;
    }

    public function reload(): void
    {
        $this->loadConfiguration();
    }
} 