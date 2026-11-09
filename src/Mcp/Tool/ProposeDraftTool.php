<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\McpSession;
use App\Mcp\PersonalDataPolicy;
use App\Mcp\McpToolException;
use App\Entity\Suggestion;
use App\Enum\SuggestionStatus;
use App\Repository\SuggestionRepository;
use App\Service\Suggestion\Kind\AbstractAiKind;
use App\Service\Suggestion\RecordDraftKind;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\RecordDrafts;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Puts a draft into the suggestion queue (for anything that needs a person's
 * decision; plain field changes go through save_record). Nothing is
 * written to the record — a person accepts (optionally edited) or rejects
 * the suggestion in the app; both are audit-logged by the queue. Text drafts
 * carry the assistant as model, so the queue shows where they came from.
 */
final class ProposeDraftTool extends AbstractMcpTool
{
    /** Rejections of one kind for one record within 30 days after which the assistant stops. */
    public const int MAX_REJECTIONS = 3;

    public function __construct(
        private readonly RecordDrafts $drafts,
        private readonly PersonalDataPolicy $personalData,
        private readonly UrlGeneratorInterface $urls,
        private readonly SuggestionRepository $suggestions,
    ) {
    }

    public function name(): string
    {
        return 'propose_draft';
    }

    public function description(): string
    {
        return 'Propose a draft for a record (kind from draft_options). For kinds with needs_data, pass data with exactly '
            . 'the JSON keys the instructions name (your own wording, based on the facts). The draft goes to the suggestion '
            . 'queue; a responsible person accepts, edits or rejects it in the app. Nothing is changed before that.';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function inputSchema(McpSession $session): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'description' => 'Kind key from draft_options'],
                'resource' => ['type' => 'string', 'enum' => $this->drafts->subjectNames()],
                'id' => ['type' => 'integer', 'minimum' => 1],
                'data' => ['type' => 'object', 'description' => 'The JSON keys the kind\'s instructions ask for', 'additionalProperties' => true],
            ],
            'required' => ['kind', 'resource', 'id'],
        ];
    }

    public function call(array $arguments, McpSession $session): array
    {
        $key = (string) self::string($arguments, 'kind', true, 100);
        $resource = (string) self::string($arguments, 'resource', true, 100);
        $id = (int) self::int($arguments, 'id', required: true);
        $record = $this->drafts->resolveByName($resource, $id, $session->tenant);
        // Records about data subjects go to the assistant only for the DPO role (S24, same rule as list_records).
        if ($record === null || !$this->personalData->offers($record::class, $session)) {
            throw new McpToolException(sprintf(
                'No %s %d in your organisation that drafts can be made for.',
                $resource,
                $id,
            ));
        }

        $kind = null;
        foreach ($this->drafts->kindsFor($record, byAssistant: true) as $candidate) {
            if ($candidate->key() === $key) {
                $kind = $candidate;
            }
        }
        if ($kind === null) {
            throw new McpToolException(sprintf('"%s" is not available for this record (see draft_options).', $key));
        }

        if ($kind instanceof AbstractAiKind) {
            $data = is_array($arguments['data'] ?? null) ? $arguments['data'] : [];
            $draft = $kind->draftFromAssistant($session->tenant, $record, $data, mb_substr('MCP: ' . $session->token->getLabel(), 0, 120));
            if ($draft === null) {
                throw new McpToolException('The draft is incomplete. Pass data with the keys the instructions of draft_options name.');
            }
        } else {
            $draft = $kind->draftFor($session->tenant, $record)
                ?? throw new McpToolException('Nothing could be drafted from the record\'s data.');
        }

        $this->refuseRepeatedRejection($session, $kind, $draft);

        $suggestion = $this->drafts->offer($session->tenant, $kind, $draft)
            ?? throw new McpToolException('The suggestion could not be recorded.');

        return [
            'suggestion_id' => $suggestion->getId(),
            'title' => $suggestion->getTitle(),
            'status' => $suggestion->getStatus()->value,
            'decide_at' => $this->urls->generate('app_suggestion_index', ['_locale' => $session->locale], UrlGeneratorInterface::ABSOLUTE_URL),
            'note' => 'Waiting for a person\'s decision in the suggestion queue.',
            '_audit' => ['resource' => $resource, 'record_id' => $id, 'kind' => $key, 'suggestion_id' => $suggestion->getId()],
        ];
    }

    /**
     * A person's "no" stands: the assistant may not offer the same draft again,
     * and after three rejections of one kind for one record within 30 days it
     * stops proposing that kind there. The person can still ask in the app.
     */
    private function refuseRepeatedRejection(McpSession $session, RecordDraftKind $kind, SuggestionDraft $draft): void
    {
        $same = $this->suggestions->findOneByDedup($session->tenant, $kind->key(), $draft->resolveDedupKey($kind->key()));
        if ($same instanceof Suggestion && $same->getStatus() === SuggestionStatus::Rejected) {
            throw McpToolException::denied(sprintf(
                'This draft was rejected on %s. Change it or let the person ask again in the app.',
                $same->getDecidedAt()?->format('Y-m-d') ?? 'an earlier date',
            ));
        }

        $subjectClass = $draft->subjectClass();
        $subjectId = $draft->subjectId();
        if ($subjectClass !== null && $subjectId !== null
            && $this->suggestions->countRejectedForSubject($session->tenant, $kind->key(), $subjectClass, $subjectId, new \DateTimeImmutable('-30 days')) >= self::MAX_REJECTIONS) {
            throw McpToolException::denied(sprintf(
                'Drafts of "%s" for this record were rejected %d times in 30 days. The assistant stops proposing it here.',
                $kind->key(),
                self::MAX_REJECTIONS,
            ));
        }
    }
}
