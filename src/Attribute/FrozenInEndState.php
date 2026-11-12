<?php

declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * S34 (R7): the result of a record that was sealed by its lifecycle (a place with
 * `end_state: true`, e.g. a completed BC exercise). Once the record sits in an end
 * state the field changes only as a correction: by a manager, with a reason
 * ({@see \App\Service\WriteRule\CorrectionReason}: form field, or the
 * `X-Correction-Reason` header on REST/MCP), audit-logged with before and after.
 * Enforced for every writer by {@see \App\EventListener\WriteRuleGuard}; forms show
 * the field read-only.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class FrozenInEndState
{
    public function __construct(
        public string $reason,
    ) {
    }
}
