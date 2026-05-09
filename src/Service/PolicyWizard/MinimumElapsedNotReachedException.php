<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\User;
use RuntimeException;

/**
 * W1 audit-defang gap #2 — thrown by
 * {@see GenerationApprovalElapsedGuard::assertMinimumElapsed} when an
 * approval attempt arrives before the human-plausible review window
 * has elapsed since the Document was generated.
 *
 * Per the External-Auditor persona-review (`docs/plans/policy-wizard/
 * persona-reviews/06-external-auditor-review.md` lines 175-178) "the
 * wizard generates 30 docs in 4 minutes, the human approves them in
 * 6 minutes — that's not review, that's clicking". The exception
 * surfaces the offending document + remaining seconds so the UI can
 * render an actionable "you have to actually read this" prompt.
 */
final class MinimumElapsedNotReachedException extends RuntimeException
{
    public function __construct(
        public readonly Document $document,
        public readonly User $approver,
        public readonly int $minimumRequiredSeconds,
        public readonly int $elapsedSeconds,
    ) {
        parent::__construct(sprintf(
            'Minimum review window not reached for Document #%d: %d s elapsed, %d s required (gap %d s).',
            $document->getId() ?? 0,
            $elapsedSeconds,
            $minimumRequiredSeconds,
            max(0, $minimumRequiredSeconds - $elapsedSeconds),
        ));
    }

    /**
     * Convenience accessor for callers/UI: how many more seconds the
     * caller must wait before the approval will succeed.
     */
    public function getRemainingSeconds(): int
    {
        return max(0, $this->minimumRequiredSeconds - $this->elapsedSeconds);
    }
}
