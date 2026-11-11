<?php

declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/**
 * S28 (F-04): being responsible for a record of this class opens an action a
 * plain user's role does not — marking a NIS-2 report as sent, deciding the
 * treatment of a risk, closing a data subject request, approving an owner step
 * of an approval chain (`RECORD_RESPONSIBLE`, lifecycle `responsible: true`,
 * `approver_role: owner`).
 *
 * So nobody without manager scope names themselves in a slot of such a record
 * when CREATING it either (S20 covers changes of stored records for every
 * class): the slot stays empty and {@see \App\Service\Responsibility\ResponsibilityResolver}
 * falls back to the roles of the duty (ISB/CISO, risk manager, DPO …). Which
 * slots count is the resolver's answer, not a second list here.
 *
 * Enforced on flush by {@see \App\EventListener\SelfAssignmentGuard}, shown as
 * form error by {@see \App\Form\Extension\SelfAssignmentFormExtension}.
 * tests/Contract/ResponsibleSlotWriteContractTest finds every class whose
 * responsibility grants an action and fails when it lacks this attribute.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ResponsibilityGrantsAuthority
{
}
