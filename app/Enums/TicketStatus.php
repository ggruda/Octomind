<?php

namespace App\Enums;

enum TicketStatus: string
{
    case PENDING = 'pending';
    case ANALYZING = 'analyzing';
    case GENERATING_SOLUTION = 'generating_solution';
    case EXECUTING = 'executing';
    case CREATING_PR = 'creating_pr';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case RETRYING = 'retrying';
    case CANCELLED = 'cancelled';
    case REQUIRES_REVIEW = 'requires_review';

    public function getDescription(): string
    {
        return match($this) {
            self::PENDING => 'Ticket wartet auf Verarbeitung',
            self::ANALYZING => 'Ticket wird analysiert',
            self::GENERATING_SOLUTION => 'Lösung wird generiert',
            self::EXECUTING => 'Code wird ausgeführt',
            self::CREATING_PR => 'Pull Request wird erstellt',
            self::COMPLETED => 'Ticket erfolgreich abgeschlossen',
            self::FAILED => 'Ticket konnte nicht verarbeitet werden',
            self::RETRYING => 'Ticket wird erneut versucht',
            self::CANCELLED => 'Ticket wurde abgebrochen',
            self::REQUIRES_REVIEW => 'Ticket benötigt menschliche Überprüfung',
        };
    }

    public function isTerminal(): bool
    {
        return match($this) {
            self::COMPLETED, self::FAILED, self::CANCELLED => true,
            default => false,
        };
    }

    public function canRetry(): bool
    {
        return match($this) {
            self::FAILED, self::REQUIRES_REVIEW => true,
            default => false,
        };
    }
} 