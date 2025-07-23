<?php

namespace App\Http\Controllers;

use App\Services\TicketProcessingService;
use App\Services\JiraService;
use App\Services\BotStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;

class TicketController extends Controller
{
    private TicketProcessingService $ticketProcessingService;
    private JiraService $jiraService;
    private BotStatusService $botStatusService;

    public function __construct(
        TicketProcessingService $ticketProcessingService,
        JiraService $jiraService,
        BotStatusService $botStatusService
    ) {
        $this->ticketProcessingService = $ticketProcessingService;
        $this->jiraService = $jiraService;
        $this->botStatusService = $botStatusService;
    }

    /**
     * Zeigt Dashboard mit Ticket-Übersicht
     */
    public function dashboard()
    {
        try {
            $tickets = $this->ticketProcessingService->getRecentTickets();
            $status = $this->botStatusService->getStatus();
            
            return view('dashboard', [
                'tickets' => $tickets,
                'bot_status' => $status,
                'page_title' => 'Octomind Bot Dashboard'
            ]);
        } catch (Exception $e) {
            return view('dashboard', [
                'error' => 'Fehler beim Laden des Dashboards: ' . $e->getMessage(),
                'tickets' => [],
                'bot_status' => ['status' => 'error'],
                'page_title' => 'Octomind Bot Dashboard'
            ]);
        }
    }

    /**
     * API: Holt aktuelle Tickets
     */
    public function getTickets(Request $request): JsonResponse
    {
        try {
            $limit = $request->get('limit', 20);
            $status = $request->get('status');
            
            $tickets = $this->ticketProcessingService->getTickets($limit, $status);
            
            return response()->json([
                'success' => true,
                'data' => $tickets,
                'total' => count($tickets)
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * API: Startet Ticket-Verarbeitung
     */
    public function processTicket(Request $request): JsonResponse
    {
        $request->validate([
            'ticket_key' => 'required|string'
        ]);

        try {
            $ticketKey = $request->get('ticket_key');
            $result = $this->ticketProcessingService->processTicket($ticketKey);
            
            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'] ?? 'Ticket-Verarbeitung gestartet',
                'data' => $result
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * API: Holt Ticket-Details
     */
    public function getTicketDetails(string $ticketKey): JsonResponse
    {
        try {
            $ticket = $this->ticketProcessingService->getTicketDetails($ticketKey);
            
            if (!$ticket) {
                return response()->json([
                    'success' => false,
                    'error' => 'Ticket nicht gefunden'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'data' => $ticket
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * API: Synchronisiert Tickets von Jira
     */
    public function syncTickets(): JsonResponse
    {
        try {
            $result = $this->jiraService->fetchTickets();
            
            return response()->json([
                'success' => true,
                'message' => 'Tickets erfolgreich synchronisiert',
                'synced_count' => count($result)
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * API: Testet Jira-Verbindung
     */
    public function testJiraConnection(): JsonResponse
    {
        try {
            $result = $this->jiraService->testConnection();
            
            return response()->json($result);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 