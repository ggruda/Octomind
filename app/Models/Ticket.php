<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'jira_key',
        'summary',
        'description',
        'status',
        'jira_status',
        'priority',
        'assignee',
        'reporter',
        'repository_url',
        'labels',
        'jira_created_at',
        'jira_updated_at',
        'fetched_at',
        'processing_started_at',
        'processing_completed_at',
        'processing_duration_seconds',
        'branch_name',
        'pr_url',
        'pr_number',
        'commit_hash',
        'ai_provider_used',
        'complexity_score',
        'required_skills',
        'error_message',
        'retry_count'
    ];

    protected $casts = [
        'labels' => 'array',
        'required_skills' => 'array',
        'jira_created_at' => 'datetime',
        'jira_updated_at' => 'datetime',
        'fetched_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime',
        'processing_duration_seconds' => 'integer',
        'pr_number' => 'integer',
        'complexity_score' => 'float',
        'retry_count' => 'integer'
    ];

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', Carbon::now()->subDays($days));
    }

    /**
     * Accessors
     */
    public function getFormattedDurationAttribute(): string
    {
        if (!$this->processing_duration_seconds) {
            return 'N/A';
        }

        $seconds = $this->processing_duration_seconds;
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes > 0) {
            return "{$minutes}m {$remainingSeconds}s";
        }

        return "{$seconds}s";
    }

    public function getJiraUrlAttribute(): string
    {
        $baseUrl = config('services.jira.base_url', env('JIRA_BASE_URL'));
        return rtrim($baseUrl, '/') . '/browse/' . $this->jira_key;
    }

    public function getComplexityLevelAttribute(): string
    {
        if (!$this->complexity_score) {
            return 'unknown';
        }

        if ($this->complexity_score >= 0.8) {
            return 'very_high';
        } elseif ($this->complexity_score >= 0.6) {
            return 'high';
        } elseif ($this->complexity_score >= 0.4) {
            return 'medium';
        } elseif ($this->complexity_score >= 0.2) {
            return 'low';
        } else {
            return 'very_low';
        }
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'pending' => 'warning',
            'in_progress' => 'info',
            'completed' => 'success',
            'failed' => 'danger',
            default => 'secondary'
        };
    }

    /**
     * Mutators
     */
    public function setLabelsAttribute($value)
    {
        $this->attributes['labels'] = is_array($value) ? json_encode($value) : $value;
    }

    public function setRequiredSkillsAttribute($value)
    {
        $this->attributes['required_skills'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * Business Logic Methods
     */
    public function startProcessing(): void
    {
        $this->update([
            'status' => 'in_progress',
            'processing_started_at' => Carbon::now()
        ]);
    }

    public function completeProcessing(array $result): void
    {
        $completedAt = Carbon::now();
        $duration = $this->processing_started_at ? 
            $completedAt->diffInSeconds($this->processing_started_at) : null;

        $this->update([
            'status' => 'completed',
            'processing_completed_at' => $completedAt,
            'processing_duration_seconds' => $duration,
            'branch_name' => $result['branch'] ?? null,
            'pr_url' => $result['pr_url'] ?? null,
            'pr_number' => $result['pr_number'] ?? null,
            'commit_hash' => $result['commit_hash'] ?? null,
            'ai_provider_used' => $result['ai_provider'] ?? null,
            'error_message' => null
        ]);
    }

    public function failProcessing(string $error, bool $incrementRetry = true): void
    {
        $data = [
            'status' => 'failed',
            'error_message' => $error
        ];

        if ($incrementRetry) {
            $data['retry_count'] = ($this->retry_count ?? 0) + 1;
        }

        $this->update($data);
    }

    public function canRetry(): bool
    {
        $maxRetries = config('octomind.max_retries', 3);
        return ($this->retry_count ?? 0) < $maxRetries;
    }

    public function resetForRetry(): void
    {
        $this->update([
            'status' => 'pending',
            'processing_started_at' => null,
            'processing_completed_at' => null,
            'processing_duration_seconds' => null,
            'error_message' => null
        ]);
    }

    public function isStale(): bool
    {
        if ($this->status !== 'in_progress') {
            return false;
        }

        $staleThreshold = config('octomind.stale_threshold_minutes', 30);
        return $this->processing_started_at && 
               $this->processing_started_at->addMinutes($staleThreshold)->isPast();
    }

    /**
     * Static Methods
     */
    public static function findByJiraKey(string $jiraKey): ?self
    {
        return static::where('jira_key', $jiraKey)->first();
    }

    public static function getProcessingStats(): array
    {
        return [
            'total' => static::count(),
            'pending' => static::pending()->count(),
            'in_progress' => static::inProgress()->count(),
            'completed' => static::completed()->count(),
            'failed' => static::failed()->count(),
            'recent_completed' => static::completed()->recent()->count(),
            'average_duration' => static::completed()
                ->whereNotNull('processing_duration_seconds')
                ->avg('processing_duration_seconds')
        ];
    }

    public static function getStaleTickets(): \Illuminate\Database\Eloquent\Collection
    {
        $staleThreshold = config('octomind.stale_threshold_minutes', 30);
        
        return static::inProgress()
            ->where('processing_started_at', '<', Carbon::now()->subMinutes($staleThreshold))
            ->get();
    }
} 