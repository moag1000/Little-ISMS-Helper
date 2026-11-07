<?php

declare(strict_types=1);

namespace App\Job;

use App\Exception\Tenant\TenantNotFoundException;
use App\Service\Job\JobTenantScope;
use App\Service\Suggestion\SuggestionGenerator;
use App\Service\Suggestion\SuggestionText;

/**
 * "Check now" on the suggestion queue: runs every kind for the dispatching
 * user's organisation — the same as the nightly app:suggestions:generate run.
 * Async because AI kinds wait for the model (local models can take minutes).
 */
final class RefreshSuggestionsJob implements AsyncJobInterface
{
    public function __construct(
        private readonly SuggestionGenerator $generator,
        private readonly JobTenantScope $tenantScope,
        private readonly SuggestionText $text,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $tenant = $this->tenantScope->resolve($ctx);
        if ($tenant === null) {
            throw new TenantNotFoundException(null, 'Suggestions are generated per organisation.');
        }
        $ctx->message($this->text->t($tenant, 'suggestions.job.running'));
        $result = $this->generator->run($tenant);
        $ctx->updatePayload(['created' => $result['created'], 'superseded' => $result['superseded']]);
        $ctx->progress(1, 1, $this->text->t($tenant, 'suggestions.job.done', [
            '%created%' => $result['created'],
            '%superseded%' => $result['superseded'],
        ]));
    }
}
