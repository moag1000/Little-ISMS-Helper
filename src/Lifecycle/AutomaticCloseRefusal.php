<?php

declare(strict_types=1);

namespace App\Lifecycle;

use App\Lifecycle\Exception\FourEyesRequiredException;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Symfony\Component\Workflow\TransitionBlocker;

/**
 * Decides whether an automatic (cron / auto-progression) attempt to close a
 * workflow was refused for a reason that means "a human has to approve
 * this" — the expected waiting state, reported as a notice, never as an
 * error:
 *  - FourEyesValidator refused it ({@see FourEyesRequiredException}), or
 *  - a lifecycle guard blocked it (e.g. TenantGuard: no tenant context
 *    outside a request) — a NotEnabledTransitionException whose blockers are
 *    all guard blockers, none "not enabled from the current marking".
 *
 * A transition that the marking does not allow, and any other failure, stays
 * an error.
 */
final class AutomaticCloseRefusal
{
    public static function isRefusal(\Throwable $e): bool
    {
        if ($e instanceof FourEyesRequiredException) {
            return true;
        }
        if (!$e instanceof InvalidTransitionException) {
            return false;
        }
        $notEnabled = $e->getPrevious();
        if (!$notEnabled instanceof NotEnabledTransitionException) {
            return false;
        }

        $blockers = iterator_to_array($notEnabled->getTransitionBlockerList(), false);
        foreach ($blockers as $blocker) {
            if ($blocker->getCode() === TransitionBlocker::BLOCKED_BY_MARKING) {
                return false;
            }
        }

        return $blockers !== [];
    }

    /**
     * Human-readable cause of a refusal for logs / the cron notice:
     * "four-eyes" or "blocked: <guard message(s)>".
     */
    public static function describe(\Throwable $e): string
    {
        $notEnabled = $e->getPrevious();
        if (!$notEnabled instanceof NotEnabledTransitionException) {
            return 'four-eyes';
        }
        $messages = [];
        foreach ($notEnabled->getTransitionBlockerList() as $blocker) {
            $messages[] = $blocker->getMessage();
        }

        return 'blocked: ' . implode('; ', $messages);
    }
}
