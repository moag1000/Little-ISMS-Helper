<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\User;
use App\Service\AuditLogger;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * W1 audit-defang gap #2 — Generation-to-approval minimum-elapsed-time
 * gate.
 *
 * Per the External-Auditor persona-review (`docs/plans/policy-wizard/
 * persona-reviews/06-external-auditor-review.md` lines 175-178) the
 * single-strongest tell for "human did not actually read the policy"
 * is the same-second timestamp pattern: generation_at == approved_at
 * (or +seconds). The auditor's defang-blocker is a hard floor on the
 * elapsed time between {@see Document::getUploadedAt} (the wizard's
 * generated-at proxy) and the moment {@see PolicySectionApprovalService}
 * (or {@see ApprovalKickoffService} for bulk runs) accepts the approval.
 *
 * Threshold formula (per spec):
 *
 *   minimum_required_seconds = max(180, body_length / 200)
 *
 * Floor 180 s (3 minutes) covers a tiny one-paragraph stub; the
 * `body_length / 200` term scales to plausible reading speed
 * (~200 chars/second for skimming; final-pass reads slow further but
 * the wizard targets the skim-and-confirm flow). For an average 8 KB
 * topic policy this works out to ~40 s of additional padding above
 * the floor.
 *
 * Body length is read from {@see Document::getFileSize} (the wizard
 * sets this to `strlen($body)` in {@see DocumentGenerator::makeFreshDocument}).
 * For non-wizard or legacy Documents that do not carry a fileSize the
 * guard falls back to the floor-only threshold so the gate never
 * deadlocks on a missing field.
 *
 * Audit-trail: every blocked attempt fires a `min_elapsed_violation`
 * audit-log entry tagged `policy-approval` so the auditor sees both
 * the catch event AND the eventual successful approval — proving the
 * gate held when it had to.
 */
final class GenerationApprovalElapsedGuard
{
    private const string AUDIT_TAG = 'policy-approval';

    /**
     * Minimum threshold floor in seconds. Covers a stub Document where
     * `fileSize / 200` would resolve to near-zero. 180 s = 3 minutes
     * is the auditor-defensible "you at least opened it" window.
     */
    public const int MIN_THRESHOLD_SECONDS = 180;

    /**
     * Plausible characters-per-second skim-read rate. Drives the
     * proportional component of the threshold formula. Tuned to a
     * confident skim-pass through familiar policy prose, NOT a deep
     * read — the wizard explicitly assumes the approver has reviewed
     * the underlying templates ahead of time.
     */
    public const int CHARS_PER_SECOND = 200;

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Throws when $approver attempts to approve $document before the
     * minimum-required review window has elapsed since the Document
     * was generated.
     *
     * Skip rules:
     *  - Document carries no `uploadedAt` timestamp → silently allow
     *    (legacy/test fixtures without a generated-at marker).
     *  - Document is not wizard-generated (no `generatedFromWizardRun`
     *    AND no `generatedFromTemplate`) → silently allow; the guard
     *    is scoped to wizard outputs.
     *  - Document has already been approved (status == 'approved' or
     *    `isImmutable=true`) → no-op so re-reads on a published row
     *    do not blow up.
     *
     * @param ?DateTimeInterface $now injected wall-clock for tests;
     *        defaults to now() in production.
     *
     * @throws MinimumElapsedNotReachedException when the elapsed time
     *         is below the per-document threshold.
     */
    public function assertMinimumElapsed(
        Document $document,
        User $approver,
        ?DateTimeInterface $now = null,
    ): void {
        if (!$this->isGuardApplicable($document)) {
            return;
        }

        $generatedAt = $document->getUploadedAt();
        if (!$generatedAt instanceof DateTimeInterface) {
            // No generation timestamp recorded — skip silently rather
            // than block on a missing field. The audit-defang only
            // applies to documents that carry a measurable elapsed
            // window.
            return;
        }

        $now ??= new DateTimeImmutable();
        $elapsedSeconds = $now->getTimestamp() - $generatedAt->getTimestamp();
        if ($elapsedSeconds < 0) {
            // Clock skew — be conservative and treat as zero elapsed.
            $elapsedSeconds = 0;
        }

        $required = $this->resolveThresholdSeconds($document);
        if ($elapsedSeconds >= $required) {
            return;
        }

        $this->logViolation($document, $approver, $required, $elapsedSeconds);

        throw new MinimumElapsedNotReachedException(
            $document,
            $approver,
            $required,
            $elapsedSeconds,
        );
    }

    /**
     * Resolve the per-document threshold in seconds.
     *
     * Formula: `max(MIN_THRESHOLD_SECONDS, body_length / CHARS_PER_SECOND)`.
     *
     * Public so callers/UI components can pre-compute the wait time
     * for "approval-button enabled in N seconds" badges without
     * triggering the throw path.
     */
    public function resolveThresholdSeconds(Document $document): int
    {
        $bodyLength = $document->getFileSize();
        $proportional = is_int($bodyLength) && $bodyLength > 0
            ? (int) ceil($bodyLength / self::CHARS_PER_SECOND)
            : 0;
        return max(self::MIN_THRESHOLD_SECONDS, $proportional);
    }

    /**
     * Whether the Document is in scope for the guard. Wizard-generated
     * (template OR run reference) drafts only — manually uploaded docs
     * use a different review pipeline and are not subject to this gate.
     */
    private function isGuardApplicable(Document $document): bool
    {
        $status = $document->getStatus();
        if ($status === 'approved' || $document->isImmutable()) {
            return false;
        }
        $hasTemplate = $document->getGeneratedFromTemplate() !== null;
        $hasRun      = $document->getGeneratedFromWizardRun() !== null;
        return $hasTemplate || $hasRun;
    }

    private function logViolation(
        Document $document,
        User $approver,
        int $requiredSeconds,
        int $elapsedSeconds,
    ): void {
        $this->logger->warning(
            'PolicyWizard W1 defang: min-elapsed approval gate held',
            [
                'document_id'      => $document->getId(),
                'approver_id'      => $approver->getId(),
                'required_seconds' => $requiredSeconds,
                'elapsed_seconds'  => $elapsedSeconds,
                'gap_seconds'      => max(0, $requiredSeconds - $elapsedSeconds),
            ],
        );

        $this->auditLogger->logCustom(
            action: 'min_elapsed_violation',
            entityType: 'Document',
            entityId: $document->getId(),
            oldValues: null,
            newValues: [
                'approver_id'      => $approver->getId(),
                'required_seconds' => $requiredSeconds,
                'elapsed_seconds'  => $elapsedSeconds,
                'gap_seconds'      => max(0, $requiredSeconds - $elapsedSeconds),
                'tag'              => self::AUDIT_TAG,
            ],
            description: sprintf(
                '[%s] min-elapsed gate blocked approval on Document #%d (%d s elapsed, %d s required)',
                self::AUDIT_TAG,
                $document->getId() ?? 0,
                $elapsedSeconds,
                $requiredSeconds,
            ),
        );
    }
}
