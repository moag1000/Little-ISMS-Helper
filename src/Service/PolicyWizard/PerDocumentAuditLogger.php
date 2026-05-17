<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Service\AuditLogger;

/**
 * PerDocumentAuditLogger — Phase 4-C / Sprint W3-C.
 *
 * Closes Phase 4-C ISB-review item §7-#1 from
 * `docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md`:
 *   "Per-document audit-log entries guaranteed in addition to batch
 *    reference."
 *
 * When a Top-Mgmt user fires a bulk-approval batch on N documents, a
 * single batch-level entry would lose the per-document detail an
 * external auditor expects (one row per evidence artefact). This
 * wrapper around {@see AuditLogger} writes BOTH:
 *
 *   - 1 batch entry (entityType=`WizardRun`, action=`bulk_approval`)
 *   - N per-document entries (entityType=`Document`, action=`approved`)
 *
 * All N per-doc rows reference the batch via the shared `batch_id`
 * field in `newValues`, so audit reconstruction is a simple JOIN on the
 * AuditLog JSON column.
 *
 * The `batch_id` is a deterministic, request-scoped UUID-v4 generated
 * on bulk approval and passed through to per-doc calls. For ad-hoc
 * single-document approvals (no batch), `batch_id` is null.
 */
final class PerDocumentAuditLogger
{
    private const string AUDIT_TAG = 'policy-bulk-approval';

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Logs a bulk approval. Writes 1 batch row + N per-document rows,
     * all correlated via the same `batch_id`.
     *
     * @param array<int, Document> $documents documents in the batch
     */
    public function logBulkApproval(
        WizardRun $run,
        array $documents,
        User $approver,
        string $rationale,
    ): void {
        $batchId = $this->generateBatchId();

        // 1. Batch-level entry first so per-doc rows can reference an
        // already-persisted parent if the audit-log timestamp ordering
        // matters downstream.
        $this->auditLogger->logCustom(
            action: 'bulk_approval',
            entityType: 'WizardRun',
            entityId: $run->getId(),
            oldValues: null,
            newValues: [
                'batch_id'      => $batchId,
                'document_count' => count($documents),
                'document_ids'  => array_map(
                    static fn(Document $d): ?int => $d->getId(),
                    $documents,
                ),
                'approver_id'   => $approver->getId(),
                'rationale'     => $rationale,
                'tag'           => self::AUDIT_TAG,
            ],
            description: sprintf(
                '[%s] Bulk approval of %d document(s) by user #%d (batch %s)',
                self::AUDIT_TAG,
                count($documents),
                $approver->getId() ?? 0,
                $batchId,
            ),
        );

        // 2. One row per document.
        foreach ($documents as $document) {
            $this->logPerDocApproval($document, $approver, $batchId, $rationale);
        }
    }

    /**
     * Single-document approval entry. Used standalone for ad-hoc
     * approvals (no batch) AND from {@see logBulkApproval()} per doc.
     *
     * `batch_id` is null for solo approvals; correlated UUID for the
     * bulk path.
     */
    public function logPerDocApproval(
        Document $document,
        User $approver,
        ?string $batchId = null,
        ?string $rationale = null,
    ): void {
        $values = [
            'document_id' => $document->getId(),
            'approver_id' => $approver->getId(),
            'tag'         => self::AUDIT_TAG,
        ];
        if ($batchId !== null) {
            $values['batch_id'] = $batchId;
        }
        if ($rationale !== null && $rationale !== '') {
            $values['rationale'] = $rationale;
        }

        $description = $batchId === null
            ? sprintf(
                '[%s] Document #%d approved by user #%d',
                self::AUDIT_TAG,
                $document->getId() ?? 0,
                $approver->getId() ?? 0,
            )
            : sprintf(
                '[%s] Document #%d approved as part of batch %s',
                self::AUDIT_TAG,
                $document->getId() ?? 0,
                $batchId,
            );

        $this->auditLogger->logCustom(
            action: 'approved',
            entityType: 'Document',
            entityId: $document->getId(),
            oldValues: null,
            newValues: $values,
            description: $description,
        );
    }

    /**
     * UUIDv4-style identifier — no external dep, just random_bytes().
     * Sufficient for correlating rows in the AuditLog JSON column.
     */
    private function generateBatchId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
