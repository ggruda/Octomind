<?php

namespace App\Enums;

enum BotStatus: string
{
    case IDLE = 'idle';
    case PROCESSING = 'processing';
    case WAITING = 'waiting';
    case ERROR = 'error';
    case MAINTENANCE = 'maintenance';
    case DISABLED = 'disabled';

    public function getDescription(): string
    {
        return match($this) {
            self::IDLE => 'Bot ist bereit und wartet auf Aufgaben',
            self::PROCESSING => 'Bot verarbeitet gerade ein Ticket',
            self::WAITING => 'Bot wartet auf externe Ressourcen',
            self::ERROR => 'Bot hat einen Fehler und benötigt Aufmerksamkeit',
            self::MAINTENANCE => 'Bot ist im Wartungsmodus',
            self::DISABLED => 'Bot ist deaktiviert',
        };
    }

    public function isActive(): bool
    {
        return match($this) {
            self::IDLE, self::PROCESSING, self::WAITING => true,
            self::ERROR, self::MAINTENANCE, self::DISABLED => false,
        };
    }
} 