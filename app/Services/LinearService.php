<?php

namespace App\Services;

use App\Contracts\TicketProviderInterface;
use App\DTOs\TicketDTO;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Exception;

class LinearService implements TicketProviderInterface
{
    private ConfigService $config;
    private LogService $logger;
    private string $apiKey;
    private string $baseUrl = 'https://api.linear.app';

    public function __construct()
    {
        $this->config = ConfigService::getInstance();
        $this->logger = new LogService();
        
        $this->apiKey = $this->config->get('auth.linear_api_key');
    }

    public function fetchTickets(): array
    {
        $this->logger->info('Starte Linear-Ticket-Abruf');

        try {
            $query = $this->buildGraphQLQuery();
            
            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json'
            ])->post("{$this->baseUrl}/graphql", [
                'query' => $query
            ]);

            if (!$response->successful()) {
                throw new Exception("Linear API Fehler: " . $response->body());
            }

            $data = $response->json();
            $tickets = [];

            foreach ($data['data']['issues']['nodes'] ?? [] as $issue) {
                try {
                    $ticket = $this->parseLinearIssue($issue);
                    if ($ticket) {
                        $tickets[] = $ticket;
                        $this->storeTicketInDatabase($ticket);
                    }
                } catch (Exception $e) {
                    $this->logger->warning('Fehler beim Parsen von Linear-Issue', [
                        'issue_id' => $issue['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->logger->info('Linear-Tickets erfolgreich abgerufen', [
                'count' => count($tickets)
            ]);

            return $tickets;

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Abrufen der Linear-Tickets', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function testConnection(): array
    {
        try {
            $query = '{ viewer { id name email } }';
            
            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json'
            ])->post("{$this->baseUrl}/graphql", [
                'query' => $query
            ]);

            if ($response->successful()) {
                $userData = $response->json()['data']['viewer'];
                
                return [
                    'success' => true,
                    'message' => 'Linear-Verbindung erfolgreich',
                    'user' => $userData['name'] ?? 'Unknown'
                ];
            }

            throw new Exception("HTTP {$response->status()}");

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Linear-Verbindung fehlgeschlagen: ' . $e->getMessage()
            ];
        }
    }

    public function addComment(string $ticketKey, string $comment): bool
    {
        try {
            $mutation = '
                mutation IssueCommentCreate($issueId: String!, $body: String!) {
                    commentCreate(input: {
                        issueId: $issueId
                        body: $body
                    }) {
                        success
                        comment {
                            id
                        }
                    }
                }
            ';

            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json'
            ])->post("{$this->baseUrl}/graphql", [
                'query' => $mutation,
                'variables' => [
                    'issueId' => $ticketKey,
                    'body' => $comment
                ]
            ]);

            if ($response->successful()) {
                $result = $response->json();
                return $result['data']['commentCreate']['success'] ?? false;
            }

            return false;

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Hinzufügen des Linear-Kommentars', [
                'ticket_key' => $ticketKey,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function updateTicketStatus(string $ticketKey, string $status): bool
    {
        try {
            // Linear verwendet State IDs statt Namen
            $stateId = $this->mapStatusToStateId($status);
            
            if (!$stateId) {
                $this->logger->warning('Unbekannter Status für Linear', [
                    'status' => $status
                ]);
                return false;
            }

            $mutation = '
                mutation IssueUpdate($issueId: String!, $stateId: String!) {
                    issueUpdate(id: $issueId, input: {
                        stateId: $stateId
                    }) {
                        success
                        issue {
                            id
                            state {
                                name
                            }
                        }
                    }
                }
            ';

            $response = Http::withHeaders([
                'Authorization' => $this->apiKey,
                'Content-Type' => 'application/json'
            ])->post("{$this->baseUrl}/graphql", [
                'query' => $mutation,
                'variables' => [
                    'issueId' => $ticketKey,
                    'stateId' => $stateId
                ]
            ]);

            if ($response->successful()) {
                $result = $response->json();
                return $result['data']['issueUpdate']['success'] ?? false;
            }

            return false;

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Aktualisieren des Linear-Status', [
                'ticket_key' => $ticketKey,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function getProviderName(): string
    {
        return 'linear';
    }

    public function getSupportedStatuses(): array
    {
        return [
            'Backlog',
            'Todo', 
            'In Progress',
            'In Review',
            'Done',
            'Canceled'
        ];
    }

    public function validateConfiguration(): array
    {
        $errors = [];

        if (empty($this->apiKey)) {
            $errors[] = 'Linear API Key nicht konfiguriert';
        }

        return $errors;
    }

    private function buildGraphQLQuery(): string
    {
        return '
            query Issues {
                issues(
                    filter: {
                        state: { type: { in: ["unstarted", "started"] } }
                        assignee: { null: true }
                    }
                    first: 50
                ) {
                    nodes {
                        id
                        identifier
                        title
                        description
                        priority
                        state {
                            name
                        }
                        assignee {
                            name
                        }
                        creator {
                            name
                        }
                        labels {
                            nodes {
                                name
                            }
                        }
                        createdAt
                        updatedAt
                        url
                    }
                }
            }
        ';
    }

    private function parseLinearIssue(array $issue): ?TicketDTO
    {
        try {
            // Repository-URL aus Labels oder Beschreibung extrahieren
            $repositoryUrl = $this->extractRepositoryUrl($issue);
            
            if (!$repositoryUrl) {
                return null; // Skip Issues ohne Repository-Link
            }

            $labels = array_map(
                fn($label) => $label['name'], 
                $issue['labels']['nodes'] ?? []
            );

            return new TicketDTO(
                key: $issue['identifier'],
                summary: $issue['title'],
                description: $issue['description'] ?? '',
                status: $issue['state']['name'],
                priority: $this->mapLinearPriority($issue['priority']),
                assignee: $issue['assignee']['name'] ?? null,
                reporter: $issue['creator']['name'] ?? 'Unknown',
                created: Carbon::parse($issue['createdAt']),
                updated: Carbon::parse($issue['updatedAt']),
                labels: $labels,
                repositoryUrl: $repositoryUrl
            );

        } catch (Exception $e) {
            $this->logger->error('Fehler beim Parsen des Linear-Issues', [
                'issue_id' => $issue['id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function extractRepositoryUrl(array $issue): ?string
    {
        // Aus Beschreibung extrahieren
        $description = $issue['description'] ?? '';
        if (preg_match('/https:\/\/github\.com\/[^\s]+/', $description, $matches)) {
            return $matches[0];
        }

        // Aus Labels extrahieren
        foreach ($issue['labels']['nodes'] ?? [] as $label) {
            if (str_starts_with($label['name'], 'repo:')) {
                return str_replace('repo:', 'https://github.com/', $label['name']);
            }
        }

        return null;
    }

    private function mapLinearPriority(int $priority): string
    {
        return match($priority) {
            0 => 'No priority',
            1 => 'Urgent',
            2 => 'High',
            3 => 'Medium',
            4 => 'Low',
            default => 'Medium'
        };
    }

    private function mapStatusToStateId(string $status): ?string
    {
        // Diese IDs müssten aus der Linear-Konfiguration kommen
        $statusMapping = [
            'Todo' => 'state-todo-id',
            'In Progress' => 'state-progress-id', 
            'In Review' => 'state-review-id',
            'Done' => 'state-done-id'
        ];

        return $statusMapping[$status] ?? null;
    }

    private function storeTicketInDatabase(TicketDTO $ticket): void
    {
        // Gleiche Logik wie in JiraService
        try {
            \Illuminate\Support\Facades\DB::table('tickets')->updateOrInsert(
                ['jira_key' => $ticket->key],
                [
                    'jira_key' => $ticket->key,
                    'summary' => $ticket->summary,
                    'description' => $ticket->description,
                    'status' => 'pending',
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
        } catch (Exception $e) {
            $this->logger->error('Fehler beim Speichern des Linear-Tickets', [
                'ticket' => $ticket->key,
                'error' => $e->getMessage()
            ]);
        }
    }
} 