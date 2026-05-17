<?php

declare(strict_types=1);

namespace App\Lifecycle\Config;

use App\Repository\LifecycleConfigRepository;
use App\Service\TenantContext;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Two-layer config resolver:
 *  1. Static YAML metadata is the canonical baseline.
 *  2. lifecycle_config rows (tenant-scoped) override individual metadata keys.
 *
 * Voter / Guards / Listeners ALWAYS call this, never read YAML directly.
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
     *                               YAML keys plus tenant overrides.
     */
    public function resolve(object $subject, string $workflowName, string $transitionName): array
    {
        $workflow = $this->workflowRegistry->get($subject, $workflowName);
        $transition = $this->findTransition($workflow, $transitionName);
        $yaml = $transition === null ? [] : $workflow->getMetadataStore()->getTransitionMetadata($transition);

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            return $yaml;
        }

        $overrides = $this->overrideRepository->findOverridesForTransition(
            $tenant,
            $workflowName,
            $transitionName,
        );

        return array_replace($yaml, $overrides);
    }

    public function get(object $subject, string $workflowName, string $transitionName, string $key, mixed $default = null): mixed
    {
        $merged = $this->resolve($subject, $workflowName, $transitionName);
        return $merged[$key] ?? $default;
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
