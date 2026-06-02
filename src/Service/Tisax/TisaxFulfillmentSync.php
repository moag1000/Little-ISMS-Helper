<?php

declare(strict_types=1);

namespace App\Service\Tisax;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bridges the TISAX maturity assessment onto the canonical
 * ComplianceRequirementFulfillment model, so a workbook import actually COUNTS:
 * catalogue coverage, conformity, SoA applicability and cross-framework
 * fulfilment-inheritance all read ComplianceRequirementFulfillment, NOT the
 * Reifegrad stored on the requirement. Without this sync an import set maturity
 * but had "no further effect" — the wizard showed 0/262 fulfilled.
 *
 * Mapping (VDA-ISA target Reifegrad = 3 / "established"):
 *   - level ≥ 3 (established+)  → 100 %, status 'implemented' (4/5 → 'verified')
 *   - level 1-2 (performed/managed) → 33/67 %, status 'in_progress'
 *   - level 0 (incomplete) / unrated → 0 %, status 'not_started'
 * Data-Protection "OK" already lands as maturityCurrent='established' via the
 * mapper, so it flows through the same path.
 */
final class TisaxFulfillmentSync
{
    /** @var array<string, int> maturity-level string → ordinal (reverse LEVEL_MAP). */
    private const LEVEL_ORDINAL = [
        'incomplete' => 0,
        'performed' => 1,
        'managed' => 2,
        'established' => 3,
        'predictable' => 4,
        'optimising' => 5,
    ];

    /** Categories that are NOT assessable catalogue controls. */
    private const NON_CATALOGUE = ['section', 'legacy_unmapped'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
    ) {}

    /**
     * Sync fulfilment for every assessed leaf requirement of $framework for $tenant.
     *
     * @return array{synced:int, covered:int}
     */
    public function sync(ComplianceFramework $framework, Tenant $tenant): array
    {
        $requirements = $this->requirementRepository->findBy(['framework' => $framework]);
        $synced = 0;
        $covered = 0;

        foreach ($requirements as $requirement) {
            $category = $requirement->getCategory();
            if ($category !== null && in_array($category, self::NON_CATALOGUE, true)) {
                continue; // sections / parked legacy ids are not assessable controls
            }
            $level = $this->levelOf($requirement);
            if ($level === null) {
                continue; // unrated — leave any existing fulfilment untouched
            }

            [$percentage, $status] = $this->mapLevel($level);
            $fulfillment = $this->fulfillmentRepository->findOrCreateForTenantAndRequirement($tenant, $requirement);
            $fulfillment->setApplicable(true);
            $fulfillment->setFulfillmentPercentage($percentage);
            $fulfillment->setStatus($status);
            $this->em->persist($fulfillment);

            $synced++;
            if ($status === 'implemented' || $status === 'verified') {
                $covered++;
            }
        }

        if ($synced > 0) {
            $this->em->flush();
        }

        return ['synced' => $synced, 'covered' => $covered];
    }

    /** Reverse-map the requirement's current maturity to an ordinal 0-5, or null. */
    private function levelOf(ComplianceRequirement $requirement): ?int
    {
        $maturity = $requirement->getMaturityCurrent();
        if ($maturity === null || $maturity === '') {
            return null;
        }
        return self::LEVEL_ORDINAL[$maturity] ?? null;
    }

    /**
     * @return array{0:int, 1:string} [percentage, status]
     *
     * NB: an imported Reifegrad is a SELF-DECLARED assessment (TISAX AL1). It is
     * therefore never promoted to status 'verified' — that label asserts
     * independent (AL2/AL3) verification, which an import cannot establish. RG ≥ 3
     * ("established"+, = the VDA-ISA target) → 'implemented' (self-declared);
     * RG 1-2 → 'in_progress'; RG 0/unrated → 'not_started'.
     */
    private function mapLevel(int $level): array
    {
        return match (true) {
            $level >= 3  => [100, 'implemented'],
            $level === 2 => [67, 'in_progress'],
            $level === 1 => [33, 'in_progress'],
            default      => [0, 'not_started'],
        };
    }
}
