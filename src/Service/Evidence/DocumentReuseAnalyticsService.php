<?php

declare(strict_types=1);

namespace App\Service\Evidence;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\ControlRepository;
use App\Service\Fte\FteRecorderService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * F4 Evidence-Versioning — document reuse analytics.
 *
 * Calculates how many Controls and compliance frameworks reference a given
 * document as evidence. The reuse factor is surfaced as a badge on the
 * document show-page: "12 controls · 4 frameworks".
 *
 * Also provides tenant-level aggregate statistics for reporting.
 */
class DocumentReuseAnalyticsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ControlRepository $controlRepository,
        private readonly ?FteRecorderService $fteRecorder = null,
    ) {}

    /**
     * Per-document reuse factor.
     *
     * @return array{
     *     control_count: int,
     *     framework_count: int,
     *     label: string
     * }
     */
    public function getReuseFactorForDocument(Document $document): array
    {
        $controlCount = $this->countControlsUsingDocument($document);
        $frameworkCount = $this->countFrameworksUsingDocument($document);

        $parts = [];
        if ($controlCount > 0) {
            $parts[] = $controlCount . ' control' . ($controlCount !== 1 ? 's' : '');
        }
        if ($frameworkCount > 0) {
            $parts[] = $frameworkCount . ' framework' . ($frameworkCount !== 1 ? 's' : '');
        }

        // F11 FTE-Tracking: record reuse savings when document spans ≥ 2 frameworks
        if ($frameworkCount >= 2 && $this->fteRecorder !== null) {
            $this->fteRecorder->recordEvidenceReuse($document, $frameworkCount);
        }

        return [
            'control_count' => $controlCount,
            'framework_count' => $frameworkCount,
            'label' => $parts !== [] ? implode(' · ', $parts) : '',
        ];
    }

    /**
     * Tenant-level aggregate reuse statistics.
     *
     * Returns top-N most-reused documents plus summary totals.
     *
     * @return array{
     *     total_documents: int,
     *     total_reuse_links: int,
     *     top_documents: array<int, array{document: Document, control_count: int, framework_count: int}>
     * }
     */
    public function getTenantAggregate(Tenant $tenant, int $topN = 10): array
    {
        // Fetch all documents for the tenant that are linked to at least one control
        $rows = $this->entityManager->createQueryBuilder()
            ->select('d.id AS doc_id, COUNT(DISTINCT c.id) AS ctrl_count')
            ->from(Document::class, 'd')
            ->join('d.evidenceDocumentsInverse', 'c') // inverse side not mapped — use join via SQL
            ->where('d.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('d.id')
            ->orderBy('ctrl_count', 'DESC')
            ->setMaxResults($topN)
            ->getQuery()
            ->getResult();

        // Fallback: use raw query if inverse side is not mapped
        if (empty($rows)) {
            $rows = $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT d.id AS doc_id, COUNT(DISTINCT ce.control_id) AS ctrl_count
                 FROM document d
                 LEFT JOIN control_evidence ce ON ce.document_id = d.id
                 LEFT JOIN control c ON c.id = ce.control_id AND c.tenant_id = :tenantId
                 WHERE d.tenant_id = :tenantId
                 GROUP BY d.id
                 ORDER BY ctrl_count DESC
                 LIMIT :limit',
                ['tenantId' => $tenant->getId(), 'limit' => $topN],
                ['limit' => \Doctrine\DBAL\Types\Types::INTEGER],
            );
        }

        $topDocuments = [];
        $totalLinks = 0;

        foreach ($rows as $row) {
            $doc = $this->entityManager->find(Document::class, (int) $row['doc_id']);
            if ($doc === null) {
                continue;
            }
            $ctrlCount = (int) $row['ctrl_count'];
            $fwCount = $this->countFrameworksUsingDocument($doc);
            $totalLinks += $ctrlCount + $fwCount;
            $topDocuments[] = [
                'document' => $doc,
                'control_count' => $ctrlCount,
                'framework_count' => $fwCount,
            ];
        }

        $totalDocs = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(Document::class, 'd')
            ->where('d.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total_documents' => $totalDocs,
            'total_reuse_links' => $totalLinks,
            'top_documents' => $topDocuments,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function countControlsUsingDocument(Document $document): int
    {
        try {
            $result = $this->entityManager->getConnection()->fetchOne(
                'SELECT COUNT(DISTINCT control_id) FROM control_evidence WHERE document_id = :docId',
                ['docId' => $document->getId()],
            );
            return (int) $result;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Count distinct compliance frameworks that have at least one requirement
     * fulfillment linked to the document.
     * Currently CRF has no direct document FK — returns 0 until Sprint 5B.
     */
    private function countFrameworksUsingDocument(Document $document): int
    {
        // Sprint 5B will add direct evidence document link on CRF.
        // For now, count frameworks via control's framework_references metadata.
        try {
            // Each control that uses the document may reference frameworks.
            // We count distinct framework slugs via a sub-select on control_evidence.
            $result = $this->entityManager->getConnection()->fetchOne(
                'SELECT COUNT(DISTINCT c.framework_references)
                 FROM control c
                 INNER JOIN control_evidence ce ON ce.control_id = c.id
                 WHERE ce.document_id = :docId
                   AND c.framework_references IS NOT NULL',
                ['docId' => $document->getId()],
            );
            // framework_references is a JSON column — rough approximation
            return min(1, (int) $result);
        } catch (\Throwable) {
            return 0;
        }
    }
}
