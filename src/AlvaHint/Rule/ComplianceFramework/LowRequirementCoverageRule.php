<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\ComplianceFramework;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\ComplianceFramework;
use App\Entity\User;
use App\Repository\ComplianceRequirementRepository;
use WeakMap;

/**
 * Tier-2 hint: ISO 27001 Cl. 9.1 — compliance frameworks with fewer
 * than COVERAGE_THRESHOLD percent of requirements mapped to controls
 * or evidence represent a significant audit gap. The hint surfaces
 * early when the tenant has imported a framework but not yet acted
 * on it, giving managers a timely nudge before the external audit.
 */
final class LowRequirementCoverageRule extends AbstractAlvaHintRule
{
    private const float COVERAGE_THRESHOLD = 50.0;

    /** @var WeakMap<ComplianceFramework, float> */
    private WeakMap $coverageCache;

    public function __construct(private readonly ComplianceRequirementRepository $requirementRepository)
    {
        $this->coverageCache = new WeakMap();
    }

    /** Percentage computed once per framework, with bounded queries (preloaded). */
    private function coverage(ComplianceFramework $framework): float
    {
        if (!isset($this->coverageCache[$framework])) {
            $this->requirementRepository->preloadMappedControls($framework);
            $this->coverageCache[$framework] = $framework->getCompliancePercentage();
        }

        return $this->coverageCache[$framework];
    }

    public function key(): string
    {
        return 'compliance_framework.low_coverage';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['compliance'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof ComplianceFramework) {
            return false;
        }

        if (!$entity->isActive()) {
            return false;
        }

        if ($entity->getRequirements()->isEmpty()) {
            return false;
        }

        return $this->coverage($entity) < self::COVERAGE_THRESHOLD;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof ComplianceFramework);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'compliance_framework.low_coverage.title',
            bodyTranslationKey: 'compliance_framework.low_coverage.body',
            bodyTranslationParams: [
                '%coverage%' => number_format($this->coverage($entity), 1),
                '%threshold%' => (int) self::COVERAGE_THRESHOLD,
                '%name%' => (string) $entity->getName(),
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'ComplianceFramework',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'compliance_framework.low_coverage.action',
            actionRoute: 'app_compliance_framework_show',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
