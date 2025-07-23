<?php

use Illuminate\Support\Facades\Route;
use App\Bots\OctomindBot;
use App\Services\ConfigService;
use App\Services\BotStatusService;
use App\Services\LogService;

// Bot Dashboard
Route::get('/', function () {
    $config = ConfigService::getInstance();
    $statusService = new BotStatusService();
    
    return view('dashboard', [
        'bot_enabled' => $config->isBotEnabled(),
        'simulation_mode' => $config->isSimulationMode(),
        'health_check' => $statusService->performHealthCheck(),
        'metrics' => $statusService->getCurrentMetrics(),
    ]);
});

// Bot Status API
Route::get('/api/status', function () {
    $statusService = new BotStatusService();
    return response()->json($statusService->performHealthCheck());
});

// Bot Metrics API
Route::get('/api/metrics', function () {
    $statusService = new BotStatusService();
    return response()->json($statusService->getCurrentMetrics());
});

// Recent Logs API
Route::get('/api/logs', function () {
    $logService = new LogService();
    return response()->json($logService->getRecentLogs(50));
});

// Configuration Check
Route::get('/api/config-check', function () {
    $config = ConfigService::getInstance();
    $errors = $config->validateConfiguration();
    
    return response()->json([
        'valid' => empty($errors),
        'errors' => $errors,
        'config' => [
            'bot_enabled' => $config->isBotEnabled(),
            'simulation_mode' => $config->isSimulationMode(),
            'jira_project' => $config->get('jira.project_key'),
            'ai_provider' => $config->get('ai.primary_provider'),
        ]
    ]);
});
