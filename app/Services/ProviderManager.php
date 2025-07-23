<?php

namespace App\Services;

use App\Contracts\TicketProviderInterface;
use App\Contracts\VersionControlProviderInterface;
use App\Contracts\AIProviderInterface;
use Exception;

class ProviderManager
{
    private ConfigService $config;
    private LogService $logger;

    // Registrierte Provider
    private array $ticketProviders = [];
    private array $vcsProviders = [];
    private array $aiProviders = [];

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
        
        $this->registerDefaultProviders();
    }

    /**
     * Registriert Standard-Provider
     */
    private function registerDefaultProviders(): void
    {
        // Ticket-Provider
        $this->registerTicketProvider('jira', JiraService::class);
        $this->registerTicketProvider('linear', LinearService::class);
        
        // VCS-Provider
        $this->registerVCSProvider('github', GitHubService::class);
        $this->registerVCSProvider('gitlab', GitLabService::class);
        
        // AI-Provider (CloudAIService enthält bereits OpenAI + Claude)
        $this->registerAIProvider('openai', CloudAIService::class);
        $this->registerAIProvider('claude', CloudAIService::class);
    }

    /**
     * Ticket-Provider-Methoden
     */
    public function registerTicketProvider(string $name, string $className): void
    {
        $this->ticketProviders[$name] = $className;
    }

    public function getTicketProvider(?string $providerName = null): TicketProviderInterface
    {
        $providerName = $providerName ?? $this->config->get('ticket.default_provider', 'jira');
        
        if (!isset($this->ticketProviders[$providerName])) {
            throw new Exception("Ticket-Provider '{$providerName}' nicht registriert");
        }

        $className = $this->ticketProviders[$providerName];
        return new $className();
    }

    public function getAvailableTicketProviders(): array
    {
        $providers = [];
        
        foreach ($this->ticketProviders as $name => $className) {
            try {
                $provider = new $className();
                $validation = $provider->validateConfiguration();
                
                $providers[$name] = [
                    'name' => $provider->getProviderName(),
                    'class' => $className,
                    'configured' => empty($validation),
                    'errors' => $validation,
                    'supported_statuses' => $provider->getSupportedStatuses()
                ];
            } catch (Exception $e) {
                $providers[$name] = [
                    'name' => $name,
                    'class' => $className,
                    'configured' => false,
                    'errors' => [$e->getMessage()],
                    'supported_statuses' => []
                ];
            }
        }

        return $providers;
    }

    /**
     * VCS-Provider-Methoden
     */
    public function registerVCSProvider(string $name, string $className): void
    {
        $this->vcsProviders[$name] = $className;
    }

    public function getVCSProvider(?string $providerName = null): VersionControlProviderInterface
    {
        $providerName = $providerName ?? $this->config->get('vcs.default_provider', 'github');
        
        if (!isset($this->vcsProviders[$providerName])) {
            throw new Exception("VCS-Provider '{$providerName}' nicht registriert");
        }

        $className = $this->vcsProviders[$providerName];
        return new $className();
    }

    public function getAvailableVCSProviders(): array
    {
        $providers = [];
        
        foreach ($this->vcsProviders as $name => $className) {
            try {
                $provider = new $className();
                $validation = $provider->validateConfiguration();
                
                $providers[$name] = [
                    'name' => $provider->getProviderName(),
                    'class' => $className,
                    'configured' => empty($validation),
                    'errors' => $validation,
                    'supported_types' => $provider->getSupportedRepositoryTypes()
                ];
            } catch (Exception $e) {
                $providers[$name] = [
                    'name' => $name,
                    'class' => $className,
                    'configured' => false,
                    'errors' => [$e->getMessage()],
                    'supported_types' => []
                ];
            }
        }

        return $providers;
    }

    /**
     * AI-Provider-Methoden
     */
    public function registerAIProvider(string $name, string $className): void
    {
        $this->aiProviders[$name] = $className;
    }

    public function getAIProvider(?string $providerName = null): AIProviderInterface
    {
        $providerName = $providerName ?? $this->config->get('ai.primary_provider', 'openai');
        
        if (!isset($this->aiProviders[$providerName])) {
            throw new Exception("AI-Provider '{$providerName}' nicht registriert");
        }

        $className = $this->aiProviders[$providerName];
        return new $className();
    }

    public function getAvailableAIProviders(): array
    {
        $providers = [];
        
        foreach ($this->aiProviders as $name => $className) {
            try {
                $provider = new $className();
                $validation = $provider->validateConfiguration();
                
                $providers[$name] = [
                    'name' => $provider->getProviderName(),
                    'class' => $className,
                    'configured' => empty($validation),
                    'errors' => $validation,
                    'supported_models' => $provider->getSupportedModels(),
                    'max_tokens' => $provider->getMaxTokens()
                ];
            } catch (Exception $e) {
                $providers[$name] = [
                    'name' => $name,
                    'class' => $className,
                    'configured' => false,
                    'errors' => [$e->getMessage()],
                    'supported_models' => [],
                    'max_tokens' => 0
                ];
            }
        }

        return $providers;
    }

    /**
     * Provider-Auswahl basierend auf Repository-URL
     */
    public function detectVCSProviderFromUrl(string $repositoryUrl): ?string
    {
        if (str_contains($repositoryUrl, 'github.com')) {
            return 'github';
        }
        
        if (str_contains($repositoryUrl, 'gitlab.com') || str_contains($repositoryUrl, 'gitlab.')) {
            return 'gitlab';
        }
        
        if (str_contains($repositoryUrl, 'bitbucket.org')) {
            return 'bitbucket';
        }

        return null;
    }

    /**
     * Testet alle konfigurierten Provider
     */
    public function testAllProviders(): array
    {
        $results = [
            'ticket_providers' => [],
            'vcs_providers' => [],
            'ai_providers' => []
        ];

        // Teste Ticket-Provider
        foreach ($this->getAvailableTicketProviders() as $name => $info) {
            if ($info['configured']) {
                try {
                    $provider = $this->getTicketProvider($name);
                    $testResult = $provider->testConnection();
                    $results['ticket_providers'][$name] = $testResult;
                } catch (Exception $e) {
                    $results['ticket_providers'][$name] = [
                        'success' => false,
                        'message' => $e->getMessage()
                    ];
                }
            } else {
                $results['ticket_providers'][$name] = [
                    'success' => false,
                    'message' => 'Provider nicht konfiguriert'
                ];
            }
        }

        // Teste VCS-Provider
        foreach ($this->getAvailableVCSProviders() as $name => $info) {
            if ($info['configured']) {
                try {
                    $provider = $this->getVCSProvider($name);
                    $testResult = $provider->testConnection();
                    $results['vcs_providers'][$name] = $testResult;
                } catch (Exception $e) {
                    $results['vcs_providers'][$name] = [
                        'success' => false,
                        'message' => $e->getMessage()
                    ];
                }
            } else {
                $results['vcs_providers'][$name] = [
                    'success' => false,
                    'message' => 'Provider nicht konfiguriert'
                ];
            }
        }

        // Teste AI-Provider
        foreach ($this->getAvailableAIProviders() as $name => $info) {
            if ($info['configured']) {
                try {
                    $provider = $this->getAIProvider($name);
                    $testResult = $provider->testConnection();
                    $results['ai_providers'][$name] = $testResult;
                } catch (Exception $e) {
                    $results['ai_providers'][$name] = [
                        'success' => false,
                        'message' => $e->getMessage()
                    ];
                }
            } else {
                $results['ai_providers'][$name] = [
                    'success' => false,
                    'message' => 'Provider nicht konfiguriert'
                ];
            }
        }

        return $results;
    }

    /**
     * Wählt besten verfügbaren Provider basierend auf Konfiguration und Tests
     */
    public function selectBestProvider(string $type): ?string
    {
        $availableProviders = match($type) {
            'ticket' => $this->getAvailableTicketProviders(),
            'vcs' => $this->getAvailableVCSProviders(),
            'ai' => $this->getAvailableAIProviders(),
            default => []
        };

        // Filtere nur konfigurierte Provider
        $configuredProviders = array_filter(
            $availableProviders, 
            fn($provider) => $provider['configured']
        );

        if (empty($configuredProviders)) {
            return null;
        }

        // Bevorzuge Provider aus Konfiguration
        $preferredProvider = match($type) {
            'ticket' => $this->config->get('ticket.default_provider'),
            'vcs' => $this->config->get('vcs.default_provider'),
            'ai' => $this->config->get('ai.primary_provider'),
            default => null
        };

        if ($preferredProvider && isset($configuredProviders[$preferredProvider])) {
            return $preferredProvider;
        }

        // Fallback: Ersten verfügbaren Provider nehmen
        return array_key_first($configuredProviders);
    }

    /**
     * Gibt Übersicht über alle Provider zurück
     */
    public function getProviderOverview(): array
    {
        return [
            'ticket_providers' => $this->getAvailableTicketProviders(),
            'vcs_providers' => $this->getAvailableVCSProviders(),
            'ai_providers' => $this->getAvailableAIProviders(),
            'recommendations' => [
                'best_ticket_provider' => $this->selectBestProvider('ticket'),
                'best_vcs_provider' => $this->selectBestProvider('vcs'),
                'best_ai_provider' => $this->selectBestProvider('ai')
            ]
        ];
    }
} 