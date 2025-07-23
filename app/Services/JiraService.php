<?php

namespace App\Services;

use App\DTOs\TicketDTO;
use App\Enums\TicketStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class JiraService
{
    private ConfigService $config;
    private LogService $logger;
    private string $baseUrl;
    private string $username;
    private string $apiToken;
    private string $projectKey;

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
        
        $authConfig = $this->config->getAuthConfig();
        $jiraConfig = $this->config->getJiraConfig();
        
        $this->baseUrl = rtrim($authConfig['jira_base_url'], '/');
        $this->username = $authConfig['jira_username'];
        $this->apiToken = $authConfig['jira_api_token'];
        $this->projectKey = $jiraConfig['project_key'];
    }

    /**
     * Holt neue Tickets von Jira basierend auf der Konfiguration
     */
    public function fetchTickets(): array
    {
        $this->logger->info('Starte Jira-Ticket-Abruf', [
            'project_key' => $this->projectKey,
            'base_url' => $this->baseUrl
        ]);

        try {
            $jql = $this->buildJQL();
            $this->logger->debug('Verwende JQL-Query', ['jql' => $jql]);
            
            $response = $this->makeJiraRequest('GET', '/rest/api/3/search', [
                'jql' => $jql,
                'maxResults' => 50,
                'fields' => $this->getRequiredFields(),
                'expand' => 'changelog'
            ]);

            if (!$response['success']) {
                throw new Exception('Jira API request failed: ' . $response['error']);
            }

            $issues = $response['data']['issues'] ?? [];
            $this->logger->info('Jira-Tickets abgerufen', [
                'total_found' => $response['data']['total'] ?? 0,
                'returned' => count($issues)
            ]);

            $tickets = [];
            foreach ($issues as $issue) {
                try {
                    $ticket = TicketDTO::fromJiraResponse($issue);
                    $tickets[] = $ticket;
                    
                    // Speichere oder aktualisiere Ticket in der Datenbank
                    $this->saveTicketToDatabase($ticket);
                    
                } catch (Exception $e) {
                    $this->logger->warning('Fehler beim Verarbeiten von Jira-Issue', [
                        'issue_key' => $issue['key'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return $tickets;

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Abrufen der Jira-Tickets', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Erstellt die JQL-Query basierend auf der Konfiguration
     */
    private function buildJQL(): string
    {
        $jiraConfig = $this->config->getJiraConfig();
        
        $conditions = [];
        
        // Projekt-Filter
        $conditions[] = "project = \"{$this->projectKey}\"";
        
        // Status-Filter
        $allowedStatuses = $jiraConfig['allowed_statuses'];
        if (!empty($allowedStatuses)) {
            $statusList = '"' . implode('", "', $allowedStatuses) . '"';
            $conditions[] = "status IN ({$statusList})";
        }
        
        // Label-Filter
        $requiredLabel = $jiraConfig['required_label'];
        if ($requiredLabel) {
            $conditions[] = "labels = \"{$requiredLabel}\"";
        }
        
        // Unassigned-Filter
        if ($jiraConfig['require_unassigned']) {
            $conditions[] = "assignee IS EMPTY";
        }
        
        // Nur Tickets, die noch nicht erfolgreich verarbeitet wurden
        $processedTickets = $this->getProcessedTicketKeys();
        if (!empty($processedTickets)) {
            $ticketList = '"' . implode('", "', $processedTickets) . '"';
            $conditions[] = "key NOT IN ({$ticketList})";
        }
        
        // Sortierung nach Priorität und Erstellungsdatum
        $jql = implode(' AND ', $conditions) . ' ORDER BY priority DESC, created ASC';
        
        return $jql;
    }

    /**
     * Definiert die benötigten Felder für die Jira-Abfrage
     */
    private function getRequiredFields(): array
    {
        return [
            'key',
            'summary',
            'description',
            'status',
            'assignee',
            'labels',
            'components',
            'priority',
            'issuetype',
            'created',
            'updated',
            'project',
            'attachment',
            'comment',
            // Custom fields - diese können je nach Jira-Konfiguration variieren
            'customfield_*'
        ];
    }

    /**
     * Führt eine HTTP-Anfrage an die Jira-API aus
     */
    private function makeJiraRequest(string $method, string $endpoint, array $params = []): array
    {
        $startTime = microtime(true);
        
        try {
            $url = $this->baseUrl . $endpoint;
            
            $response = Http::withBasicAuth($this->username, $this->apiToken)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ])
                ->timeout(30);

            if ($method === 'GET') {
                $response = $response->get($url, $params);
            } elseif ($method === 'POST') {
                $response = $response->post($url, $params);
            } elseif ($method === 'PUT') {
                $response = $response->put($url, $params);
            } else {
                throw new Exception("Unsupported HTTP method: {$method}");
            }

            $responseTime = (microtime(true) - $startTime) * 1000;
            
            $this->logger->performance('jira_api_request', $responseTime / 1000, [
                'method' => $method,
                'endpoint' => $endpoint,
                'status_code' => $response->status(),
                'response_time_ms' => $responseTime
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'status_code' => $response->status()
                ];
            } else {
                return [
                    'success' => false,
                    'error' => "HTTP {$response->status()}: " . $response->body(),
                    'status_code' => $response->status()
                ];
            }

        } catch (Exception $e) {
            $responseTime = (microtime(true) - $startTime) * 1000;
            
            $this->logger->error('Jira API request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'response_time_ms' => $responseTime
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => 0
            ];
        }
    }

    /**
     * Speichert ein Ticket in der lokalen Datenbank
     */
    private function saveTicketToDatabase(TicketDTO $ticket): void
    {
        try {
            $ticketData = [
                'key' => $ticket->key,
                'project_key' => $ticket->projectKey,
                'summary' => $ticket->summary,
                'description' => $ticket->description,
                'status' => $ticket->status->value,
                'assignee' => $ticket->assignee,
                'linked_repository' => $ticket->linkedRepository,
                'labels' => json_encode($ticket->labels),
                'components' => json_encode($ticket->components),
                'priority' => $ticket->priority,
                'issue_type' => $ticket->issueType,
                'custom_fields' => json_encode($ticket->customFields),
                'attachments' => json_encode($ticket->attachments),
                'comments' => json_encode($ticket->comments),
                'jira_created_at' => $ticket->createdAt,
                'jira_updated_at' => $ticket->updatedAt,
                'updated_at' => Carbon::now(),
            ];

            // Upsert: Insert oder Update falls bereits vorhanden
            DB::table('tickets')
                ->updateOrInsert(
                    ['key' => $ticket->key],
                    array_merge($ticketData, ['created_at' => Carbon::now()])
                );

            $this->logger->debug('Ticket in Datenbank gespeichert', ['ticket_key' => $ticket->key]);

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Speichern des Tickets in die Datenbank', [
                'ticket_key' => $ticket->key,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Holt die Keys von bereits erfolgreich verarbeiteten Tickets
     */
    private function getProcessedTicketKeys(): array
    {
        try {
            return DB::table('tickets')
                ->where('status', TicketStatus::COMPLETED->value)
                ->where('updated_at', '>', Carbon::now()->subDays(7)) // Nur letzte 7 Tage
                ->pluck('key')
                ->toArray();
        } catch (Exception $e) {
            $this->logger->warning('Fehler beim Abrufen verarbeiteter Tickets', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Fügt einen Kommentar zu einem Jira-Ticket hinzu
     */
    public function addComment(string $ticketKey, string $comment): bool
    {
        try {
            $this->logger->info('Füge Kommentar zu Jira-Ticket hinzu', [
                'ticket_key' => $ticketKey,
                'comment_length' => strlen($comment)
            ]);

            $response = $this->makeJiraRequest('POST', "/rest/api/3/issue/{$ticketKey}/comment", [
                'body' => [
                    'type' => 'doc',
                    'version' => 1,
                    'content' => [
                        [
                            'type' => 'paragraph',
                            'content' => [
                                [
                                    'text' => $comment,
                                    'type' => 'text'
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

            if ($response['success']) {
                $this->logger->info('Kommentar erfolgreich hinzugefügt', ['ticket_key' => $ticketKey]);
                return true;
            } else {
                $this->logger->error('Fehler beim Hinzufügen des Kommentars', [
                    'ticket_key' => $ticketKey,
                    'error' => $response['error']
                ]);
                return false;
            }

        } catch (Exception $e) {
            $this->logger->error('Exception beim Hinzufügen des Kommentars', [
                'ticket_key' => $ticketKey,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Aktualisiert den Status eines Tickets in Jira
     */
    public function updateTicketStatus(string $ticketKey, string $status): bool
    {
        try {
            // Hier würde die Jira-Transition-Logik implementiert werden
            // Dies ist komplex, da Jira Workflows verwendet
            $this->logger->info('Ticket-Status-Update angefordert', [
                'ticket_key' => $ticketKey,
                'new_status' => $status
            ]);

            // Für jetzt nur lokale Aktualisierung
            DB::table('tickets')
                ->where('key', $ticketKey)
                ->update([
                    'status' => $status,
                    'updated_at' => Carbon::now()
                ]);

            return true;

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Aktualisieren des Ticket-Status', [
                'ticket_key' => $ticketKey,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Testet die Verbindung zur Jira-API
     */
    public function testConnection(): array
    {
        try {
            $this->logger->info('Teste Jira-API-Verbindung');
            
            $response = $this->makeJiraRequest('GET', '/rest/api/3/myself');
            
            if ($response['success']) {
                $user = $response['data'];
                $this->logger->info('Jira-Verbindung erfolgreich', [
                    'user' => $user['displayName'] ?? 'Unknown',
                    'account_id' => $user['accountId'] ?? 'Unknown'
                ]);
                
                return [
                    'success' => true,
                    'message' => 'Verbindung zu Jira erfolgreich',
                    'user' => $user['displayName'] ?? 'Unknown'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Jira-Verbindung fehlgeschlagen: ' . $response['error']
                ];
            }

        } catch (Exception $e) {
            $this->logger->error('Jira-Verbindungstest fehlgeschlagen', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Jira-Verbindung fehlgeschlagen: ' . $e->getMessage()
            ];
        }
    }
} 