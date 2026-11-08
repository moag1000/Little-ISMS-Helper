<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\McpSession;
use App\Security\Persona\PersonaResolver;
use App\Service\Search\SearchService;

/**
 * The app's global search (⌘K) over the organisation's records and pages.
 *
 * Hits travel to an external model. Groups whose titles name data subjects
 * (people, consents, data subject requests, data breaches) are returned only
 * to the data protection officer's role — data minimisation, GDPR Art. 5 (1) c
 * and 25; everybody else gets them through the app itself.
 */
final class SearchTool extends AbstractMcpTool
{
    /** Search groups that carry names of data subjects. */
    public const array PERSONAL_DATA_GROUPS = ['persons', 'consents', 'data_subject_requests', 'data_breaches'];

    public function __construct(
        private readonly SearchService $search,
        private readonly PersonaResolver $personas,
    ) {
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
        $withheld = 0;
        $mayReadPersonalData = $this->personas->reaches($session->user, 'ROLE_DPO');
        foreach ($this->search->search($query, $session->tenant) as $group => $items) {
            if (!is_array($items)) {
                continue;
            }
            if (!$mayReadPersonalData && in_array($group, self::PERSONAL_DATA_GROUPS, true)) {
                $withheld += count($items);
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

        $result = ['query' => $query, 'count' => count($hits), 'hits' => $hits];
        if ($withheld > 0) {
            $result['withheld_personal_data_hits'] = $withheld;
            $result['note'] = 'Hits naming data subjects are shown only in the app (or to the data protection officer role).';
        }

        return $result + ['_audit' => ['count' => count($hits), 'withheld' => $withheld]];
    }
}
