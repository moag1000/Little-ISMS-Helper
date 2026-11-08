<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Suggestion;
use App\Mcp\McpSession;
use App\Service\Suggestion\SuggestionQueue;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Suggestions waiting for the caller's decision (the queue under
 * /suggestions). Deciding stays in the app: the assistant can explain a
 * suggestion, the person accepts or rejects it there.
 */
final class PendingSuggestionsTool extends AbstractMcpTool
{
    public function __construct(
        private readonly SuggestionQueue $queue,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return 'pending_suggestions';
    }

    public function description(): string
    {
        return 'Suggestions waiting for this person\'s decision (derived by rules, inherited, imported or drafted by an AI or '
            . 'by you via propose_draft): kind, origin, title, rationale, proposed text. Accepting or rejecting happens in the app.';
    }

    public function inputSchema(McpSession $session): array
    {
        return [
            'type' => 'object',
            'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 30]],
        ];
    }

    public function call(array $arguments, McpSession $session): array
    {
        $pending = $this->queue->pendingFor($session->tenant);
        $limit = max(1, min(100, (int) self::int($arguments, 'limit', 30)));
        $rows = array_map(fn (Suggestion $s): array => [
            'id' => $s->getId(),
            'kind' => $s->getKind(),
            'kind_label' => $this->translator->trans('suggestions.kind.' . $s->getKind(), [], 'suggestions', $session->locale),
            'origin' => $s->getOrigin()->value,
            'title' => $s->getTitle(),
            'rationale' => $s->getRationale(),
            'proposed_text' => $s->getEditableText(),
            'subject' => $s->getSubjectClass() !== null ? ['type' => substr((string) strrchr('\\' . $s->getSubjectClass(), '\\'), 1), 'id' => $s->getSubjectId()] : null,
            'created' => self::date($s->getCreatedAt()),
        ], array_slice($pending, 0, $limit));

        return [
            'total' => count($pending),
            'suggestions' => $rows,
            'decide_at' => $this->urls->generate('app_suggestion_index', ['_locale' => $session->locale], UrlGeneratorInterface::ABSOLUTE_URL),
            '_audit' => ['count' => count($rows)],
        ];
    }
}
