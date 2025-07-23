<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class TicketTodo extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'title',
        'description',
        'priority',
        'estimated_hours',
        'category',
        'status',
        'order_index',
        'actual_hours',
        'dependencies',
        'acceptance_criteria',
        'ai_generated',
        'ai_provider_used',
        'ai_confidence',
        'started_at',
        'completed_at',
        'processing_duration_seconds',
        'branch_name',
        'commit_hash',
        'code_changes',
        'error_message',
        'retry_count'
    ];

    protected $casts = [
        'estimated_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'ai_confidence' => 'decimal:2',
        'dependencies' => 'array',
        'acceptance_criteria' => 'array',
        'ai_generated' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'processing_duration_seconds' => 'integer',
        'priority' => 'integer',
        'order_index' => 'integer',
        'retry_count' => 'integer'
    ];

    /**
     * Beziehungen
     */
    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

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

    public function scopeBlocked($query)
    {
        return $query->where('status', 'blocked');
    }

    public function scopeAiGenerated($query)
    {
        return $query->where('ai_generated', true);
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'asc');
    }

    public function scopeByOrder($query)
    {
        return $query->orderBy('order_index', 'asc');
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
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

    public function getPriorityLabelAttribute(): string
    {
        return match($this->priority) {
            1 => 'Kritisch',
            2 => 'Hoch',
            3 => 'Mittel',
            4 => 'Niedrig',
            5 => 'Sehr niedrig',
            default => 'Unbekannt'
        };
    }

    public function getPriorityColorAttribute(): string
    {
        return match($this->priority) {
            1 => 'danger',
            2 => 'warning',
            3 => 'info',
            4 => 'secondary',
            5 => 'light',
            default => 'secondary'
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'pending' => 'warning',
            'in_progress' => 'info',
            'completed' => 'success',
            'blocked' => 'danger',
            'cancelled' => 'secondary',
            default => 'secondary'
        };
    }

    public function getCategoryIconAttribute(): string
    {
        return match($this->category) {
            'backend' => '⚙️',
            'frontend' => '🎨',
            'database' => '🗄️',
            'testing' => '🧪',
            'deployment' => '🚀',
            'planning' => '📋',
            default => '📝'
        };
    }

    public function getProgressPercentageAttribute(): int
    {
        return match($this->status) {
            'pending' => 0,
            'in_progress' => 50,
            'completed' => 100,
            'blocked' => 25,
            'cancelled' => 0,
            default => 0
        };
    }

    /**
     * Business Logic Methods
     */
    public function startProcessing(): void
    {
        $this->update([
            'status' => 'in_progress',
            'started_at' => Carbon::now()
        ]);
    }

    public function completeProcessing(array $result = []): void
    {
        $completedAt = Carbon::now();
        $duration = $this->started_at ? 
            $completedAt->diffInSeconds($this->started_at) : null;

        $updateData = [
            'status' => 'completed',
            'completed_at' => $completedAt,
            'processing_duration_seconds' => $duration,
            'error_message' => null
        ];

        // Zusätzliche Daten aus dem Result übernehmen
        if (isset($result['branch_name'])) {
            $updateData['branch_name'] = $result['branch_name'];
        }
        if (isset($result['commit_hash'])) {
            $updateData['commit_hash'] = $result['commit_hash'];
        }
        if (isset($result['code_changes'])) {
            $updateData['code_changes'] = $result['code_changes'];
        }
        if (isset($result['actual_hours'])) {
            $updateData['actual_hours'] = $result['actual_hours'];
        }

        $this->update($updateData);
    }

    public function failProcessing(string $error, bool $incrementRetry = true): void
    {
        $data = [
            'status' => 'blocked',
            'error_message' => $error
        ];

        if ($incrementRetry) {
            $data['retry_count'] = $this->retry_count + 1;
        }

        $this->update($data);
    }

    public function canRetry(): bool
    {
        $maxRetries = config('octomind.max_todo_retries', 2);
        return $this->retry_count < $maxRetries && $this->status === 'blocked';
    }

    public function resetForRetry(): void
    {
        $this->update([
            'status' => 'pending',
            'started_at' => null,
            'completed_at' => null,
            'processing_duration_seconds' => null,
            'error_message' => null
        ]);
    }

    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }

    public function canStart(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        // Prüfe Abhängigkeiten
        if (!empty($this->dependencies)) {
            foreach ($this->dependencies as $dependency) {
                $dependentTodo = static::where('ticket_id', $this->ticket_id)
                    ->where(function($query) use ($dependency) {
                        $query->where('title', $dependency)
                              ->orWhere('id', $dependency);
                    })
                    ->first();

                if ($dependentTodo && $dependentTodo->status !== 'completed') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Static Methods
     */
    public static function getNextAvailableTodo(int $ticketId): ?self
    {
        return static::where('ticket_id', $ticketId)
            ->where('status', 'pending')
            ->whereHas('ticket', function($query) {
                $query->where('status', 'in_progress');
            })
            ->byPriority()
            ->byOrder()
            ->get()
            ->first(function($todo) {
                return $todo->canStart();
            });
    }

    public static function getProgressStats(int $ticketId): array
    {
        $todos = static::where('ticket_id', $ticketId)->get();
        $total = $todos->count();
        
        if ($total === 0) {
            return [
                'total' => 0,
                'completed' => 0,
                'in_progress' => 0,
                'pending' => 0,
                'blocked' => 0,
                'progress_percentage' => 0
            ];
        }

        $completed = $todos->where('status', 'completed')->count();
        $inProgress = $todos->where('status', 'in_progress')->count();
        $pending = $todos->where('status', 'pending')->count();
        $blocked = $todos->where('status', 'blocked')->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'in_progress' => $inProgress,
            'pending' => $pending,
            'blocked' => $blocked,
            'progress_percentage' => round(($completed / $total) * 100, 1)
        ];
    }
} 