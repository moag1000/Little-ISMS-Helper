<?php

declare(strict_types=1);

namespace App\Lifecycle\Config;

use App\Entity\Tenant;
use App\Lifecycle\EntityTypeRegistry;
use App\Repository\LifecycleConfigRepository;
use App\Service\TenantContext;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Two-layer config resolver:
 *  1. Static YAML metadata is the canonical baseline.
 *  2. lifecycle_config rows of the RECORD's organisation override individual metadata keys,
 *     as far as {@see LifecycleOverridePolicy} lets them (tighten, never loosen — S15).
 *
 * Voter / Guards / Listeners ALWAYS call this, never read YAML directly.
 *
 * The organisation is the record's (`getTenant()`), falling back to the current one only for
 * records without an organisation: a background job has no current tenant, and a holding user
 * acting in another organisation must get that organisation's rules (S15 — it used the
 * current tenant before).
 */
final class LifecycleConfigResolver implements LifecycleConfigResolverInterface
{
    public function __construct(
        private readonly Registry $workflowRegistry,
        private readonly LifecycleConfigRepository $overrideRepository,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array<string, mixed>  Effective metadata for the given transition,
     *                               YAML keys plus the permitted tenant overrides.
     */
    public function resolve(object $subject, string $workflowName, string $transitionName): array
    {
        $workflow = $this->workflowRegistry->get($subject, $workflowName);
        $transition = $this->findTransition($workflow, $transitionName);
        $yaml = $transition === null ? [] : $workflow->getMetadataStore()->getTransitionMetadata($transition);

        $tenant = $this->tenantOf($subject);
        if ($tenant === null || !EntityTypeRegistry::acceptsTenantOverrides($workflowName)) {
            return $yaml;
        }

        $overrides = $this->overrideRepository->findOverridesForTransition(
            $tenant,
            $workflowName,
            $transitionName,
        );

        return array_replace($yaml, LifecycleOverridePolicy::effective($yaml, $overrides));
    }

    public function get(object $subject, string $workflowName, string $transitionName, string $key, mixed $default = null): mixed
    {
        $merged = $this->resolve($subject, $workflowName, $transitionName);
        return $merged[$key] ?? $default;
    }

    private function tenantOf(object $subject): ?Tenant
    {
        // Generic subject (any lifecycle-managed entity), not a typed entity — Gate 83 n/a.
        if (method_exists($subject, 'getTenant')) {
            $tenant = $subject->getTenant();
            if ($tenant instanceof Tenant) {
                return $tenant;
            }
        }

        return $this->tenantContext->getCurrentTenant();
    }

    private function findTransition(WorkflowInterface $workflow, string $transitionName): ?\Symfony\Component\Workflow\Transition
    {
        foreach ($workflow->getDefinition()->getTransitions() as $t) {
            if ($t->getName() === $transitionName) {
                return $t;
            }
        }
        return null;
    }
}
