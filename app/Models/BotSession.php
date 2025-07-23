<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class BotSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'customer_email',
        'customer_name',
        'purchased_hours',
        'consumed_hours',
        'remaining_hours',
        'status',
        'started_at',
        'paused_at',
        'expired_at',
        'last_activity_at',
        'tickets_processed',
        'tickets_successful',
        'tickets_failed',
        'warning_75_sent',
        'warning_90_sent',
        'expiry_notification_sent',
        'bot_config',
        'notes'
    ];

    protected $casts = [
        'purchased_hours' => 'decimal:2',
        'consumed_hours' => 'decimal:2',
        'remaining_hours' => 'decimal:2',
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'expired_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'warning_75_sent' => 'boolean',
        'warning_90_sent' => 'boolean',
        'expiry_notification_sent' => 'boolean',
        'bot_config' => 'array'
    ];

    /**
     * Tickets, die in dieser Session verarbeitet wurden
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Prüft ob Session noch aktiv sein kann
     */
    public function canBeActive(): bool
    {
        return $this->remaining_hours > 0 && 
               $this->status !== 'expired' && 
               $this->status !== 'cancelled';
    }

    /**
     * Prüft ob Session abgelaufen ist
     */
    public function isExpired(): bool
    {
        return $this->remaining_hours <= 0 || $this->status === 'expired';
    }

    /**
     * Berechnet Verbrauchsrate pro Stunde
     */
    public function getHourlyConsumptionRate(): float
    {
        if ($this->tickets_processed === 0) {
            return 0;
        }

        return $this->consumed_hours / $this->tickets_processed;
    }

    /**
     * Geschätzte verbleibende Tickets basierend auf bisherigem Verbrauch
     */
    public function getEstimatedRemainingTickets(): int
    {
        $avgHoursPerTicket = $this->getHourlyConsumptionRate();
        
        if ($avgHoursPerTicket <= 0) {
            return 0;
        }

        return (int) floor($this->remaining_hours / $avgHoursPerTicket);
    }

    /**
     * Verbrauchsfortschritt in Prozent
     */
    public function getConsumptionPercentage(): float
    {
        if ($this->purchased_hours <= 0) {
            return 0;
        }

        return ($this->consumed_hours / $this->purchased_hours) * 100;
    }

    /**
     * Prüft ob Warnung bei 75% gesendet werden soll
     */
    public function shouldSend75Warning(): bool
    {
        return !$this->warning_75_sent && 
               $this->getConsumptionPercentage() >= 75;
    }

    /**
     * Prüft ob Warnung bei 90% gesendet werden soll
     */
    public function shouldSend90Warning(): bool
    {
        return !$this->warning_90_sent && 
               $this->getConsumptionPercentage() >= 90;
    }

    /**
     * Markiert Session als abgelaufen
     */
    public function markExpired(): void
    {
        $this->update([
            'status' => 'expired',
            'expired_at' => now(),
            'remaining_hours' => 0
        ]);
    }

    /**
     * Pausiert Session
     */
    public function pause(): void
    {
        $this->update([
            'status' => 'paused',
            'paused_at' => now()
        ]);
    }

    /**
     * Aktiviert Session wieder
     */
    public function resume(): void
    {
        if ($this->canBeActive()) {
            $this->update([
                'status' => 'active',
                'paused_at' => null
            ]);
        }
    }

    /**
     * Verbraucht Stunden für Ticket-Verarbeitung
     */
    public function consumeHours(float $hours, bool $successful = true): void
    {
        $newConsumedHours = $this->consumed_hours + $hours;
        $newRemainingHours = max(0, $this->purchased_hours - $newConsumedHours);

        $this->update([
            'consumed_hours' => $newConsumedHours,
            'remaining_hours' => $newRemainingHours,
            'tickets_processed' => $this->tickets_processed + 1,
            'tickets_successful' => $successful ? $this->tickets_successful + 1 : $this->tickets_successful,
            'tickets_failed' => $successful ? $this->tickets_failed : $this->tickets_failed + 1,
            'last_activity_at' => now()
        ]);

        // Prüfen ob Session abgelaufen ist
        if ($newRemainingHours <= 0) {
            $this->markExpired();
        }
    }

    /**
     * Generiert Session-Report
     */
    public function generateReport(): array
    {
        return [
            'session_id' => $this->session_id,
            'customer' => [
                'email' => $this->customer_email,
                'name' => $this->customer_name
            ],
            'hours' => [
                'purchased' => $this->purchased_hours,
                'consumed' => $this->consumed_hours,
                'remaining' => $this->remaining_hours,
                'consumption_percentage' => round($this->getConsumptionPercentage(), 2)
            ],
            'tickets' => [
                'total_processed' => $this->tickets_processed,
                'successful' => $this->tickets_successful,
                'failed' => $this->tickets_failed,
                'success_rate' => $this->tickets_processed > 0 
                    ? round(($this->tickets_successful / $this->tickets_processed) * 100, 2) 
                    : 0
            ],
            'performance' => [
                'avg_hours_per_ticket' => round($this->getHourlyConsumptionRate(), 4),
                'estimated_remaining_tickets' => $this->getEstimatedRemainingTickets()
            ],
            'status' => [
                'current' => $this->status,
                'started_at' => $this->started_at?->toISOString(),
                'last_activity' => $this->last_activity_at?->toISOString(),
                'expired_at' => $this->expired_at?->toISOString()
            ]
        ];
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where('remaining_hours', '>', 0);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired')->orWhere('remaining_hours', '<=', 0);
    }

    public function scopeNeedsWarning($query)
    {
        return $query->where(function($q) {
            $q->where(function($subQ) {
                // 75% Warnung
                $subQ->where('warning_75_sent', false)
                     ->whereRaw('(consumed_hours / purchased_hours) >= 0.75');
            })->orWhere(function($subQ) {
                // 90% Warnung  
                $subQ->where('warning_90_sent', false)
                     ->whereRaw('(consumed_hours / purchased_hours) >= 0.90');
            });
        });
    }

    /**
     * Erstellt neue Session
     */
    public static function createSession(
        string $customerEmail, 
        float $purchasedHours, 
        ?string $customerName = null,
        ?array $botConfig = null
    ): self {
        return self::create([
            'session_id' => 'session_' . uniqid() . '_' . time(),
            'customer_email' => $customerEmail,
            'customer_name' => $customerName,
            'purchased_hours' => $purchasedHours,
            'consumed_hours' => 0,
            'remaining_hours' => $purchasedHours,
            'status' => 'active',
            'started_at' => now(),
            'last_activity_at' => now(),
            'bot_config' => $botConfig ?? []
        ]);
    }
} 