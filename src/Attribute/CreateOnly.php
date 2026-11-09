<?php

declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * S22: a value fixed when the record is created — the moment an incident or
 * a data breach became known (the NIS-2 Art. 23 / GDPR Art. 33 deadlines run
 * from it), the risk a treatment plan belongs to. Moving it later would make a
 * late report look on time or hang a plan on another risk.
 *
 * After creation only a manager corrects it (the audit log keeps before and
 * after); the bulk import never changes it on an existing record, the web
 * forms show it read-only to everybody else. Enforced for every writer by
 * {@see \App\EventListener\WriteRuleGuard}; Gate 96 (WritePathParityTest).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class CreateOnly
{
    public function __construct(
        public string $reason,
    ) {
    }
}
