<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

use App\Entity\Tenant;

/**
 * A draft kind whose content the person's own assistant (MCP `propose_draft`) writes: the brief tells it what to
 * send, the answer becomes the draft. Model kinds ({@see Kind\AbstractAiKind}) and the status proposal
 * ({@see Kind\RecordTransitionKind}) are such sources.
 */
interface AssistantDraftSource extends RecordDraftKind
{
    /**
     * What the assistant needs to draft: the instruction with the keys to send and the facts about the record.
     * Null when the record has nothing to draft for.
     *
     * @return array{instructions: string, facts: string}|null
     */
    public function assistantBrief(object $record): ?array;

    /**
     * The draft from the assistant's answer (same checks as a model answer); null when incomplete or invalid.
     *
     * @param array<string, mixed> $data the keys the instruction asks for
     */
    public function draftFromAssistant(Tenant $tenant, object $record, array $data, string $model): ?SuggestionDraft;
}
