<?php

namespace App\DTOs;

use App\Enums\TicketStatus;
use Carbon\Carbon;

class TicketDTO
{
    public function __construct(
        public readonly string $key,
        public readonly string $projectKey,
        public readonly string $summary,
        public readonly string $description,
        public readonly TicketStatus $status,
        public readonly ?string $assignee,
        public readonly ?string $linkedRepository,
        public readonly array $labels,
        public readonly array $components,
        public readonly ?string $priority,
        public readonly ?string $issueType,
        public readonly Carbon $createdAt,
        public readonly Carbon $updatedAt,
        public readonly array $customFields = [],
        public readonly array $attachments = [],
        public readonly array $comments = []
    ) {}

    public static function fromJiraResponse(array $issue): self
    {
        $fields = $issue['fields'] ?? [];
        
        return new self(
            key: $issue['key'],
            projectKey: $fields['project']['key'] ?? '',
            summary: $fields['summary'] ?? '',
            description: $fields['description'] ?? '',
            status: TicketStatus::PENDING,
            assignee: $fields['assignee']['displayName'] ?? null,
            linkedRepository: self::extractLinkedRepository($fields),
            labels: $fields['labels'] ?? [],
            components: array_map(fn($c) => $c['name'], $fields['components'] ?? []),
            priority: $fields['priority']['name'] ?? null,
            issueType: $fields['issuetype']['name'] ?? null,
            createdAt: Carbon::parse($fields['created']),
            updatedAt: Carbon::parse($fields['updated']),
            customFields: self::extractCustomFields($fields),
            attachments: self::extractAttachments($fields),
            comments: self::extractComments($fields)
        );
    }

    private static function extractLinkedRepository(array $fields): ?string
    {
        // Check for custom field that contains repository information
        foreach ($fields as $key => $value) {
            if (str_contains(strtolower($key), 'repository') || str_contains(strtolower($key), 'repo')) {
                if (is_string($value) && !empty($value)) {
                    return $value;
                }
            }
        }

        // Check in description for GitHub/GitLab URLs
        $description = $fields['description'] ?? '';
        if (preg_match('/https:\/\/github\.com\/([^\/]+\/[^\/\s]+)/', $description, $matches)) {
            return $matches[1];
        }

        if (preg_match('/https:\/\/gitlab\.com\/([^\/]+\/[^\/\s]+)/', $description, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private static function extractCustomFields(array $fields): array
    {
        $customFields = [];
        
        foreach ($fields as $key => $value) {
            if (str_starts_with($key, 'customfield_')) {
                $customFields[$key] = $value;
            }
        }
        
        return $customFields;
    }

    private static function extractAttachments(array $fields): array
    {
        $attachments = [];
        
        if (isset($fields['attachment']) && is_array($fields['attachment'])) {
            foreach ($fields['attachment'] as $attachment) {
                $attachments[] = [
                    'id' => $attachment['id'] ?? null,
                    'filename' => $attachment['filename'] ?? null,
                    'size' => $attachment['size'] ?? null,
                    'mimeType' => $attachment['mimeType'] ?? null,
                    'content' => $attachment['content'] ?? null,
                ];
            }
        }
        
        return $attachments;
    }

    private static function extractComments(array $fields): array
    {
        $comments = [];
        
        if (isset($fields['comment']['comments']) && is_array($fields['comment']['comments'])) {
            foreach ($fields['comment']['comments'] as $comment) {
                $comments[] = [
                    'id' => $comment['id'] ?? null,
                    'author' => $comment['author']['displayName'] ?? null,
                    'body' => $comment['body'] ?? null,
                    'created' => isset($comment['created']) ? Carbon::parse($comment['created']) : null,
                    'updated' => isset($comment['updated']) ? Carbon::parse($comment['updated']) : null,
                ];
            }
        }
        
        return $comments;
    }

    public function hasRequiredLabel(string $requiredLabel): bool
    {
        return in_array($requiredLabel, $this->labels);
    }

    public function isUnassigned(): bool
    {
        return $this->assignee === null;
    }

    public function hasLinkedRepository(): bool
    {
        return $this->linkedRepository !== null;
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'project_key' => $this->projectKey,
            'summary' => $this->summary,
            'description' => $this->description,
            'status' => $this->status->value,
            'assignee' => $this->assignee,
            'linked_repository' => $this->linkedRepository,
            'labels' => $this->labels,
            'components' => $this->components,
            'priority' => $this->priority,
            'issue_type' => $this->issueType,
            'created_at' => $this->createdAt->toISOString(),
            'updated_at' => $this->updatedAt->toISOString(),
            'custom_fields' => $this->customFields,
            'attachments' => $this->attachments,
            'comments' => $this->comments,
        ];
    }
} 