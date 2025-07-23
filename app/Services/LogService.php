<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LogService
{
    private ConfigService $config;
    private string $logLevel;
    private bool $verboseLogging;
    private bool $dbLogging;

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logLevel = $this->config->getLogLevel();
        $this->verboseLogging = $this->config->isVerboseLogging();
        $this->dbLogging = $this->config->get('bot.monitoring_enabled', true);
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function botActivity(string $activity, array $data = []): void
    {
        $this->log('info', "Bot Activity: {$activity}", [
            'activity' => $activity,
            'data' => $data,
            'timestamp' => Carbon::now()->toISOString(),
            'memory_usage' => memory_get_usage(true),
        ]);
    }

    public function ticketProcessing(string $ticketKey, string $status, array $data = []): void
    {
        $this->log('info', "Ticket Processing: {$ticketKey} - {$status}", [
            'ticket_key' => $ticketKey,
            'status' => $status,
            'data' => $data,
            'timestamp' => Carbon::now()->toISOString(),
        ]);
    }

    public function aiInteraction(string $provider, string $action, array $data = []): void
    {
        $this->log('info', "AI Interaction: {$provider} - {$action}", [
            'provider' => $provider,
            'action' => $action,
            'data' => $this->sanitizeAiData($data),
            'timestamp' => Carbon::now()->toISOString(),
        ]);
    }

    public function codeExecution(string $repository, string $action, array $data = []): void
    {
        $this->log('info', "Code Execution: {$repository} - {$action}", [
            'repository' => $repository,
            'action' => $action,
            'data' => $data,
            'timestamp' => Carbon::now()->toISOString(),
        ]);
    }

    public function retryAttempt(string $operation, int $attempt, int $maxAttempts, array $context = []): void
    {
        $this->log('warning', "Retry Attempt: {$operation} ({$attempt}/{$maxAttempts})", [
            'operation' => $operation,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'context' => $context,
            'timestamp' => Carbon::now()->toISOString(),
        ]);
    }

    public function performance(string $operation, float $duration, array $metrics = []): void
    {
        $this->log('info', "Performance: {$operation} completed in {$duration}s", [
            'operation' => $operation,
            'duration' => $duration,
            'metrics' => $metrics,
            'timestamp' => Carbon::now()->toISOString(),
        ]);
    }

    public function security(string $event, array $context = []): void
    {
        $this->log('warning', "Security Event: {$event}", [
            'event' => $event,
            'context' => $context,
            'timestamp' => Carbon::now()->toISOString(),
            'ip' => request()->ip() ?? 'cli',
        ]);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        // Check if we should log this level
        if (!$this->shouldLog($level)) {
            return;
        }

        // Enhance context with bot information
        $enhancedContext = array_merge($context, [
            'bot_id' => 'octomind',
            'process_id' => getmypid(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ]);

        // Log to Laravel's logging system (files)
        Log::channel('single')->{$level}($message, $enhancedContext);

        // Log to database if enabled
        if ($this->dbLogging) {
            $this->logToDatabase($level, $message, $enhancedContext);
        }

        // Verbose logging to console if enabled
        if ($this->verboseLogging && app()->runningInConsole()) {
            $this->logToConsole($level, $message, $enhancedContext);
        }
    }

    private function shouldLog(string $level): bool
    {
        $levels = [
            'emergency' => 0,
            'alert' => 1,
            'critical' => 2,
            'error' => 3,
            'warning' => 4,
            'notice' => 5,
            'info' => 6,
            'debug' => 7,
        ];

        $currentLevelValue = $levels[$this->logLevel] ?? 6;
        $messageLevelValue = $levels[$level] ?? 6;

        return $messageLevelValue <= $currentLevelValue;
    }

    private function logToDatabase(string $level, string $message, array $context): void
    {
        try {
            DB::table('bot_logs')->insert([
                'level' => $level,
                'message' => $message,
                'context' => json_encode($context),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } catch (\Exception $e) {
            // Fallback to file logging if database is not available
            Log::error('Failed to log to database: ' . $e->getMessage());
        }
    }

    private function logToConsole(string $level, string $message, array $context): void
    {
        $timestamp = Carbon::now()->format('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);
        
        $output = "[{$timestamp}] {$levelUpper}: {$message}";
        
        if (!empty($context) && $level === 'debug') {
            $output .= "\nContext: " . json_encode($context, JSON_PRETTY_PRINT);
        }
        
        echo $output . "\n";
    }

    private function sanitizeAiData(array $data): array
    {
        // Remove sensitive information from AI interaction logs
        $sanitized = $data;
        
        // Remove API keys and tokens
        if (isset($sanitized['api_key'])) {
            $sanitized['api_key'] = '***REDACTED***';
        }
        
        if (isset($sanitized['token'])) {
            $sanitized['token'] = '***REDACTED***';
        }
        
        // Truncate very long content
        if (isset($sanitized['prompt']) && strlen($sanitized['prompt']) > 1000) {
            $sanitized['prompt'] = substr($sanitized['prompt'], 0, 1000) . '... [TRUNCATED]';
        }
        
        if (isset($sanitized['response']) && strlen($sanitized['response']) > 1000) {
            $sanitized['response'] = substr($sanitized['response'], 0, 1000) . '... [TRUNCATED]';
        }
        
        return $sanitized;
    }

    public function getRecentLogs(int $limit = 100, string $level = null): array
    {
        if (!$this->dbLogging) {
            return [];
        }

        $query = DB::table('bot_logs')
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        if ($level) {
            $query->where('level', $level);
        }

        return $query->get()->toArray();
    }

    public function clearOldLogs(int $daysToKeep = 30): int
    {
        if (!$this->dbLogging) {
            return 0;
        }

        $cutoffDate = Carbon::now()->subDays($daysToKeep);
        
        return DB::table('bot_logs')
            ->where('created_at', '<', $cutoffDate)
            ->delete();
    }
} 