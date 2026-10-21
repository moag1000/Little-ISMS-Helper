<?php

declare(strict_types=1);

namespace App\Lifecycle;

/**
 * Who, besides the initiator, could grant a four-eyes transition — passed by
 * callers that authorise the approver against a more specific rule than the
 * transition's `roles` (WorkflowService: the workflow step's approver_role /
 * approver_users, plus ROLE_ADMIN).
 *
 * FourEyesValidator turns this into ONE count query (no user is loaded) to
 * decide the single-approver escape: a user is eligible when one of their
 * roles reaches any of {@see $roles} through the Symfony role hierarchy, or
 * when their id is listed in {@see $userIds}.
 */
final readonly class EligibleApprovers
{
    /**
     * @param list<string> $roles   roles that qualify, resolved through the role hierarchy
     * @param list<int>    $userIds users that qualify by id, whatever their roles
     */
    public function __construct(
        public array $roles,
        public array $userIds = [],
    ) {
    }
}
