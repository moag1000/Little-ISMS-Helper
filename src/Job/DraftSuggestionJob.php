<?php

declare(strict_types=1);

namespace App\Job;

use App\Exception\Tenant\TenantNotFoundException;
use App\Service\Job\JobTenantScope;
use App\Service\Suggestion\RecordDraftKind;
use App\Service\Suggestion\RecordDrafts;
use App\Service\Suggestion\SuggestionQueue;
use App\Service\Suggestion\SuggestionText;
use App\Service\TenantContext;

/**
 * "Entwurf vorschlagen" with a language model (P6): asks the kind for one
 * record and puts the draft into the queue. Async because local models can
 * take a minute; the controller checked who may ask before dispatching.
 * Args: tenantId, kind, class, id.
 */
final class DraftSuggestionJob implements AsyncJobInterface
{
    public function __construct(
        private readonly RecordDrafts $drafts,
        private readonly SuggestionQueue $queue,
        private readonly JobTenantScope $tenantScope,
        private readonly TenantContext $tenantContext,
        private readonly SuggestionText $text,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $tenant = $this->tenantScope->resolve($ctx);
        if ($tenant === null) {
            throw new TenantNotFoundException(null, 'Drafts are made per organisation.');
        }
        $kind = $this->queue->kind((string) $ctx->arg('kind'));
        if (!$kind instanceof RecordDraftKind) {
            // @intentional-assertion: the controller dispatches only known draft kinds
            throw new \InvalidArgumentException('Unknown draft kind.');
        }
        $previous = $this->tenantContext->getCurrentTenant();
        // After the response is flushed the request is gone; scope the Doctrine filter explicitly.
        $this->tenantContext->setCurrentTenant($tenant);
        try {
            $ctx->message($this->text->t($tenant, 'suggestions.draft.running'));
            $record = $this->drafts->resolve($kind, (string) $ctx->arg('class'), (int) $ctx->arg('id'), $tenant);
            $suggestion = $record !== null ? $this->drafts->draft($tenant, $kind, $record) : null;
            $ctx->updatePayload(['suggestion' => $suggestion?->getId()]);
            $ctx->progress(1, 1, $this->text->t($tenant, $suggestion !== null ? 'suggestions.draft.done' : 'suggestions.draft.none'));
        } finally {
            $this->tenantContext->setCurrentTenant($previous);
        }
    }
}
