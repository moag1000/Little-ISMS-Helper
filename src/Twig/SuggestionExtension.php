<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Service\Suggestion\SuggestionQueue;
use App\Service\TenantContext;
use Twig\Attribute\AsTwigFunction;

/**
 * Open suggestions of a record for the detail-page hint above the links
 * block (`_components/_fa_entity_links.html.twig`): "1 suggestion open",
 * and the count for the link on My Day.
 */
final class SuggestionExtension
{
    public function __construct(
        private readonly SuggestionQueue $queue,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /** Open suggestions the current user may decide, for the My Day link. */
    #[AsTwigFunction('open_suggestion_count')]
    public function openSuggestionCount(): int
    {
        try {
            $tenant = $this->tenantContext->getCurrentTenant();

            return $tenant instanceof Tenant ? $this->queue->countPendingFor($tenant) : 0;
        } catch (\Throwable) {
            return 0;
        }
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
