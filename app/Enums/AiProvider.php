<?php

namespace App\Enums;

enum AiProvider: string
{
    case OPENAI = 'openai';
    case CLAUDE = 'claude';
    case CLOUD_AI = 'cloud_ai';

    public function getApiUrl(): string
    {
        return match($this) {
            self::OPENAI => 'https://api.openai.com/v1',
            self::CLAUDE => 'https://api.anthropic.com/v1',
            self::CLOUD_AI => env('CLOUD_AI_ENDPOINT', 'https://api.example.com/v1'),
        };
    }

    public function getDefaultModel(): string
    {
        return match($this) {
            self::OPENAI => 'gpt-4',
            self::CLAUDE => 'claude-3-sonnet-20240229',
            self::CLOUD_AI => env('CLOUD_AI_MODEL', 'default'),
        };
    }

    public function getMaxTokens(): int
    {
        return match($this) {
            self::OPENAI => 4096,
            self::CLAUDE => 4096,
            self::CLOUD_AI => 4096,
        };
    }
} 