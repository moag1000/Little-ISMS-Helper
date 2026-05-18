<?php

declare(strict_types=1);

namespace App\Lifecycle\Exception;

/**
 * Thrown by `FourEyesValidator` when a lifecycle transition declared
 * `four_eyes: true` is invoked without a valid second-approver.
 *
 * Reasons (one of):
 *   - context.four_eyes_approver is null (no second approver supplied)
 *   - context.four_eyes_approver === context.user (same person)
 *   - approver does not carry any of the YAML `roles` for this transition
 *
 * Caught by LifecycleController + translated to HTTP 422 with
 * error-code `four_eyes_required` so the UI can show a picker.
 *
 * ISO 27001 Cl. 5.3 (Segregation of Duties) + Cl. 7.5.3 (audit-trail
 * integrity) require two-person rule on destructive / irreversible
 * transitions (dispose, publish, close, approve, accept).
 */
class FourEyesRequiredException extends \RuntimeException
{
    public function __construct(
        public readonly string $workflowName,
        public readonly string $transitionName,
        public readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function missing(string $workflowName, string $transitionName): self
    {
        return new self(
            $workflowName,
            $transitionName,
            'missing_approver',
            sprintf(
                'Four-eyes approval required for transition "%s" in workflow "%s" — no second approver supplied.',
                $transitionName,
                $workflowName,
            ),
        );
    }

    public static function sameUser(string $workflowName, string $transitionName): self
    {
        return new self(
            $workflowName,
            $transitionName,
            'same_user',
            sprintf(
                'Four-eyes approval requires a DIFFERENT user — requester and approver are the same person (transition "%s", workflow "%s").',
                $transitionName,
                $workflowName,
            ),
        );
    }

    public static function approverLacksRole(string $workflowName, string $transitionName, array $requiredRoles): self
    {
        return new self(
            $workflowName,
            $transitionName,
            'approver_lacks_role',
            sprintf(
                'Four-eyes approver does not carry any of the required roles [%s] for transition "%s" in workflow "%s".',
                implode(', ', $requiredRoles),
                $transitionName,
                $workflowName,
            ),
        );
    }
}
