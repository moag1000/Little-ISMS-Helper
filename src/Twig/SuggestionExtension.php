<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Suggestion;
use App\Service\Suggestion\RecordDrafts;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionQueue;
use Twig\Attribute\AsTwigFunction;

/**
 * Open suggestions of a record for the detail-page hint above the links
 * block (`_components/_fa_entity_links.html.twig`): "1 suggestion open",
 * and the drafts the current user may ask for there ("Entwurf vorschlagen", P6).
 */
final class SuggestionExtension
{
    public function __construct(
        private readonly SuggestionQueue $queue,
        private readonly RecordDrafts $drafts,
    ) {
    }

    /** @return list<Suggestion> pending suggestions about the record the current user may decide */
    #[AsTwigFunction('pending_suggestions')]
    public function pendingSuggestions(object $entity): array
    {
        try {
            return $this->queue->pendingForSubject($entity);
        } catch (\Throwable) {
            // The hint must never break a detail page (e.g. table missing before the migration ran).
            return [];
        }
    }

    /**
     * Drafts the current user may ask for on the record (none pending).
     *
     * @return list<array{key: string, class: string, id: int, model: bool}>
     */
    #[AsTwigFunction('draft_kinds')]
    public function draftKinds(object $entity): array
    {
        try {
            $class = (string) (new SuggestionDraft($entity, '', ''))->subjectClass();
            $id = (new SuggestionDraft($entity, '', ''))->subjectId();
            if ($id === null) {
                return [];
            }

            return array_map(
                static fn ($kind): array => ['key' => $kind->key(), 'class' => $class, 'id' => $id, 'model' => $kind->needsModel()],
                $this->drafts->kindsFor($entity),
            );
        } catch (\Throwable) {
            // Same as the hint: a detail page never breaks over a draft button.
            return [];
        }
    }
}
