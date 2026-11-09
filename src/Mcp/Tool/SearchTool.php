<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\McpSession;
use App\Mcp\PersonalDataPolicy;
use App\Service\Search\SearchService;

/**
 * The app's global search (⌘K) over the organisation's records and pages.
 *
 * Hits travel to an external model. Search groups of classes marked
 * #[PersonalDataResource] follow {@see PersonalDataPolicy}, the same rule as
 * list_records / get_record: without the data protection officer's role a
 * group of data-subject records (consents, data subject requests, data
 * breaches) is left out, and a group whose records carry personal fields
 * (people) returns only hits whose title — the name the app shows anyway —
 * matches, without the description (it may carry the e-mail). How many hits
 * were left out is not said (no existence oracle).
 */
final class SearchTool extends AbstractMcpTool
{
    public function __construct(
        private readonly SearchService $search,
        private readonly PersonalDataPolicy $personalData,
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
        $mayReadPersonalData = $this->personalData->mayReadPersonalData($session);
        $markedGroups = $this->personalData->searchGroups();
        foreach ($this->search->search($query, $session->tenant) as $group => $items) {
            if (!is_array($items)) {
                continue;
            }
            $marker = $mayReadPersonalData ? null : ($markedGroups[$group] ?? null);
            if ($marker !== null && !$marker->isFieldLevel()) {
                $withheld += count($items);
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if ($marker !== null && mb_stripos((string) ($item['title'] ?? ''), $query) === false) {
                    ++$withheld; // matched on a personal field only
                    continue;
                }
                $hits[] = [
                    'group' => $group,
                    'type' => $item['type'] ?? $group,
                    'id' => $item['id'] ?? null,
                    'title' => $item['title'] ?? null,
                    'description' => $marker !== null ? null : ($item['description'] ?? null),
                    'url' => isset($item['url']) && is_string($item['url']) ? $session->url($item['url']) : null,
                ];
            }
        }

        $result = ['query' => $query, 'count' => count($hits), 'hits' => $hits];
        if (!$mayReadPersonalData) {
            $result['note'] = 'Records about data subjects (consents, data subject requests, data breaches) and personal '
                . 'details of people are searched only in the app (or by the data protection officer role).';
        }

        return $result + ['_audit' => ['count' => count($hits), 'withheld' => $withheld]];
    }
}
