<?php

declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * S22: a field that decides duties, protection need or responsibility —
 * the classification of an incident (does it have to be reported?), the
 * protection need of an asset, the criticality of a supplier. Whoever creates
 * the record sets it; afterwards only a manager or the record's responsible
 * person ({@see \App\Security\Voter\RecordResponsibleVoter}, judged by the
 * record as it was before the change) changes it. Narrative fields
 * (description, measures) stay collaborative.
 *
 * One rule for every writer: {@see \App\EventListener\WriteRuleGuard} refuses
 * the change on flush (form, import, REST, assistant via MCP), the web forms
 * show the field read-only ({@see \App\Form\Extension\WriteRuleFormExtension}).
 * tests/Security/WritePathParityTest.php (Gate 96) keeps both in step.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class ManagerOrResponsibleField
{
    public function __construct(
        public string $reason,
    ) {
    }
}
