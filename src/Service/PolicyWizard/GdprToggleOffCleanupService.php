<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\DocumentStatus;
use App\Lifecycle\LifecycleTransitionInterface;
use App\Repository\DocumentRepository;
use App\Repository\DocumentSectionRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * GDPR-toggle off-cleanup behaviour.
 *
 * Spec: `docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md`
 * (Compliance-Manager "Open questions" #2).
 *
 * When a tenant flips the org-level setting `org.is_gdpr_subject` from
 * `true` to `false` AFTER having already generated GDPR documents, the
 * tenant ends up with stale Datenschutz-Artefakte in their document
 * register that:
 *   - mislead auditors (still listed as "active" but no longer in scope)
 *   - confuse the DPO inbox (sign-off prompts on retired docs)
 *   - block the SoA from being re-issued without GDPR-anchors
 *
 * This service performs an inventoried, reversible cleanup:
 *   - **Documents** with `category='gdpr'` (or generated from a template
 *     where `standard='gdpr'`) are archived via the document_lifecycle
 *     transition `archive_out_of_scope` (`isArchived=true`). NOT deleted —
 *     the rows remain as historical evidence.
 *   - **DocumentSections** belonging to GDPR templates / sectionKeys
 *     starting with `gdpr_` get their `editLocked` flag set true and
 *     a cleanup notice appended to `contentSnapshot`.
 *   - **AuditLogger** writes a `gdpr_toggled_off_cleanup` event with the
 *     full inventory so the operator (and the auditor) can reconstruct
 *     the cleanup later.
 *
 * Idempotency: the second invocation is a safe no-op — every step
 * checks the current state before mutating.
 */
final class GdprToggleOffCleanupService
{
    public const string AUDIT_ACTION = 'gdpr_toggled_off_cleanup';
    private const string AUDIT_TAG = 'policy-wizard-gdpr-cleanup';
    private const string GDPR_STANDARD = 'gdpr';
    private const string CLEANUP_NOTICE_MARKER = '[GDPR-cleanup]';
    private const string ARCHIVE_REASON = 'GDPR out of scope: organisation setting org.is_gdpr_subject switched to false';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentRepository $documentRepository,
        private readonly DocumentSectionRepository $sectionRepository,
        private readonly AuditLogger $auditLogger,
        private readonly LifecycleTransitionInterface $lifecycle,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Archive GDPR Documents + lock GDPR DocumentSections + emit audit
     * trail. Returns the inventory of affected entities.
     *
     * @return array{
     *   tenant_id: int|null,
     *   archived_documents: list<int>,
     *   already_archived_documents: list<int>,
     *   locked_sections: list<int>,
     *   already_locked_sections: list<int>,
     * }
     */
    public function cleanupAfterGdprToggleOff(Tenant $tenant, ?User $actor = null): array
    {
        $report = [
            'tenant_id' => $tenant->getId(),
            'archived_documents' => [],
            'already_archived_documents' => [],
            'locked_sections' => [],
            'already_locked_sections' => [],
        ];

        // ── 1. Archive GDPR Documents ──────────────────────────────────
        $documents = $this->fetchGdprDocuments($tenant);
        foreach ($documents as $document) {
            $id = $document->getId();
            if ($id === null) {
                continue;
            }
            if ($document->isArchived() || $document->getStatus() === DocumentStatus::Archived->value) {
                $report['already_archived_documents'][] = $id;
                continue;
            }
            if ($document->getStatus() === DocumentStatus::Deleted->value) {
                // Soft-deleted rows are already out of the register.
                continue;
            }
            $document->setIsArchived(true);
            // archive_out_of_scope is allowed from every live place
            // (draft … published) and writes the status_change audit row.
            $this->lifecycle->transition(
                $document,
                'document_lifecycle',
                'archive_out_of_scope',
                $actor,
                self::ARCHIVE_REASON,
            );
            $report['archived_documents'][] = $id;
        }

        // ── 2. Lock GDPR DocumentSections + append cleanup notice ─────
        $sections = $this->fetchGdprSections($tenant);
        foreach ($sections as $section) {
            $id = $section->getId();
            if ($id === null) {
                continue;
            }
            if ($section->isEditLocked() && $this->snapshotHasMarker($section)) {
                $report['already_locked_sections'][] = $id;
                continue;
            }
            $section->setEditLocked(true);
            if (!$this->snapshotHasMarker($section)) {
                $existing = $section->getContentSnapshot() ?? '';
                $notice = sprintf(
                    "\n\n%s Tenant flipped org.is_gdpr_subject=false; section retained as historical evidence and locked from further edits.",
                    self::CLEANUP_NOTICE_MARKER,
                );
                $section->setContentSnapshot($existing . $notice);
            }
            $this->entityManager->persist($section);
            $report['locked_sections'][] = $id;
        }

        $this->entityManager->flush();

        // ── 3. Audit trail with full inventory ─────────────────────────
        $this->auditLogger->logCustom(
            action: self::AUDIT_ACTION,
            entityType: 'Tenant',
            entityId: $tenant->getId(),
            oldValues: null,
            newValues: [
                'inventory' => $report,
                'tag' => self::AUDIT_TAG,
            ],
            description: sprintf(
                '[%s] Tenant #%d flipped org.is_gdpr_subject=false → archived %d Documents, locked %d Sections',
                self::AUDIT_TAG,
                $tenant->getId() ?? 0,
                count($report['archived_documents']),
                count($report['locked_sections']),
            ),
        );

        return $report;
    }

    /**
     * @return list<Document>
     */
    private function fetchGdprDocuments(Tenant $tenant): array
    {
        try {
            /** @var list<Document> $rows */
            $rows = $this->documentRepository->createQueryBuilder('d')
                ->leftJoin('d.generatedFromTemplate', 't')
                ->where('d.tenant = :tenant')
                ->andWhere('d.category = :gdprCategory OR t.standard = :gdprStandard')
                ->setParameter('tenant', $tenant)
                ->setParameter('gdprCategory', self::GDPR_STANDARD)
                ->setParameter('gdprStandard', self::GDPR_STANDARD)
                ->getQuery()
                ->getResult();
            return $rows;
        } catch (\Throwable $error) {
            $this->logger->warning(
                'GdprToggleOffCleanupService: GDPR document fetch failed',
                ['tenant_id' => $tenant->getId(), 'error' => $error->getMessage()],
            );
            return [];
        }
    }

    /**
     * @return list<DocumentSection>
     */
    private function fetchGdprSections(Tenant $tenant): array
    {
        try {
            /** @var list<DocumentSection> $rows */
            $rows = $this->sectionRepository->createQueryBuilder('s')
                ->where('s.tenant = :tenant')
                ->andWhere('s.sectionKey LIKE :gdprPrefix')
                ->setParameter('tenant', $tenant)
                ->setParameter('gdprPrefix', 'gdpr_%')
                ->getQuery()
                ->getResult();
            return $rows;
        } catch (\Throwable $error) {
            $this->logger->warning(
                'GdprToggleOffCleanupService: GDPR section fetch failed',
                ['tenant_id' => $tenant->getId(), 'error' => $error->getMessage()],
            );
            return [];
        }
    }

    private function snapshotHasMarker(DocumentSection $section): bool
    {
        $snapshot = $section->getContentSnapshot();
        return $snapshot !== null && str_contains($snapshot, self::CLEANUP_NOTICE_MARKER);
    }
}
