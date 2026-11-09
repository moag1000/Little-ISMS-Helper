<?php

declare(strict_types=1);

namespace App\Lifecycle\Exception;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * S21: the person who applies a lifecycle transition lacks the effective
 * roles of that transition (YAML + organisation override) and is not the
 * responsible person where the transition is open to them. Thrown by
 * {@see \App\Lifecycle\TransitionAccess} before anything is applied; as an
 * AccessDeniedException it answers 403 wherever it is not caught.
 *
 * The message is a translation key: controllers that flash `getMessage()` of a
 * caught RuntimeException show it translated (`|fa_flash`). The workflow, the
 * transition and the roles are on the exception for logs and JSON callers.
 */
final class TransitionAccessDeniedException extends AccessDeniedException
{
    public const string MESSAGE_KEY = 'lifecycle.errors.role_required';

    /**
     * @param list<string> $requiredRoles
     */
    public function __construct(
        public readonly string $workflowName,
        public readonly string $transitionName,
        public readonly array $requiredRoles,
    ) {
        parent::__construct(self::MESSAGE_KEY);
    }

    /** Text for logs: which step, which roles. */
    public function describe(): string
    {
        return sprintf(
            'Transition "%s" in workflow "%s" needs one of the roles %s.',
            $this->transitionName,
            $this->workflowName,
            $this->requiredRoles === [] ? '<responsible person>' : implode(', ', $this->requiredRoles),
        );
    }
}
