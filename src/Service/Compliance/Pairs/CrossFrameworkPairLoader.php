<?php

declare(strict_types=1);

namespace App\Service\Compliance\Pairs;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\Tenant;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;

/**
 * Loads what every framework pair needs in a fixed number of queries (S31): the compliance exports looped
 * frameworks × frameworks with ~6 queries per pair (~600 pairs for 25 frameworks).
 */
final readonly class CrossFrameworkPairLoader
{
    public function __construct(
        private ComplianceMappingRepository $mappings,
        private ComplianceRequirementRepository $requirements,
        private ComplianceRequirementFulfillmentRepository $fulfillments,
    ) {
    }

    /** @param list<ComplianceFramework> $frameworks */
    public function load(array $frameworks, ?Tenant $tenant): CrossFrameworkPairSet
    {
        $bulk = $this->mappings->findAllCrossFrameworkMappingsBulk($frameworks);

        $counts = [];
        foreach ($frameworks as $framework) {
            $counts[(int) $framework->getId()] = $this->requirements->countTopLevelByFramework($framework);
        }

        $sources = [];
        array_walk_recursive($bulk, static function (ComplianceMapping $mapping) use (&$sources): void {
            if ($mapping->getSourceRequirement() !== null) {
                $sources[] = $mapping->getSourceRequirement();
            }
        });
        $percentages = $tenant instanceof Tenant ? $this->fulfillments->percentagesFor($tenant, $sources) : [];

        return new CrossFrameworkPairSet($bulk, $counts, $percentages);
    }
}
