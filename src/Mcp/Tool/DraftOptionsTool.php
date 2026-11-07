<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\McpSession;
use App\Mcp\McpToolException;
use App\Service\Suggestion\Kind\AbstractAiKind;
use App\Service\Suggestion\RecordDrafts;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Which drafts the caller may ask for on a record ("Entwurf vorschlagen"):
 * for text drafts the instruction with the JSON keys and the facts the
 * app's own model would get, so the assistant drafts the same way.
 */
final class DraftOptionsTool extends AbstractMcpTool
{
    public function __construct(
        private readonly RecordDrafts $drafts,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return 'draft_options';
    }

    public function description(): string
    {
        return 'Drafts you may propose for one record (risk from an incident, corrective action, SoA justification, '
            . 'breach notification, incident report, board summary, …). For each: kind key, label, whether you must write '
            . 'the text (needs_data) and then the instructions with the JSON keys plus the facts about the record. '
            . 'Then call propose_draft.';
    }

    public function inputSchema(McpSession $session): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'resource' => ['type' => 'string', 'enum' => $this->drafts->subjectNames()],
                'id' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['resource', 'id'],
        ];
    }

    public function call(array $arguments, McpSession $session): array
    {
        $resource = (string) self::string($arguments, 'resource', true, 100);
        $id = (int) self::int($arguments, 'id', required: true);
        $record = $this->drafts->resolveByName($resource, $id, $session->tenant)
            ?? throw new McpToolException(sprintf('No %s %d in your organisation that drafts can be made for.', $resource, $id));

        $options = [];
        foreach ($this->drafts->kindsFor($record, byAssistant: true) as $kind) {
            $brief = $kind instanceof AbstractAiKind ? $kind->assistantBrief($record) : null;
            $options[] = [
                'kind' => $kind->key(),
                'label' => $this->translator->trans('suggestions.kind.' . $kind->key(), [], 'suggestions', $session->locale),
                'help' => $this->translator->trans('suggestions.kind_help.' . $kind->key(), [], 'suggestions', $session->locale),
                'needs_data' => $kind instanceof AbstractAiKind,
                'instructions' => $brief['instructions'] ?? null,
                'facts' => $brief['facts'] ?? null,
            ];
        }

        return [
            'resource' => $resource,
            'id' => $id,
            'options' => $options,
            'note' => $options === [] ? 'Nothing to draft: a draft is already pending, the record does not need one, or your role may not ask for it.' : null,
            '_audit' => ['resource' => $resource, 'record_id' => $id, 'count' => count($options)],
        ];
    }
}
