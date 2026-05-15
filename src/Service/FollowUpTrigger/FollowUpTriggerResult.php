<?php

declare(strict_types=1);

namespace App\Service\FollowUpTrigger;

/**
 * Immutable evaluation result of FollowUpTriggerService::evaluate().
 *
 * Surfaces the matched definition plus the resolved pre-fill payload so a
 * listener / controller can persist a skeleton entity or build a deeplink
 * with query parameters that hydrate the follow-up form.
 *
 * @phpstan-type PreFillPayload array<string, mixed>
 */
final readonly class FollowUpTriggerResult
{
    /**
     * @param array<string, mixed> $preFillPayload
     */
    public function __construct(
        public FollowUpTriggerDefinition $definition,
        public array $preFillPayload = [],
    ) {
    }
}
