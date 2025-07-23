<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bot Configuration
    |--------------------------------------------------------------------------
    |
    | Konfiguration für den Octomind Bot
    |
    */

    'bot' => [
        // Ticket-Loading-Intervall in Minuten
        'ticket_load_interval_minutes' => env('OCTOMIND_TICKET_LOAD_INTERVAL', 2),
        
        // Maximale Verarbeitungszeit pro Ticket in Sekunden
        'max_processing_time_seconds' => env('OCTOMIND_MAX_PROCESSING_TIME', 3600),
        
        // Retry-Versuche bei fehlgeschlagenen Tickets
        'max_retry_attempts' => env('OCTOMIND_MAX_RETRY_ATTEMPTS', 3),
        
        // Wartezeit zwischen Retry-Versuchen in Sekunden
        'retry_delay_seconds' => env('OCTOMIND_RETRY_DELAY', 300),
        
        // Automatischer Neustart bei Fehlern
        'auto_restart_on_error' => env('OCTOMIND_AUTO_RESTART', true),
        
        // Debug-Modus
        'debug_mode' => env('OCTOMIND_DEBUG', false),
        
        // Logging-Level (debug, info, warning, error)
        'log_level' => env('OCTOMIND_LOG_LEVEL', 'info'),
        
        // Maximale Anzahl gleichzeitiger Ticket-Verarbeitungen
        'max_concurrent_tickets' => env('OCTOMIND_MAX_CONCURRENT', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Management
    |--------------------------------------------------------------------------
    |
    | Konfiguration für Bot-Sessions und Stunden-Management
    |
    */

    'sessions' => [
        // Standard-Stunden für neue Sessions
        'default_hours' => env('OCTOMIND_DEFAULT_HOURS', 10),
        
        // Warnung bei Verbrauch von X% der Stunden
        'warning_thresholds' => [
            'first' => env('OCTOMIND_WARNING_75', 75),
            'second' => env('OCTOMIND_WARNING_90', 90),
        ],
        
        // Automatische Session-Verlängerung bei Premium-Kunden
        'auto_extend_premium' => env('OCTOMIND_AUTO_EXTEND_PREMIUM', false),
        
        // Anzahl Stunden für automatische Verlängerung
        'auto_extend_hours' => env('OCTOMIND_AUTO_EXTEND_HOURS', 5),
        
        // Session-Cleanup nach X Tagen
        'cleanup_after_days' => env('OCTOMIND_SESSION_CLEANUP_DAYS', 90),
        
        // Maximale Anzahl Sessions pro Kunde
        'max_sessions_per_customer' => env('OCTOMIND_MAX_SESSIONS_PER_CUSTOMER', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Notifications
    |--------------------------------------------------------------------------
    |
    | Konfiguration für Email-Benachrichtigungen
    |
    */

    'email' => [
        // Absender-Adresse
        'from_address' => env('OCTOMIND_FROM_EMAIL', 'noreply@octomind.com'),
        
        // Absender-Name
        'from_name' => env('OCTOMIND_FROM_NAME', 'Octomind Bot'),
        
        // Interne Email für Expiry-Benachrichtigungen
        'internal_expiry_email' => env('OCTOMIND_INTERNAL_EMAIL', 'hours-expired@octomind.com'),
        
        // Support-Email
        'support_email' => env('OCTOMIND_SUPPORT_EMAIL', 'support@octomind.com'),
        
        // Email-Templates aktivieren
        'use_templates' => env('OCTOMIND_USE_EMAIL_TEMPLATES', false),
        
        // Test-Modus (Emails werden nicht versendet)
        'test_mode' => env('OCTOMIND_EMAIL_TEST_MODE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Repository Management
    |--------------------------------------------------------------------------
    |
    | Konfiguration für Repository-Verwaltung
    |
    */

    'repository' => [
        // Standard-Workspace-Pfad
        'workspace_base_path' => env('OCTOMIND_WORKSPACE_PATH', storage_path('app/octomind/workspaces')),
        
        // SSH-Keys-Pfad
        'ssh_keys_path' => env('OCTOMIND_SSH_KEYS_PATH', storage_path('app/octomind/ssh-keys')),
        
        // Standard-Branch für neue Repositories
        'default_branch' => env('OCTOMIND_DEFAULT_BRANCH', 'main'),
        
        // Branch-Prefix für Bot-Branches
        'branch_prefix' => env('OCTOMIND_BRANCH_PREFIX', 'octomind/'),
        
        // Commit-Author-Name
        'commit_author_name' => env('OCTOMIND_COMMIT_AUTHOR_NAME', 'Octomind Bot'),
        
        // Commit-Author-Email
        'commit_author_email' => env('OCTOMIND_COMMIT_AUTHOR_EMAIL', 'bot@octomind.com'),
        
        // Automatisches Repository-Cleanup
        'auto_cleanup_workspaces' => env('OCTOMIND_AUTO_CLEANUP_WORKSPACES', true),
        
        // Cleanup nach X Tagen Inaktivität
        'cleanup_after_days' => env('OCTOMIND_WORKSPACE_CLEANUP_DAYS', 30),
        
        // Maximale Workspace-Größe in MB
        'max_workspace_size_mb' => env('OCTOMIND_MAX_WORKSPACE_SIZE', 1000),
        
        // Git-Timeout in Sekunden
        'git_timeout_seconds' => env('OCTOMIND_GIT_TIMEOUT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Jira Configuration
    |--------------------------------------------------------------------------
    |
    | Standard-Konfiguration für Jira-Integration
    |
    */

    'jira' => [
        // Standard-JQL-Filter
        'default_jql_filter' => env('OCTOMIND_DEFAULT_JQL', 'status IN ("To Do", "In Progress") AND labels = "octomind-bot"'),
        
        // Automatische Status-Updates
        'auto_update_status' => env('OCTOMIND_JIRA_AUTO_UPDATE_STATUS', true),
        
        // Status nach erfolgreicher Verarbeitung
        'completed_status' => env('OCTOMIND_JIRA_COMPLETED_STATUS', 'In Review'),
        
        // Status bei Fehlern
        'failed_status' => env('OCTOMIND_JIRA_FAILED_STATUS', 'Failed'),
        
        // Maximale Tickets pro Projekt-Load
        'max_tickets_per_load' => env('OCTOMIND_JIRA_MAX_TICKETS_PER_LOAD', 50),
        
        // Jira-API-Timeout in Sekunden
        'api_timeout_seconds' => env('OCTOMIND_JIRA_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Configuration
    |--------------------------------------------------------------------------
    |
    | Konfiguration für AI-Provider
    |
    */

    'ai' => [
        // Standard-AI-Provider
        'default_provider' => env('OCTOMIND_AI_PROVIDER', 'openai'),
        
        // Verfügbare Provider
        'providers' => [
            'openai' => [
                'model' => env('OCTOMIND_OPENAI_MODEL', 'gpt-4'),
                'max_tokens' => env('OCTOMIND_OPENAI_MAX_TOKENS', 4000),
                'temperature' => env('OCTOMIND_OPENAI_TEMPERATURE', 0.3),
            ],
            'anthropic' => [
                'model' => env('OCTOMIND_ANTHROPIC_MODEL', 'claude-3-sonnet-20240229'),
                'max_tokens' => env('OCTOMIND_ANTHROPIC_MAX_TOKENS', 4000),
                'temperature' => env('OCTOMIND_ANTHROPIC_TEMPERATURE', 0.3),
            ],
        ],
        
        // Fallback-Provider bei Fehlern
        'fallback_provider' => env('OCTOMIND_AI_FALLBACK_PROVIDER', 'anthropic'),
        
        // Maximale Retry-Versuche für AI-Requests
        'max_retries' => env('OCTOMIND_AI_MAX_RETRIES', 3),
        
        // Timeout für AI-Requests in Sekunden
        'request_timeout_seconds' => env('OCTOMIND_AI_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance & Monitoring
    |--------------------------------------------------------------------------
    |
    | Konfiguration für Performance-Monitoring
    |
    */

    'performance' => [
        // Performance-Monitoring aktivieren
        'enable_monitoring' => env('OCTOMIND_ENABLE_MONITORING', true),
        
        // Metriken sammeln
        'collect_metrics' => env('OCTOMIND_COLLECT_METRICS', true),
        
        // Detaillierte Logs für Performance-Analyse
        'detailed_performance_logs' => env('OCTOMIND_DETAILED_PERF_LOGS', false),
        
        // Memory-Limit für Bot-Prozesse in MB
        'memory_limit_mb' => env('OCTOMIND_MEMORY_LIMIT', 512),
        
        // CPU-Limit für Bot-Prozesse (0-100%)
        'cpu_limit_percent' => env('OCTOMIND_CPU_LIMIT', 80),
        
        // Health-Check-Intervall in Sekunden
        'health_check_interval_seconds' => env('OCTOMIND_HEALTH_CHECK_INTERVAL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Sicherheits-Konfiguration
    |
    */

    'security' => [
        // SSH-Key-Verschlüsselung
        'encrypt_ssh_keys' => env('OCTOMIND_ENCRYPT_SSH_KEYS', true),
        
        // Sichere Token-Speicherung
        'secure_token_storage' => env('OCTOMIND_SECURE_TOKENS', true),
        
        // IP-Whitelist für Bot-API (comma-separated)
        'api_ip_whitelist' => env('OCTOMIND_API_WHITELIST', ''),
        
        // Rate-Limiting für API-Endpoints
        'api_rate_limit' => env('OCTOMIND_API_RATE_LIMIT', 60),
        
        // Audit-Logging aktivieren
        'enable_audit_logging' => env('OCTOMIND_ENABLE_AUDIT_LOGGING', true),
        
        // Automatische Security-Updates
        'auto_security_updates' => env('OCTOMIND_AUTO_SECURITY_UPDATES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Cache-Konfiguration für bessere Performance
    |
    */

    'cache' => [
        // Cache-Prefix für Octomind-Keys
        'prefix' => env('OCTOMIND_CACHE_PREFIX', 'octomind'),
        
        // Standard-TTL für Cache-Einträge in Sekunden
        'default_ttl' => env('OCTOMIND_CACHE_TTL', 3600),
        
        // Cache-Tags aktivieren
        'enable_tags' => env('OCTOMIND_CACHE_TAGS', true),
        
        // Spezifische TTLs
        'ttl' => [
            'projects' => env('OCTOMIND_CACHE_TTL_PROJECTS', 1800),
            'repositories' => env('OCTOMIND_CACHE_TTL_REPOSITORIES', 1800),
            'tickets' => env('OCTOMIND_CACHE_TTL_TICKETS', 300),
            'sessions' => env('OCTOMIND_CACHE_TTL_SESSIONS', 600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Development & Testing
    |--------------------------------------------------------------------------
    |
    | Konfiguration für Entwicklung und Testing
    |
    */

    'development' => [
        // Entwicklungsmodus
        'dev_mode' => env('OCTOMIND_DEV_MODE', false),
        
        // Mock-Services verwenden
        'use_mocks' => env('OCTOMIND_USE_MOCKS', false),
        
        // Test-Daten generieren
        'generate_test_data' => env('OCTOMIND_GENERATE_TEST_DATA', false),
        
        // Fake-Ticket-Verarbeitung (für Tests)
        'fake_processing' => env('OCTOMIND_FAKE_PROCESSING', false),
        
        // Entwickler-Dashboard aktivieren
        'enable_dev_dashboard' => env('OCTOMIND_ENABLE_DEV_DASHBOARD', false),
    ],
]; 