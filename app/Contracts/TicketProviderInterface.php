<?php

namespace App\Contracts;

use App\DTOs\TicketDTO;

interface TicketProviderInterface
{
    /**
     * Ruft Tickets vom Provider ab
     */
    public function fetchTickets(): array;

    /**
     * Testet die Verbindung zum Provider
     */
    public function testConnection(): array;

    /**
     * Fügt einen Kommentar zu einem Ticket hinzu
     */
    public function addComment(string $ticketKey, string $comment): bool;

    /**
     * Aktualisiert den Status eines Tickets
     */
    public function updateTicketStatus(string $ticketKey, string $status): bool;

    /**
     * Gibt den Namen des Providers zurück
     */
    public function getProviderName(): string;

    /**
     * Gibt die unterstützten Ticket-Status zurück
     */
    public function getSupportedStatuses(): array;

    /**
     * Validiert Ticket-Konfiguration
     */
    public function validateConfiguration(): array;
} 