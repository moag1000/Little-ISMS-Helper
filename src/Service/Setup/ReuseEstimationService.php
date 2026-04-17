<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\CompliancePolicyService;

/**
 * Reuse Estimation Service (WS-8 — "Was hast du schon?"-Setup-Step).
 *
 * Liefert für jedes neu hinzuzufügende Framework eine Schätzung, wie viele Anforderungen
 * bereits durch vorhandene Frameworks abgedeckt sein können (Mapping-basiert, WS-1).
 *
 * Heuristik:
 *   - `delta_requirements`  = Gesamtzahl Anforderungen des neuen Frameworks.
 *   - `estimated_reusable`  = einzigartige Target-Requirements (aus den bestehenden →
 *     neuen Cross-Framework-Mappings), deren `mappingPercentage` >= 50 ist.
 *   - `estimated_fte_days_saved` = reusable × 0.3 FTE-Tage (grobe Heuristik:
 *     ~2h pro Requirement Erst-Bewertung, abzüglich Review-Aufwand).
 *
 * Hinweis: Die 0.3-Tage-Schätzung basiert auf WS-1-Plan (4–8 min Erstbewertung vs.
 * 2–4 min Review). Sie ist bewusst konservativ und dokumentiert — keine Simulation,
 * sondern eine Orientierung für den Business-Case im Setup-Dialog.
 */
final class ReuseEstimationService
{
    public const MIN_MAPPING_PERCENTAGE_FOR_REUSE = 50;

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly CompliancePolicyService $policy,
    ) {
    }

    private function fteDaysPerRequirement(): float
    {
        return $this->policy->getFloat(CompliancePolicyService::KEY_REUSE_DAYS_PER_REQUIREMENT, 0.3);
    }

    /**
     * Estimate Reuse-Potential for a set of new frameworks based on already-certified ones.
     *
     * @param string[] $existingCodes Framework-Codes, die bereits zertifiziert sind.
     * @param string[] $newCodes      Framework-Codes, die neu hinzugefügt werden sollen.
     *
     * @return array<string, array{
     *     code: string,
     *     name: string,
     *     delta_requirements: int,
     *     estimated_reusable: int,
     *     estimated_fte_days_saved: float,
     *     reuse_percentage: float,
     *     sources: string[]
     * }>
     */
    public function estimate(array $existingCodes, array $newCodes): array
    {
        $existingCodes = array_values(array_unique(array_filter($existingCodes, 'is_string')));
        $newCodes      = array_values(array_unique(array_filter($newCodes, 'is_string')));

        if ($newCodes === []) {
            return [];
        }

        $existingFrameworks = $this->loadFrameworksByCode($existingCodes);
        $result = [];

        foreach ($newCodes as $newCode) {
            $newFramework = $this->frameworkRepository->findOneBy(['code' => $newCode]);

            if (!$newFramework instanceof ComplianceFramework) {
                // Framework not yet loaded into the DB — we cannot derive mappings.
                $result[$newCode] = [
                    'code' => $newCode,
                    'name' => $newCode,
                    'delta_requirements' => 0,
                    'estimated_reusable' => 0,
                    'estimated_fte_days_saved' => 0.0,
                    'reuse_percentage' => 0.0,
                    'sources' => [],
                ];
                continue;
            }

            $totalRequirements = count($this->requirementRepository->findByFramework($newFramework));

            $reusableTargets = [];
            $sourcesContributing = [];

            foreach ($existingFrameworks as $existingFramework) {
                if ($existingFramework->getId() === $newFramework->getId()) {
                    continue;
                }

                $mappings = $this->mappingRepository->findCrossFrameworkMappings(
                    $existingFramework,
                    $newFramework,
                );

                $contributed = false;

                foreach ($mappings as $mapping) {
                    if ($mapping->getMappingPercentage() < self::MIN_MAPPING_PERCENTAGE_FOR_REUSE) {
                        continue;
                    }

                    $targetReq = $mapping->getTargetRequirement();
                    if ($targetReq === null) {
                        continue;
                    }

                    $reusableTargets[$targetReq->getId()] = true;
                    $contributed = true;
                }

                // Bidirectional coverage: check reverse direction too (new -> existing mapped bidirectional).
                $reverseMappings = $this->mappingRepository->findCrossFrameworkMappings(
                    $newFramework,
                    $existingFramework,
                );

                foreach ($reverseMappings as $reverseMapping) {
                    if (!$reverseMapping->isBidirectional()) {
                        continue;
                    }
                    if ($reverseMapping->getMappingPercentage() < self::MIN_MAPPING_PERCENTAGE_FOR_REUSE) {
                        continue;
                    }

                    $sourceReq = $reverseMapping->getSourceRequirement();
                    if ($sourceReq === null) {
                        continue;
                    }

                    $reusableTargets[$sourceReq->getId()] = true;
                    $contributed = true;
                }

                if ($contributed) {
                    $sourcesContributing[] = $existingFramework->getCode();
                }
            }

            $reusable = count($reusableTargets);
            $reusable = min($reusable, $totalRequirements); // cap sanity
            $fteDays  = round($reusable * $this->fteDaysPerRequirement(), 1);
            $percent  = $totalRequirements > 0 ? round(($reusable / $totalRequirements) * 100, 1) : 0.0;

            $result[$newCode] = [
                'code' => $newCode,
                'name' => $newFramework->getName() ?? $newCode,
                'delta_requirements' => $totalRequirements,
                'estimated_reusable' => $reusable,
                'estimated_fte_days_saved' => $fteDays,
                'reuse_percentage' => $percent,
                'sources' => array_values(array_unique($sourcesContributing)),
            ];
        }

        return $result;
    }

    /**
     * @param string[] $codes
     *
     * @return ComplianceFramework[]
     */
    private function loadFrameworksByCode(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $frameworks = [];
        foreach ($codes as $code) {
            $framework = $this->frameworkRepository->findOneBy(['code' => $code]);
            if ($framework instanceof ComplianceFramework) {
                $frameworks[] = $framework;
            }
        }

        return $frameworks;
    }
}
