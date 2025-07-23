<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'jira_key',
        'name',
        'description',
        'jira_base_url',
        'project_type',
        'project_category',
        'bot_enabled',
        'required_label',
        'require_unassigned',
        'allowed_statuses',
        'fetch_interval',
        'default_repository_id',
        'custom_fields_mapping',
        'webhook_config',
        'last_sync_at',
        'total_tickets_processed',
        'successful_tickets',
        'failed_tickets',
        'is_active',
        'notes'
    ];

    protected $casts = [
        'bot_enabled' => 'boolean',
        'require_unassigned' => 'boolean',
        'allowed_statuses' => 'array',
        'fetch_interval' => 'integer',
        'custom_fields_mapping' => 'array',
        'webhook_config' => 'array',
        'last_sync_at' => 'datetime',
        'total_tickets_processed' => 'integer',
        'successful_tickets' => 'integer',
        'failed_tickets' => 'integer',
        'is_active' => 'boolean'
    ];

    /**
     * Relationships
     */
    public function defaultRepository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'default_repository_id');
    }

    public function repositories(): BelongsToMany
    {
        return $this->belongsToMany(Repository::class, 'project_repositories')
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

    public function activeRepositories(): BelongsToMany
    {
        return $this->repositories()->wherePivot('is_active', true);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
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

    public function scopeByJiraKey($query, string $jiraKey)
    {
        return $query->where('jira_key', $jiraKey);
    }

    public function scopeNeedingSync($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('last_sync_at')
              ->orWhere('last_sync_at', '<', now()->subSeconds($this->fetch_interval ?? 300));
        });
    }

    /**
     * Accessors
     */
    public function getSuccessRateAttribute(): float
    {
        if ($this->total_tickets_processed === 0) {
            return 0.0;
        }

        return round($this->successful_tickets / $this->total_tickets_processed, 4);
    }

    public function getFailureRateAttribute(): float
    {
        if ($this->total_tickets_processed === 0) {
            return 0.0;
        }

        return round($this->failed_tickets / $this->total_tickets_processed, 4);
    }

    public function getJiraUrlAttribute(): string
    {
        return rtrim($this->jira_base_url, '/') . '/projects/' . $this->jira_key;
    }

    public function getIsStaleAttribute(): bool
    {
        if (!$this->last_sync_at) {
            return true;
        }

        return $this->last_sync_at->addSeconds($this->fetch_interval)->isPast();
    }

    /**
     * Business Logic Methods
     */
    public function getRepositoryForTicket(Ticket $ticket): ?Repository
    {
        // 1. Repository-Routing basierend auf Ticket-Eigenschaften
        $repository = $this->resolveRepositoryByRouting($ticket);
        
        if ($repository) {
            return $repository;
        }

        // 2. Standard-Repository verwenden
        return $this->defaultRepository;
    }

    private function resolveRepositoryByRouting(Ticket $ticket): ?Repository
    {
        $repositories = $this->activeRepositories()
                            ->orderBy('project_repositories.priority')
                            ->get();

        foreach ($repositories as $repository) {
            $pivot = $repository->pivot;
            
            // Component-basiertes Routing
            if ($pivot->component_mapping && $this->matchesComponentMapping($ticket, $pivot->component_mapping)) {
                return $repository;
            }
            
            // Label-basiertes Routing
            if ($pivot->label_mapping && $this->matchesLabelMapping($ticket, $pivot->label_mapping)) {
                return $repository;
            }
            
            // Custom Routing Rules
            if ($pivot->ticket_routing_rules && $this->matchesRoutingRules($ticket, $pivot->ticket_routing_rules)) {
                return $repository;
            }
        }

        return null;
    }

    private function matchesComponentMapping(Ticket $ticket, array $componentMapping): bool
    {
        // Implementierung für Komponenten-Matching
        // TODO: Implementieren basierend auf Jira-Komponenten
        return false;
    }

    private function matchesLabelMapping(Ticket $ticket, array $labelMapping): bool
    {
        if (empty($ticket->labels)) {
            return false;
        }

        foreach ($labelMapping as $labelPattern) {
            foreach ($ticket->labels as $label) {
                if (fnmatch($labelPattern, $label)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function matchesRoutingRules(Ticket $ticket, array $routingRules): bool
    {
        // Implementierung für Custom Routing Rules
        // Beispiel: {"priority": ["High", "Critical"], "summary_contains": ["frontend", "ui"]}
        
        if (isset($routingRules['priority']) && !in_array($ticket->priority, $routingRules['priority'])) {
            return false;
        }

        if (isset($routingRules['summary_contains'])) {
            $summaryMatches = false;
            foreach ($routingRules['summary_contains'] as $keyword) {
                if (stripos($ticket->summary, $keyword) !== false) {
                    $summaryMatches = true;
                    break;
                }
            }
            if (!$summaryMatches) {
                return false;
            }
        }

        return true;
    }

    public function updateSyncTimestamp(): void
    {
        $this->update(['last_sync_at' => now()]);
        $this->clearCache();
    }

    public function incrementTicketStats(bool $success): void
    {
        $this->increment('total_tickets_processed');
        
        if ($success) {
            $this->increment('successful_tickets');
        } else {
            $this->increment('failed_tickets');
        }
        
        $this->clearCache();
    }

    /**
     * Cache Management
     */
    public function getCacheKey(string $suffix = ''): string
    {
        return "project:{$this->jira_key}" . ($suffix ? ":{$suffix}" : '');
    }

    public function clearCache(): void
    {
        Cache::forget($this->getCacheKey());
        Cache::forget($this->getCacheKey('repositories'));
        Cache::forget($this->getCacheKey('config'));
    }

    public function getCachedRepositories(): \Illuminate\Support\Collection
    {
        return Cache::remember($this->getCacheKey('repositories'), 3600, function () {
            return $this->activeRepositories()->get();
        });
    }

    public function getCachedConfig(): array
    {
        return Cache::remember($this->getCacheKey('config'), 3600, function () {
            return [
                'jira_key' => $this->jira_key,
                'name' => $this->name,
                'jira_base_url' => $this->jira_base_url,
                'bot_enabled' => $this->bot_enabled,
                'required_label' => $this->required_label,
                'require_unassigned' => $this->require_unassigned,
                'allowed_statuses' => $this->allowed_statuses,
                'fetch_interval' => $this->fetch_interval,
                'custom_fields_mapping' => $this->custom_fields_mapping,
            ];
        });
    }

    /**
     * Static Methods
     */
    public static function findByJiraKey(string $jiraKey): ?self
    {
        return Cache::remember("project:by_key:{$jiraKey}", 3600, function () use ($jiraKey) {
            return static::byJiraKey($jiraKey)->active()->first();
        });
    }

    public static function getActiveProjects(): \Illuminate\Support\Collection
    {
        return Cache::remember('projects:active', 1800, function () {
            return static::active()->botEnabled()->get();
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

        static::saved(function ($project) {
            $project->clearCache();
            static::clearAllCache();
        });

        static::deleted(function ($project) {
            $project->clearCache();
            static::clearAllCache();
        });
    }
} 