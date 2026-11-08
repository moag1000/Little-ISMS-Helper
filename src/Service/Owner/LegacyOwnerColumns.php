<?php

declare(strict_types=1);

namespace App\Service\Owner;

/**
 * The 14 free-text owner columns that existed until 2026-11 (migration
 * Version20261108100100_DropLegacyOwnerColumns) and the User / Person slot
 * each one was replaced by — for data that still carries the old key: an
 * organisation backup written before the drop is restored through
 * {@see OwnerTextResolver} with this map.
 *
 * entity short name => [old text key, User property, Person property]
 */
final class LegacyOwnerColumns
{
    /** @var array<string, array{string, string, string}> */
    public const array FIELDS = [
        'Asset' => ['owner', 'ownerUser', 'ownerPerson'],
        'Control' => ['responsiblePerson', 'responsiblePersonUser', 'responsiblePersonRef'],
        'BusinessProcess' => ['processOwner', 'processOwnerUser', 'processOwnerPerson'],
        'BusinessContinuityPlan' => ['planOwner', 'planOwnerUser', 'planOwnerPerson'],
        'BCExercise' => ['facilitator', 'facilitatorUser', 'facilitatorPerson'],
        'Incident' => ['reportedBy', 'reportedByUser', 'reportedByPerson'],
        'InternalAudit' => ['leadAuditor', 'leadAuditorUser', 'leadAuditorPerson'],
        'Training' => ['trainer', 'trainerUser', 'trainerPerson'],
        'ISMSObjective' => ['responsiblePerson', 'responsiblePersonUser', 'responsiblePersonRef'],
        'Supplier' => ['contactPerson', 'contactPersonUser', 'contactPersonRef'],
        'InterestedParty' => ['contactPerson', 'contactPersonUser', 'contactPersonRef'],
        'Location' => ['responsiblePerson', 'responsiblePersonUser', 'responsiblePersonRef'],
        'Vulnerability' => ['responsiblePerson', 'responsiblePersonUser', 'responsiblePersonRef'],
        'Patch' => ['responsiblePerson', 'responsiblePersonUser', 'responsiblePersonRef'],
    ];
}
