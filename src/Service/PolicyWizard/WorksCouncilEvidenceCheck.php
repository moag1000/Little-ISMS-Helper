<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Repository\DocumentRepository;
use App\Repository\PolicyTemplateRepository;

/**
 * W5 Gap-C — Works-Council BR-evidence inventory helper.
 *
 * For every published Document generated from a PolicyTemplate marked
 * `requires_works_council_evidence=true`, confirms that the tenant
 * uploaded a corresponding Betriebsrats-Beteiligungsnachweis (BR-evidence
 * attachment). The attachment is recognised when its `category` is
 * `works_council_evidence` AND it is linked to the host policy
 * Document via the Document entityType/entityId fields.
 *
 * Spec: `docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md`
 * line 261-262 — Auditor "Auditor-specific gaps" Works-Council.
 *
 * Returns a structured gap report so callers (Compliance-Wizard check,
 * Alva-Hint, audit-pack export) can re-use the inventory result.
 *
 * @phpstan-type GapItem array{
 *   document_id: int|null,
 *   topic: string|null,
 *   filename: string|null,
 * }
 *
 * @phpstan-type GapReport array{
 *   evaluated_documents: int,
 *   covered: int,
 *   missing: list<GapItem>,
 *   tenant_id: int|null,
 * }
 */
class WorksCouncilEvidenceCheck
{
    public const string EVIDENCE_CATEGORY = 'works_council_evidence';

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly PolicyTemplateRepository $templateRepository,
    ) {
    }

    /**
     * Inspect all published Documents whose source PolicyTemplate
     * requires works-council evidence. Returns the inventory report
     * (covered + missing) for the given tenant.
     *
     * @return GapReport
     */
    public function inspect(?Tenant $tenant): array
    {
        if ($tenant === null) {
            return [
                'evaluated_documents' => 0,
                'covered' => 0,
                'missing' => [],
                'tenant_id' => null,
            ];
        }

        /** @var list<PolicyTemplate> $relevantTemplates */
        $relevantTemplates = $this->templateRepository->findBy([
            'requiresWorksCouncilEvidence' => true,
        ]);
        if ($relevantTemplates === []) {
            return [
                'evaluated_documents' => 0,
                'covered' => 0,
                'missing' => [],
                'tenant_id' => $tenant->getId(),
            ];
        }

        $evaluated = 0;
        $covered = 0;
        $missing = [];

        foreach ($relevantTemplates as $template) {
            /** @var list<Document> $hostDocuments */
            $hostDocuments = $this->documentRepository->createQueryBuilder('d')
                ->where('d.tenant = :tenant')
                ->andWhere('d.generatedFromTemplate = :template')
                ->andWhere('d.status IN (:statuses)')
                ->andWhere('d.isArchived = false')
                ->setParameter('tenant', $tenant)
                ->setParameter('template', $template)
                ->setParameter('statuses', ['published', 'approved'])
                ->getQuery()
                ->getResult();

            foreach ($hostDocuments as $hostDocument) {
                $evaluated++;
                if ($this->hasAttachedEvidence($tenant, $hostDocument)) {
                    $covered++;
                    continue;
                }
                $missing[] = [
                    'document_id' => $hostDocument->getId(),
                    'topic' => $template->getTopic(),
                    'filename' => $hostDocument->getOriginalFilename(),
                ];
            }
        }

        return [
            'evaluated_documents' => $evaluated,
            'covered' => $covered,
            'missing' => $missing,
            'tenant_id' => $tenant->getId(),
        ];
    }

    /**
     * An evidence attachment exists when at least one Document with
     * category `works_council_evidence` is linked to the host policy
     * via Document.entityType='Document' + entityId=hostId. Either the
     * attachment shares the host tenant or it carries no tenant (rare —
     * legacy uploads); both pass.
     */
    private function hasAttachedEvidence(Tenant $tenant, Document $hostDocument): bool
    {
        $hostId = $hostDocument->getId();
        if ($hostId === null) {
            return false;
        }

        $count = (int) $this->documentRepository->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.tenant = :tenant')
            ->andWhere('a.entityType = :entityType')
            ->andWhere('a.entityId = :entityId')
            ->andWhere('a.category = :category')
            ->andWhere('a.isArchived = false')
            ->setParameter('tenant', $tenant)
            ->setParameter('entityType', 'Document')
            ->setParameter('entityId', $hostId)
            ->setParameter('category', self::EVIDENCE_CATEGORY)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
