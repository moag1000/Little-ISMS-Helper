<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\AuditChecklist;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\InternalAudit;
use App\Entity\Tenant;
use App\Repository\AuditChecklistRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Compliance\Fulfillment\RequirementEvidence;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The audit checklist from the audit's scope (ISO 27001 9.2.2: criteria and
 * scope of each audit): one item per requirement of every framework in scope
 * — ISO 27001 for a full-ISMS audit without framework — with the
 * organisation's evidence behind the requirement already listed, so the
 * auditor checks instead of searching.
 *
 * Idempotent: requirements that already have an item are skipped, nothing is
 * removed. The auditor's verdict then feeds the requirement's fulfilment
 * ({@see \App\Service\Compliance\Fulfillment\AuditResultRule}).
 */
final class AuditChecklistGenerator
{
    private const string DEFAULT_FRAMEWORK = 'ISO27001';
    private const int EVIDENCE_TITLES = 10;

    public function __construct(
        private readonly AuditChecklistRepository $checklists,
        private readonly ComplianceFrameworkRepository $frameworks,
        private readonly ComplianceRequirementRepository $requirements,
        private readonly RequirementEvidence $evidence,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Frameworks the checklist covers.
     *
     * @return list<ComplianceFramework>
     */
    public function frameworksFor(InternalAudit $audit): array
    {
        $frameworks = $audit->getAllScopedFrameworks();
        if ($frameworks === []) {
            $iso = $this->frameworks->findOneBy(['code' => self::DEFAULT_FRAMEWORK]);
            if ($iso instanceof ComplianceFramework) {
                $frameworks[] = $iso;
            }
        }

        return $frameworks;
    }

    /**
     * Adds the missing items and flushes.
     *
     * @return int number of items added
     */
    public function generate(InternalAudit $audit): int
    {
        $tenant = $audit->getTenant();
        if (!$tenant instanceof Tenant) {
            return 0;
        }

        $existing = [];
        foreach ($this->checklists->findByAudit($audit) as $item) {
            $existing[(int) $item->getRequirement()?->getId()] = true;
        }

        $added = 0;
        foreach ($this->frameworksFor($audit) as $framework) {
            foreach ($this->requirements->findInScopeForFrameworkListing($framework, $tenant) as $requirement) {
                if (!$requirement instanceof ComplianceRequirement || isset($existing[(int) $requirement->getId()])) {
                    continue;
                }
                $existing[(int) $requirement->getId()] = true;
                $this->entityManager->persist(
                    (new AuditChecklist())
                        ->setTenant($tenant)
                        ->setAudit($audit)
                        ->setRequirement($requirement)
                        ->setEvidenceFound($this->evidenceSummary($requirement, $tenant)),
                );
                $added++;
            }
        }

        if ($added > 0) {
            $this->entityManager->flush();
        }

        return $added;
    }

    /** Titles of the organisation's evidence behind the requirement, one per line. */
    private function evidenceSummary(ComplianceRequirement $requirement, Tenant $tenant): ?string
    {
        $titles = [];
        foreach ($this->evidence->documentsFor($requirement, $tenant) as $document) {
            $titles[] = '• ' . (string) ($document->getOriginalFilename() ?? $document->getFilename() ?? '#' . $document->getId());
            if (count($titles) >= self::EVIDENCE_TITLES) {
                break;
            }
        }

        return $titles === [] ? null : implode("\n", $titles);
    }
}
