<?php

declare(strict_types=1);

namespace App\Service\Compliance;

use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Exception\BusinessRule\BusinessRuleException;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * S18 — the one place that reads and writes an organisation's Reifegrad (Ist / Soll) of a
 * requirement.
 *
 *  - A requirement the organisation uploaded itself (TISAX/VDA-ISA workbook, `uploadTenant`)
 *    keeps its Reifegrad on the row: nobody else sees that row.
 *  - A global catalogue requirement (MRIS MHC, ISO, NIS-2, … industry baselines) is shared by
 *    every organisation, so the organisation's Ist / Soll lives on its own fulfilment row. The
 *    requirement's `maturityTarget` is only the catalogue default (set by catalogue loaders),
 *    used while the organisation has not chosen a target of its own.
 *
 * Writing into another organisation's uploaded row is refused. Callers flush.
 */
final class RequirementMaturity implements ResetInterface
{
    /** @var array<string, ComplianceRequirementFulfillment> rows written in this unit of work */
    private array $written = [];

    public function __construct(
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function current(ComplianceRequirement $requirement, Tenant $tenant): ?string
    {
        if ($this->ownsRow($requirement, $tenant)) {
            return $requirement->getMaturityCurrent();
        }
        if (!$this->isGlobal($requirement)) {
            return null;
        }

        return $this->fulfillment($requirement, $tenant)?->getMaturityCurrent();
    }

    public function target(ComplianceRequirement $requirement, Tenant $tenant): ?string
    {
        if ($this->ownsRow($requirement, $tenant)) {
            return $requirement->getMaturityTarget();
        }
        if (!$this->isGlobal($requirement)) {
            return null;
        }

        return $this->fulfillment($requirement, $tenant)?->getMaturityTarget() ?? $requirement->getMaturityTarget();
    }

    public function reviewedAt(ComplianceRequirement $requirement, Tenant $tenant): ?\DateTimeInterface
    {
        if ($this->ownsRow($requirement, $tenant)) {
            return $requirement->getMaturityReviewedAt();
        }
        if (!$this->isGlobal($requirement)) {
            return null;
        }

        return $this->fulfillment($requirement, $tenant)?->getMaturityReviewedAt();
    }

    public function setCurrent(ComplianceRequirement $requirement, Tenant $tenant, ?string $stage): void
    {
        $now = new DateTimeImmutable();
        if ($this->ownsRow($requirement, $tenant)) {
            $requirement->setMaturityCurrent($stage)->setMaturityReviewedAt($now);
            return;
        }
        $this->writableFulfillment($requirement, $tenant)
            ->setMaturityCurrent($stage)
            ->setMaturityReviewedAt($now);
    }

    public function setTarget(ComplianceRequirement $requirement, Tenant $tenant, ?string $stage): void
    {
        if ($this->ownsRow($requirement, $tenant)) {
            $requirement->setMaturityTarget($stage);
            return;
        }
        $this->writableFulfillment($requirement, $tenant)->setMaturityTarget($stage);
    }

    /**
     * Catalogue change (new MRIS version reworded an MHC): every organisation's Ist no longer
     * fits. Returns how many organisations had one.
     */
    public function clearCurrentEverywhere(ComplianceRequirement $requirement): int
    {
        $cleared = 0;
        if ($requirement->getMaturityCurrent() !== null) {
            $requirement->setMaturityCurrent(null);
            $cleared++;
        }
        foreach ($this->fulfillmentRepository->findBy(['requirement' => $requirement]) as $fulfillment) {
            if ($fulfillment->getMaturityCurrent() !== null) {
                $fulfillment->setMaturityCurrent(null);
                $cleared++;
            }
        }

        return $cleared;
    }

    private function ownsRow(ComplianceRequirement $requirement, Tenant $tenant): bool
    {
        $owner = $requirement->getUploadTenant();

        return $owner !== null
            && ($owner === $tenant || ($owner->getId() !== null && $owner->getId() === $tenant->getId()));
    }

    private function isGlobal(ComplianceRequirement $requirement): bool
    {
        return $requirement->getUploadTenant() === null;
    }

    private function fulfillment(ComplianceRequirement $requirement, Tenant $tenant): ?ComplianceRequirementFulfillment
    {
        $key = self::key($requirement, $tenant);
        if (isset($this->written[$key])) {
            return $this->written[$key];
        }
        if ($requirement->getId() === null || $tenant->getId() === null) {
            return null;
        }

        return $this->fulfillmentRepository->findOneBy(['tenant' => $tenant, 'requirement' => $requirement]);
    }

    private function writableFulfillment(ComplianceRequirement $requirement, Tenant $tenant): ComplianceRequirementFulfillment
    {
        if (!$this->isGlobal($requirement)) {
            throw new BusinessRuleException('Die Anforderung gehört einer anderen Organisation.', 'requirement');
        }
        $key = self::key($requirement, $tenant);
        $fulfillment = $this->written[$key]
            ?? $this->fulfillmentRepository->findOrCreateForTenantAndRequirement($tenant, $requirement);
        if ($fulfillment->getId() === null) {
            $this->entityManager->persist($fulfillment);
        }

        return $this->written[$key] = $fulfillment;
    }

    public function reset(): void
    {
        $this->written = [];
    }

    private static function key(ComplianceRequirement $requirement, Tenant $tenant): string
    {
        return spl_object_id($requirement) . ':' . spl_object_id($tenant);
    }
}
