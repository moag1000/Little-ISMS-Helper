<?php

declare(strict_types=1);

namespace App\Workflow\Loader;

use Symfony\Component\Workflow\Registry;

/**
 * Sprint Y.2 — RegulatoryWorkflowLoader
 *
 * Reads the `metadata.steps` block from each regulatory workflow registered in
 * Symfony's Workflow Registry (config/workflows/regulatory/*.yaml) and exposes
 * a simple `getStepsForWorkflow(string $name): array` API.
 *
 * This replaces the DB-backed `WorkflowRepository::findOneBy(['name' => ...])` lookup
 * inside WorkflowService::startWorkflow() for the 15 regulatory approval chains.
 * For workflows that are NOT registered in the YAML (custom tenant workflows), the
 * loader returns null and WorkflowService falls back to the DB lookup — backwards-compat.
 *
 * The steps array shape matches the PHP-array format used by WorkflowService / WorkflowStep:
 *   [
 *     'name'                   => string,
 *     'description'            => string,
 *     'order'                  => int,
 *     'approver_role'          => string,
 *     'step_type'              => string,
 *     'days_to_complete'       => int,
 *     'is_required'            => bool,
 *     'auto_progress_conditions' => array|null,
 *     'reject_action'          => string|null,   // 'loop_back'
 *     'reject_target_step'     => int|null,
 *   ]
 */
class RegulatoryWorkflowLoader
{
    /** @var array<string, array<int, array<string, mixed>>> Cache: workflow_name → steps */
    private array $cache = [];

    public function __construct(
        private readonly Registry $workflowRegistry,
    ) {}

    /**
     * Return the steps metadata for a regulatory workflow YAML.
     *
     * Returns null when the workflow is not registered in the Symfony Workflow
     * Registry (i.e. it is a DB-only custom tenant workflow).
     *
     * @return list<array<string, mixed>>|null
     */
    public function getStepsForWorkflow(string $workflowName): ?array
    {
        if (array_key_exists($workflowName, $this->cache)) {
            return $this->cache[$workflowName];
        }

        $definition = $this->findDefinition($workflowName);
        if ($definition === null) {
            return null;
        }

        $workflowMetadata = $definition->getMetadataStore()->getWorkflowMetadata();

        // Steps live under regulatory_metadata.steps (spec D5)
        $steps = $workflowMetadata['regulatory_metadata']['steps'] ?? null;

        if (!is_array($steps) || $steps === []) {
            // Workflow exists in registry but has no steps block (entity-stage SM, not approval chain)
            return null;
        }

        // Normalise numeric keys — the YAML list may have integer keys
        $normalised = array_values($steps);

        $this->cache[$workflowName] = $normalised;

        return $normalised;
    }

    /**
     * Return the regulatory_metadata block for a workflow (standard, sla_hours, etc.).
     *
     * @return array<string, mixed>|null
     */
    public function getRegulatoryMetadata(string $workflowName): ?array
    {
        $definition = $this->findDefinition($workflowName);
        if ($definition === null) {
            return null;
        }

        $workflowMetadata = $definition->getMetadataStore()->getWorkflowMetadata();
        $regMeta = $workflowMetadata['regulatory_metadata'] ?? null;

        if (!is_array($regMeta)) {
            return null;
        }

        // Remove the steps sub-key — callers should use getStepsForWorkflow() for that
        unset($regMeta['steps']);

        return $regMeta;
    }

    /**
     * Return the list of all workflow names that have a steps block in the registry.
     *
     * @return string[]
     */
    public function getRegisteredRegulatoryWorkflowNames(): array
    {
        $names = [];

        foreach ($this->getAllWorkflowDefinitions() as $name => $definition) {
            $workflowMetadata = $definition->getMetadataStore()->getWorkflowMetadata();
            $steps = $workflowMetadata['regulatory_metadata']['steps'] ?? null;
            if (is_array($steps) && $steps !== []) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Check whether a workflow name is registered in the Symfony Workflow Registry.
     */
    public function isRegistered(string $workflowName): bool
    {
        return $this->findDefinition($workflowName) !== null;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Find a Symfony Workflow Definition by name using the Registry.
     *
     * The Registry only exposes `get($subject, $workflowName)` — not a direct
     * name lookup. We use reflection to access the internal workflow list so we
     * can iterate without needing a subject object.
     *
     * Falls back gracefully: if reflection is unavailable the method returns null
     * (treating the workflow as unregistered).
     */
    private function findDefinition(string $workflowName): ?\Symfony\Component\Workflow\Definition
    {
        foreach ($this->getAllWorkflowDefinitions() as $name => $definition) {
            if ($name === $workflowName) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Return all workflow definitions keyed by workflow name.
     *
     * @return array<string, \Symfony\Component\Workflow\Definition>
     */
    private function getAllWorkflowDefinitions(): array
    {
        // Symfony's Registry stores workflows in a private $workflows array of objects
        // that implement WorkflowInterface. Each has getName() and getDefinition().
        try {
            $ref = new \ReflectionProperty(Registry::class, 'workflows');
            /** @var list<array{0: \Symfony\Component\Workflow\WorkflowInterface, 1: mixed}> $workflows */
            $workflows = $ref->getValue($this->workflowRegistry);

            $result = [];
            foreach ($workflows as [$workflow]) {
                if (method_exists($workflow, 'getName') && method_exists($workflow, 'getDefinition')) {
                    /** @var string $name */
                    $name = $workflow->getName();
                    $result[$name] = $workflow->getDefinition();
                }
            }

            return $result;
        } catch (\ReflectionException) {
            return [];
        }
    }
}
