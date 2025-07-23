<?php

namespace App\Contracts;

use App\DTOs\TicketDTO;

interface VersionControlProviderInterface
{
    /**
     * Erstellt einen Pull Request für das Ticket
     */
    public function createPullRequest(TicketDTO $ticket, array $executionResult): array;

    /**
     * Testet die Verbindung zum Provider
     */
    public function testConnection(): array;

    /**
     * Holt Repository-Informationen
     */
    public function getRepositoryInfo(string $owner, string $repo): array;

    /**
     * Fügt einen Kommentar zu einem Pull Request hinzu
     */
    public function addPRComment(string $owner, string $repo, int $prNumber, string $comment): bool;

    /**
     * Merged einen Pull Request
     */
    public function mergePullRequest(string $owner, string $repo, int $prNumber): array;

    /**
     * Löscht einen Branch
     */
    public function deleteBranch(string $owner, string $repo, string $branchName): bool;

    /**
     * Gibt den Namen des Providers zurück
     */
    public function getProviderName(): string;

    /**
     * Gibt die unterstützten Repository-Typen zurück
     */
    public function getSupportedRepositoryTypes(): array;

    /**
     * Validiert Provider-Konfiguration
     */
    public function validateConfiguration(): array;
} 