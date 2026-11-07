<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\McpSession;
use App\Service\Search\SearchService;

/**
 * The app's global search (⌘K) over the organisation's records and pages.
 */
final class SearchTool extends AbstractMcpTool
{
    public function __construct(private readonly SearchService $search)
    {
    }

    public function name(): string
    {
        return 'search';
    }

    public function description(): string
    {
        return 'Full-text search across the organisation\'s records (assets, risks, controls, incidents, documents, suppliers, '
            . 'audits, findings, BCM, privacy records, …), compliance requirements and app pages. Returns type, id, title and link.';
    }

    public function inputSchema(McpSession $session): array
    {
        return [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string', 'minLength' => 2]],
            'required' => ['query'],
        ];
    }

    public function call(array $arguments, McpSession $session): array
    {
        $query = (string) self::string($arguments, 'query', true, 100);
        $hits = [];
        foreach ($this->search->search($query, $session->tenant) as $group => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $hits[] = [
                    'group' => $group,
                    'type' => $item['type'] ?? $group,
                    'id' => $item['id'] ?? null,
                    'title' => $item['title'] ?? null,
                    'description' => $item['description'] ?? null,
                    'url' => isset($item['url']) && is_string($item['url']) ? $session->url($item['url']) : null,
                ];
            }
        }

        return ['query' => $query, 'count' => count($hits), 'hits' => $hits, '_audit' => ['count' => count($hits)]];
    }
}
