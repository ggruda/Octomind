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
        
        $this->baseUrl = $this->config->get('auth.jira_base_url');
        $this->username = $this->config->get('auth.jira_username');
        $this->apiToken = $this->config->get('auth.jira_api_token');
        $this->projectKey = $this->config->get('jira.project_key');
    }

    /**
     * Ruft Tickets von Jira ab basierend auf Konfiguration
     */
    public function fetchTickets(): array
    {
        $this->logger->info('Starte Jira-Ticket-Abruf', [
            'project' => $this->projectKey,
            'base_url' => $this->baseUrl
        ]);

        try {
            $jql = $this->buildJQL();
            $this->logger->debug('Erstelle JQL-Query', ['jql' => $jql]);

            $response = $this->makeJiraRequest('search', [
                'jql' => $jql,
                'fields' => $this->getRequiredFields(),
                'maxResults' => 50,
                'startAt' => 0
            ]);

            if (!$response->successful()) {
                throw new Exception("Jira API Fehler: " . $response->body());
            }

            $data = $response->json();
            $tickets = [];

            foreach ($data['issues'] ?? [] as $issue) {
                try {
                    $ticket = $this->parseJiraIssue($issue);
                    if ($ticket) {
                        $tickets[] = $ticket;
                        $this->storeTicketInDatabase($ticket);
                    }
                } catch (Exception $e) {
                    $this->logger->warning('Fehler beim Parsen von Ticket', [
                        'issue_key' => $issue['key'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->logger->info('Jira-Tickets erfolgreich abgerufen', [
                'total_found' => $data['total'] ?? 0,
                'returned' => count($tickets),
                'processed' => count($tickets)
            ]);

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
     * Testet die Verbindung zu Jira
     */
    public function testConnection(): array
    {
        try {
            $startTime = microtime(true);
            
            $response = $this->makeJiraRequest('myself');
            $responseTime = round((microtime(true) - $startTime) * 1000);

            if ($response->successful()) {
                $userData = $response->json();
                
                $this->logger->info('Jira-Verbindungstest erfolgreich', [
                    'user' => $userData['displayName'] ?? 'Unknown',
                    'response_time_ms' => $responseTime
                ]);

                return [
                    'success' => true,
                    'message' => 'Verbindung zu Jira erfolgreich',
                    'user' => $userData['displayName'] ?? 'Unknown',
                    'response_time_ms' => $responseTime
                ];
            } else {
                throw new Exception("HTTP {$response->status()}: " . $response->body());
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

    /**
     * Fügt einen Kommentar zu einem Jira-Ticket hinzu
     */
    public function addComment(string $ticketKey, string $comment): bool
    {
        try {
            $this->logger->debug('Füge Kommentar zu Ticket hinzu', [
                'ticket' => $ticketKey,
                'comment_length' => strlen($comment)
            ]);

            $response = $this->makeJiraRequest("issue/{$ticketKey}/comment", [
                'body' => $this->formatJiraComment($comment)
            ], 'POST');

            if ($response->successful()) {
                $this->logger->info('Kommentar erfolgreich hinzugefügt', [
                    'ticket' => $ticketKey
                ]);
                return true;
            } else {
                throw new Exception("HTTP {$response->status()}: " . $response->body());
            }

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Hinzufügen des Kommentars', [
                'ticket' => $ticketKey,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Aktualisiert den Status eines Jira-Tickets
     */
    public function updateTicketStatus(string $ticketKey, string $status): bool
    {
        try {
            // Hole verfügbare Transitions
            $transitionsResponse = $this->makeJiraRequest("issue/{$ticketKey}/transitions");
            
            if (!$transitionsResponse->successful()) {
                throw new Exception("Konnte Transitions nicht abrufen: " . $transitionsResponse->body());
            }

            $transitions = $transitionsResponse->json()['transitions'] ?? [];
            $targetTransition = null;

            // Finde passende Transition
            foreach ($transitions as $transition) {
                if (stripos($transition['name'], $status) !== false || 
                    stripos($transition['to']['name'], $status) !== false) {
                    $targetTransition = $transition;
                    break;
                }
            }

            if (!$targetTransition) {
                $this->logger->warning('Keine passende Transition gefunden', [
                    'ticket' => $ticketKey,
                    'target_status' => $status,
                    'available_transitions' => array_column($transitions, 'name')
                ]);
                return false;
            }

            // Führe Transition aus
            $response = $this->makeJiraRequest("issue/{$ticketKey}/transitions", [
                'transition' => ['id' => $targetTransition['id']]
            ], 'POST');

            if ($response->successful()) {
                $this->logger->info('Ticket-Status erfolgreich aktualisiert', [
                    'ticket' => $ticketKey,
                    'new_status' => $targetTransition['to']['name']
                ]);
                return true;
            } else {
                throw new Exception("HTTP {$response->status()}: " . $response->body());
            }

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Aktualisieren des Ticket-Status', [
                'ticket' => $ticketKey,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Erstellt JQL-Query basierend auf Konfiguration
     */
    private function buildJQL(): string
    {
        $conditions = [];
        
        // Projekt-Filter
        if ($this->projectKey) {
            $conditions[] = "project = \"{$this->projectKey}\"";
        }

        // Status-Filter
        $allowedStatuses = $this->config->get('jira.allowed_statuses', ['Open', 'In Progress', 'To Do']);
        if (!empty($allowedStatuses)) {
            $statusList = implode('", "', $allowedStatuses);
            $conditions[] = "status IN (\"{$statusList}\")";
        }

        // Label-Filter
        $requiredLabel = $this->config->get('jira.required_label');
        if ($requiredLabel) {
            $conditions[] = "labels = \"{$requiredLabel}\"";
        }

        // Unassigned-Filter
        if ($this->config->get('jira.require_unassigned', true)) {
            $conditions[] = "assignee is EMPTY";
        }

        // Zeitfilter - nur neuere Tickets
        $conditions[] = "created >= -30d";

        // Sortierung
        $jql = implode(' AND ', $conditions) . ' ORDER BY created DESC';

        return $jql;
    }

    /**
     * Definiert welche Felder von Jira abgerufen werden sollen
     */
    private function getRequiredFields(): array
    {
        return [
            'key',
            'summary',
            'description', 
            'status',
            'priority',
            'assignee',
            'reporter',
            'created',
            'updated',
            'labels',
            'components',
            'fixVersions',
            'customfield_10000', // Beispiel für Repository-Link Custom Field
        ];
    }

    /**
     * Parst ein Jira-Issue zu einem TicketDTO
     */
    private function parseJiraIssue(array $issue): ?TicketDTO
    {
        try {
            $fields = $issue['fields'] ?? [];
            
            // Repository-Link aus verschiedenen Quellen extrahieren
            $repositoryUrl = $this->extractRepositoryUrl($fields);
            
            if (!$repositoryUrl) {
                $this->logger->debug('Ticket hat keinen Repository-Link', [
                    'ticket' => $issue['key']
                ]);
                return null; // Skip tickets ohne Repository-Link
            }

            $ticketDto = new TicketDTO(
                key: $issue['key'],
                summary: $fields['summary'] ?? '',
                description: $fields['description'] ?? '',
                status: $fields['status']['name'] ?? 'Unknown',
                priority: $fields['priority']['name'] ?? 'Medium',
                assignee: $fields['assignee']['displayName'] ?? null,
                reporter: $fields['reporter']['displayName'] ?? 'Unknown',
                created: Carbon::parse($fields['created']),
                updated: Carbon::parse($fields['updated']),
                labels: $fields['labels'] ?? [],
                repositoryUrl: $repositoryUrl
            );

            $this->logger->debug('Ticket erfolgreich geparst', [
                'ticket' => $ticketDto->key,
                'repository' => $ticketDto->repositoryUrl
            ]);

            return $ticketDto;

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Parsen des Jira-Issues', [
                'issue_key' => $issue['key'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Extrahiert Repository-URL aus verschiedenen Jira-Feldern
     */
    private function extractRepositoryUrl(array $fields): ?string
    {
        // 1. Custom Field (konfigurierbar)
        $customFieldKey = $this->config->get('jira.repository_custom_field', 'customfield_10000');
        if (!empty($fields[$customFieldKey])) {
            return $fields[$customFieldKey];
        }

        // 2. Aus Beschreibung extrahieren
        $description = $fields['description'] ?? '';
        if (preg_match('/https:\/\/github\.com\/[^\s]+/', $description, $matches)) {
            return $matches[0];
        }

        // 3. Aus Kommentaren (falls verfügbar)
        // Hier könnte zusätzliche Logik implementiert werden

        return null;
    }

    /**
     * Macht HTTP-Request an Jira API
     */
    private function makeJiraRequest(string $endpoint, array $data = [], string $method = 'GET')
    {
        $url = rtrim($this->baseUrl, '/') . '/rest/api/2/' . ltrim($endpoint, '/');
        
        $request = Http::withBasicAuth($this->username, $this->apiToken)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout(30);

        return match(strtoupper($method)) {
            'GET' => $request->get($url, $data),
            'POST' => $request->post($url, $data),
            'PUT' => $request->put($url, $data),
            'DELETE' => $request->delete($url, $data),
            default => throw new Exception("Unsupported HTTP method: {$method}")
        };
    }

    /**
     * Formatiert Kommentar für Jira (ADF Format)
     */
    private function formatJiraComment(string $comment): array
    {
        return [
            'type' => 'doc',
            'version' => 1,
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $comment
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Speichert Ticket in der Datenbank
     */
    private function storeTicketInDatabase(TicketDTO $ticket): void
    {
        try {
            DB::table('tickets')->updateOrInsert(
                ['jira_key' => $ticket->key],
                [
                    'jira_key' => $ticket->key,
                    'summary' => $ticket->summary,
                    'description' => $ticket->description,
                    'status' => TicketStatus::PENDING->value,
                    'jira_status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'assignee' => $ticket->assignee,
                    'reporter' => $ticket->reporter,
                    'repository_url' => $ticket->repositoryUrl,
                    'labels' => json_encode($ticket->labels),
                    'jira_created_at' => $ticket->created,
                    'jira_updated_at' => $ticket->updated,
                    'fetched_at' => Carbon::now(),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]
            );

            $this->logger->debug('Ticket in Datenbank gespeichert', [
                'ticket' => $ticket->key
            ]);

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Speichern des Tickets in der Datenbank', [
                'ticket' => $ticket->key,
                'error' => $e->getMessage()
            ]);
        }
    }
} 