<?php

namespace App\Contracts;

interface AIProviderInterface
{
    /**
     * Generiert eine Lösung basierend auf dem Prompt
     */
    public function generateSolution(string $prompt): array;

    /**
     * Testet die Verbindung zum AI-Provider
     */
    public function testConnection(): array;

    /**
     * Gibt den Namen des Providers zurück
     */
    public function getProviderName(): string;

    /**
     * Gibt die unterstützten Modelle zurück
     */
    public function getSupportedModels(): array;

    /**
     * Gibt die maximale Token-Anzahl zurück
     */
    public function getMaxTokens(): int;

    /**
     * Validiert Provider-Konfiguration
     */
    public function validateConfiguration(): array;

    /**
     * Berechnet geschätzte Kosten für einen Request
     */
    public function estimateCost(string $prompt): float;
} 