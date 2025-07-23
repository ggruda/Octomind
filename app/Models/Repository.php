<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class Repository extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'full_name',
        'owner',
        'description',
        'clone_url',
        'ssh_url',
        'web_url',
        'provider',
        'provider_id',
        'default_branch',
        'is_private',
        'bot_enabled',
        'allowed_file_extensions',
        'forbidden_paths',
        'max_file_size',
        'framework_type',
        'framework_config',
        'package_manager',
        'branch_prefix',
        'create_draft_prs',
        'auto_merge_enabled',
        'pr_template',
        'ssh_key_deployed',
        'deploy_key_fingerprint',
        'ssh_key_deployed_at',
        'total_commits',
        'total_prs_created',
        'total_prs_merged',
        'last_commit_at',
        'last_pr_created_at',
        'local_path',
        'last_cloned_at',
        'last_synced_at',
        'current_commit_hash',
        'is_active',
        'notes',
        'webhook_config'
    ];

    protected $casts = [
        'is_private' => 'boolean',
        'bot_enabled' => 'boolean',
        'allowed_file_extensions' => 'array',
        'forbidden_paths' => 'array',
        'max_file_size' => 'integer',
        'framework_config' => 'array',
        'create_draft_prs' => 'boolean',
        'auto_merge_enabled' => 'boolean',
        'pr_template' => 'array',
        'ssh_key_deployed' => 'boolean',
        'ssh_key_deployed_at' => 'datetime',
        'total_commits' => 'integer',
        'total_prs_created' => 'integer',
        'total_prs_merged' => 'integer',
        'last_commit_at' => 'datetime',
        'last_pr_created_at' => 'datetime',
        'last_cloned_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'is_active' => 'boolean',
        'webhook_config' => 'array'
    ];

    /**
     * Relationships
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_repositories')
                    ->withPivot([
                        'is_default',
                        'priority',
                        'branch_strategy',
                        'custom_branch_prefix',
                        'ticket_routing_rules',
                        'component_mapping',
                        'label_mapping',
                        'tickets_processed',
                        'last_ticket_processed_at',
                        'is_active',
                        'notes'
                    ])
                    ->withTimestamps();
    }

    public function activeProjects(): BelongsToMany
    {
        return $this->projects()->wherePivot('is_active', true);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function defaultForProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'default_repository_id');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBotEnabled($query)
    {
        return $query->where('bot_enabled', true);
    }

    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    public function scopeByOwner($query, string $owner)
    {
        return $query->where('owner', $owner);
    }

    public function scopeByFullName($query, string $fullName)
    {
        return $query->where('full_name', $fullName);
    }

    public function scopeNeedingSync($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('last_synced_at')
              ->orWhere('last_synced_at', '<', now()->subHours(1));
        });
    }

    public function scopeSSHKeyDeployed($query)
    {
        return $query->where('ssh_key_deployed', true);
    }

    /**
     * Accessors
     */
    public function getProviderUrlAttribute(): string
    {
        return match($this->provider) {
            'github' => 'https://github.com/' . $this->full_name,
            'gitlab' => 'https://gitlab.com/' . $this->full_name,
            'bitbucket' => 'https://bitbucket.org/' . $this->full_name,
            'azure_devops' => $this->web_url,
            default => $this->web_url
        };
    }

    public function getPrSuccessRateAttribute(): float
    {
        if ($this->total_prs_created === 0) {
            return 0.0;
        }

        return round($this->total_prs_merged / $this->total_prs_created, 4);
    }

    public function getLocalWorkspacePathAttribute(): string
    {
        if ($this->local_path) {
            return $this->local_path;
        }

        return storage_path("app/repositories/{$this->owner}/{$this->name}");
    }

    public function getIsStaleAttribute(): bool
    {
        if (!$this->last_synced_at) {
            return true;
        }

        return $this->last_synced_at->addHour()->isPast();
    }

    public function getFrameworkDetectedAttribute(): bool
    {
        return !empty($this->framework_type);
    }

    public function getSSHKeyStatusAttribute(): string
    {
        if (!$this->ssh_key_deployed) {
            return 'not_deployed';
        }

        if (!$this->ssh_key_deployed_at) {
            return 'unknown';
        }

        if ($this->ssh_key_deployed_at->addMonths(6)->isPast()) {
            return 'needs_rotation';
        }

        return 'active';
    }

    /**
     * Business Logic Methods
     */
    public function getBranchName(string $ticketKey, string $type = 'feature'): string
    {
        $prefix = $this->branch_prefix ?? 'octomind';
        $sanitizedTicket = strtolower(str_replace([' ', '_'], '-', $ticketKey));
        
        return "{$prefix}/{$type}/{$sanitizedTicket}";
    }

    public function isFileAllowed(string $filePath): bool
    {
        // Check forbidden paths
        foreach ($this->forbidden_paths as $forbiddenPath) {
            if (str_starts_with($filePath, $forbiddenPath)) {
                return false;
            }
        }

        // Check file extension
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        return in_array($extension, $this->allowed_file_extensions);
    }

    public function getWorkspaceConfig(): array
    {
        return [
            'local_path' => $this->local_workspace_path,
            'clone_url' => $this->clone_url,
            'ssh_url' => $this->ssh_url,
            'default_branch' => $this->default_branch,
            'framework_type' => $this->framework_type,
            'package_manager' => $this->package_manager,
            'allowed_extensions' => $this->allowed_file_extensions,
            'forbidden_paths' => $this->forbidden_paths,
            'max_file_size' => $this->max_file_size
        ];
    }

    public function updateCommitStats(string $commitHash): void
    {
        $this->update([
            'total_commits' => $this->total_commits + 1,
            'last_commit_at' => now(),
            'current_commit_hash' => $commitHash
        ]);
        
        $this->clearCache();
    }

    public function updatePRStats(): void
    {
        $this->update([
            'total_prs_created' => $this->total_prs_created + 1,
            'last_pr_created_at' => now()
        ]);
        
        $this->clearCache();
    }

    public function markPRMerged(): void
    {
        $this->increment('total_prs_merged');
        $this->clearCache();
    }

    public function updateSyncTimestamp(): void
    {
        $this->update(['last_synced_at' => now()]);
        $this->clearCache();
    }

    public function markSSHKeyDeployed(string $fingerprint): void
    {
        $this->update([
            'ssh_key_deployed' => true,
            'deploy_key_fingerprint' => $fingerprint,
            'ssh_key_deployed_at' => now()
        ]);
        
        $this->clearCache();
    }

    public function detectFramework(): ?string
    {
        $workspacePath = $this->local_workspace_path;
        
        if (!is_dir($workspacePath)) {
            return null;
        }

        // Laravel Detection
        if (file_exists($workspacePath . '/artisan') && file_exists($workspacePath . '/composer.json')) {
            $composerJson = json_decode(file_get_contents($workspacePath . '/composer.json'), true);
            if (isset($composerJson['require']['laravel/framework'])) {
                return 'laravel';
            }
        }

        // Node.js Detection
        if (file_exists($workspacePath . '/package.json')) {
            $packageJson = json_decode(file_get_contents($workspacePath . '/package.json'), true);
            
            if (isset($packageJson['dependencies']['react']) || isset($packageJson['devDependencies']['react'])) {
                return 'react';
            }
            
            if (isset($packageJson['dependencies']['vue']) || isset($packageJson['devDependencies']['vue'])) {
                return 'vue';
            }
            
            if (isset($packageJson['dependencies']['next']) || isset($packageJson['devDependencies']['next'])) {
                return 'nextjs';
            }
            
            return 'nodejs';
        }

        // Python Detection
        if (file_exists($workspacePath . '/requirements.txt') || file_exists($workspacePath . '/pyproject.toml')) {
            return 'python';
        }

        return 'generic';
    }

    /**
     * Cache Management
     */
    public function getCacheKey(string $suffix = ''): string
    {
        return "repository:{$this->full_name}" . ($suffix ? ":{$suffix}" : '');
    }

    public function clearCache(): void
    {
        Cache::forget($this->getCacheKey());
        Cache::forget($this->getCacheKey('config'));
        Cache::forget($this->getCacheKey('projects'));
    }

    public function getCachedConfig(): array
    {
        return Cache::remember($this->getCacheKey('config'), 3600, function () {
            return $this->getWorkspaceConfig();
        });
    }

    public function getCachedProjects(): \Illuminate\Support\Collection
    {
        return Cache::remember($this->getCacheKey('projects'), 3600, function () {
            return $this->activeProjects()->get();
        });
    }

    /**
     * Static Methods
     */
    public static function findByFullName(string $fullName): ?self
    {
        return Cache::remember("repository:by_name:{$fullName}", 3600, function () use ($fullName) {
            return static::byFullName($fullName)->active()->first();
        });
    }

    public static function getActiveRepositories(): \Illuminate\Support\Collection
    {
        return Cache::remember('repositories:active', 1800, function () {
            return static::active()->botEnabled()->get();
        });
    }

    public static function getByProvider(string $provider): \Illuminate\Support\Collection
    {
        return Cache::remember("repositories:provider:{$provider}", 1800, function () use ($provider) {
            return static::byProvider($provider)->active()->get();
        });
    }

    public static function clearAllCache(): void
    {
        Cache::flush(); // Entferne alle Cache-Einträge da wir keine Tags verwenden können
    }

    /**
     * Model Events
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($repository) {
            // Auto-generate SSH URL if not provided
            if (!$repository->ssh_url && $repository->clone_url) {
                $repository->ssh_url = static::convertToSSHUrl($repository->clone_url);
            }
            
            // Set local path
            if (!$repository->local_path) {
                $repository->local_path = storage_path("app/repositories/{$repository->owner}/{$repository->name}");
            }
        });

        static::saved(function ($repository) {
            $repository->clearCache();
            static::clearAllCache();
        });

        static::deleted(function ($repository) {
            $repository->clearCache();
            static::clearAllCache();
        });
    }

    /**
     * Helper Methods
     */
    public static function convertToSSHUrl(string $httpsUrl): string
    {
        // Convert HTTPS to SSH URL
        $patterns = [
            'https://github.com/' => 'git@github.com:',
            'https://gitlab.com/' => 'git@gitlab.com:',
            'https://bitbucket.org/' => 'git@bitbucket.org:'
        ];

        foreach ($patterns as $https => $ssh) {
            if (str_starts_with($httpsUrl, $https)) {
                $path = str_replace($https, '', $httpsUrl);
                $path = rtrim($path, '.git') . '.git';
                return $ssh . $path;
            }
        }

        return $httpsUrl; // Fallback
    }
} 