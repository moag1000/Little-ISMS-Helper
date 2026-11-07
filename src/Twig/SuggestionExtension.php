<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Suggestion;
use App\Service\Suggestion\SuggestionQueue;
use Twig\Attribute\AsTwigFunction;

/**
 * Open suggestions of a record for the detail-page hint above the links
 * block (`_components/_fa_entity_links.html.twig`): "1 suggestion open".
 */
final class SuggestionExtension
{
    public function __construct(
        private readonly SuggestionQueue $queue,
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
}
