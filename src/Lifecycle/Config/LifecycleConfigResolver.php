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
 *
 * Extended in Y.3 to also support workflow-step-scoped overrides:
 *   resolveWorkflowStep($workflowName, $stepIndex) merges step YAML metadata
 *   with lifecycle_config rows keyed as:
 *     workflowName = 'workflow:{name}', transitionName = 'step:{index}'
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

    /**
     * Resolve merged config for a workflow-step overlay (Y.3).
     *
     * The YAML step baseline comes from the regulatory workflow's metadata.steps
     * array (loaded via RegulatoryWorkflowLoader if available, or passed in directly).
     * Tenant DB overrides are stored with:
     *   workflowName = 'workflow:{name}',  transitionName = 'step:{index}'
     *
     * @param  array<string, mixed> $yamlStepBaseline  Step metadata from YAML (name, approver_role, days_to_complete, etc.)
     * @return array<string, mixed>  Merged step config
     */
    public function resolveWorkflowStep(string $workflowName, int $stepIndex, array $yamlStepBaseline = []): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            return $yamlStepBaseline;
        }

        $overrides = $this->overrideRepository->findByWorkflowAndStep($tenant, $workflowName, $stepIndex);

        return array_replace($yamlStepBaseline, $overrides);
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
