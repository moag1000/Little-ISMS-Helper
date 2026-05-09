<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Document;
use RuntimeException;

/**
 * W6 Gap-E — raised when a DPO Charter (or Privacy Policy) is offered to
 * the bulk-approval batch builder. GDPR Art. 38(3) requires the DPO
 * appointment to be approved standalone so the independence of the DPO
 * is positively documented.
 *
 * Spec: `docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md`
 * line 285 (Auditor "Open questions for Phase 4" #1, lines 291-293).
 */
final class DpoCharterBulkApprovalException extends RuntimeException
{
    public function __construct(
        public readonly Document $document,
        public readonly ?string $topic,
    ) {
        parent::__construct(sprintf(
            'Document #%d (topic="%s") cannot be added to a bulk-approval batch: '
            . 'DPO Charter / Privacy Policy must be approved standalone (GDPR Art. 38(3) DPO independence).',
            $document->getId() ?? 0,
            $topic ?? '?',
        ));
    }
}
