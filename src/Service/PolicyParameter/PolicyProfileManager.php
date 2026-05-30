<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

use App\Entity\OrganizationSecurityProfile;

/**
 * Orchestration facade over the policy-parameter services. Mutates the profile
 * entity in memory (apply sector); persistence is the caller's concern.
 * Resolves the effective value of every catalog parameter and computes
 * per-framework coverage for the profile's sector frameworks.
 */
final readonly class PolicyProfileManager
{
    public function __construct(
        private PolicyParameterCatalog $params,
        private PolicyBaselineCatalog $baselines,
        private PolicyParameterResolver $resolver,
        private PolicyBaselineApplier $applier,
        private FrameworkCoverageEvaluator $evaluator,
    ) {
    }

    public function applySector(OrganizationSecurityProfile $profile, string $sector): void
    {
        $this->applier->apply($sector, $profile);
    }

    /**
     * Effective value of every catalog parameter for this profile.
     *
     * @param array<string, mixed> $overrides per-run override values
     *
     * @return array<string, mixed> param-key => effective value
     */
    public function resolveAll(OrganizationSecurityProfile $profile, array $overrides = []): array
    {
        $baseline = $this->baselinePresets($profile);

        $out = [];
        foreach ($this->params->keys() as $key) {
            $out[$key] = $this->resolver->resolve($key, $profile, $baseline, $overrides);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, FrameworkCoverage> keyed by framework
     */
    public function coverage(OrganizationSecurityProfile $profile, array $overrides = []): array
    {
        $sector = $profile->getSectorKey();
        $frameworks = $sector !== null ? $this->baselines->get($sector)->frameworks : [];

        return $this->evaluator->evaluate($frameworks, $this->resolveAll($profile, $overrides));
    }

    /** @return array<string, mixed> */
    private function baselinePresets(OrganizationSecurityProfile $profile): array
    {
        $sector = $profile->getSectorKey();

        return $sector !== null ? $this->baselines->get($sector)->presetValues() : [];
    }
}
