<?php

namespace App\Services;

use App\DTOs\TicketDTO;

class PromptBuilderService
{
    private ConfigService $config;
    private LogService $logger;

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
    }

    public function buildPrompt(TicketDTO $ticket, array $analysis): string
    {
        $this->logger->debug('Erstelle Prompt für Ticket', ['ticket_key' => $ticket->key]);
        
        // Placeholder - hier würde der echte Prompt-Builder implementiert werden
        return "Löse das folgende Ticket: {$ticket->summary}\n\nBeschreibung: {$ticket->description}";
    }
} 