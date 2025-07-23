<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\BotController;
use App\Services\ConfigService;
use App\Services\BotStatusService;
use App\Services\LogService;

// Bot Dashboard
Route::get('/', function () {
    return view('welcome');
});

// Bot-Management Routen
Route::prefix('bot')->name('bot.')->group(function () {
    Route::get('/', [BotController::class, 'dashboard'])->name('dashboard');
    Route::get('/create', [BotController::class, 'create'])->name('create');
    Route::post('/store', [BotController::class, 'store'])->name('store');
    Route::post('/start', [BotController::class, 'start'])->name('start');
    Route::post('/stop', [BotController::class, 'stop'])->name('stop');
    Route::post('/load-tickets', [BotController::class, 'loadTickets'])->name('load-tickets');
    Route::get('/status/{projectKey}', [BotController::class, 'status'])->name('status');
});

// Standard-Route umleiten zum Bot-Dashboard
Route::redirect('/', '/bot');

// API Routes für Ticket-Management
Route::prefix('api')->group(function () {
    // Ticket-Operationen
    Route::get('/tickets', [TicketController::class, 'getTickets']);
    Route::get('/tickets/{ticketKey}', [TicketController::class, 'getTicketDetails']);
    Route::post('/tickets/process', [TicketController::class, 'processTicket']);
    Route::post('/tickets/sync', [TicketController::class, 'syncTickets']);
    
    // Service-Tests
    Route::get('/test/jira', [TicketController::class, 'testJiraConnection']);
    
    // Bot Status API
    Route::get('/status', function () {
        $statusService = new BotStatusService();
        return response()->json($statusService->performHealthCheck());
    });

    // Bot Metrics API
    Route::get('/metrics', function () {
        $stats = \App\Models\Ticket::getProcessingStats();
        return response()->json([
            'tickets_total' => $stats['total'],
            'tickets_pending' => $stats['pending'],
            'tickets_in_progress' => $stats['in_progress'],
            'tickets_completed' => $stats['completed'],
            'tickets_failed' => $stats['failed'],
            'recent_completed' => $stats['recent_completed'],
            'avg_processing_time_seconds' => round($stats['average_duration'] ?? 0),
            'success_rate' => $stats['total'] > 0 ? round($stats['completed'] / $stats['total'], 2) : 0
        ]);
    });

    // Recent Logs API
    Route::get('/logs', function () {
        $logService = new LogService();
        return response()->json($logService->getRecentLogs(50));
    });

    // Configuration Check
    Route::get('/config-check', function () {
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
});
