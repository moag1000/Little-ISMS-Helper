<?php

declare(strict_types=1);

namespace App\Tests\Workflow\Loader;

use App\Entity\WorkflowInstance;
use App\Workflow\Loader\RegulatoryWorkflowLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\SupportStrategy\InstanceOfSupportStrategy;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Yaml\Yaml;

/**
 * Sprint Y.2 — Unit tests for RegulatoryWorkflowLoader, over the real regulatory YAML (S17:
 * the earlier stub carried the steps in a layout no YAML used, so the loader returned null in
 * production while these tests stayed green).
 *
 * Verifies:
 *  - All 15 regulatory YAML workflows are discoverable in the registry
 *  - Each workflow returns the expected number of steps
 *  - Required step fields (name, approver_role, days_to_complete) are present
 *  - Auto-progress conditions parse correctly where defined
 *  - Regulatory metadata block (standard, sla_hours) is accessible
 *  - Unregistered workflow name returns null
 *  - getRegisteredRegulatoryWorkflowNames() lists all 15
 */
final class RegulatoryWorkflowLoaderTest extends TestCase
{
    private RegulatoryWorkflowLoader $loader;

    protected function setUp(): void
    {
        $this->loader = $this->buildLoader();
    }

    // -------------------------------------------------------------------------
    // Tests: getStepsForWorkflow
    // -------------------------------------------------------------------------

    #[Test]
    public function returnsNullForUnknownWorkflow(): void
    {
        $this->assertNull($this->loader->getStepsForWorkflow('non_existent_workflow'));
    }

    #[Test]
    public function returnsNullForEntityStageWorkflowWithoutStepsBlock(): void
    {
        // entity-stage SMs (e.g. document_lifecycle) have no steps block
        $this->assertNull($this->loader->getStepsForWorkflow('no_steps_workflow'));
    }

    #[Test]
    #[DataProvider('workflowStepCountProvider')]
    public function workflowReturnsExpectedStepCount(string $workflowName, int $expectedStepCount): void
    {
        $steps = $this->loader->getStepsForWorkflow($workflowName);
        $this->assertNotNull($steps, "Workflow '{$workflowName}' should be registered with steps");
        $this->assertCount($expectedStepCount, $steps, "Workflow '{$workflowName}' should have {$expectedStepCount} steps");
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function workflowStepCountProvider(): array
    {
        return [
            'gdpr_data_breach' => ['gdpr_data_breach', 6],
            'incident_high_severity' => ['incident_high_severity', 6],
            'incident_low_severity' => ['incident_low_severity', 4],
            'risk_treatment' => ['risk_treatment', 6],
            'dpia' => ['dpia', 6],
            'dsr' => ['dsr', 5],
            'capa' => ['capa', 5],
            'change_request' => ['change_request', 4],
            'management_review' => ['management_review', 4],
            'control_verification' => ['control_verification', 3],
            'supplier_assessment' => ['supplier_assessment', 5],
            'training_verification' => ['training_verification', 2],
            'bc_plan_activation' => ['bc_plan_activation', 5],
            'document_review' => ['document_review', 3],
            'incident_post_mortem' => ['incident_post_mortem', 3],
        ];
    }

    #[Test]
    #[DataProvider('workflowRequiredFieldsProvider')]
    public function eachStepHasRequiredFields(string $workflowName): void
    {
        $steps = $this->loader->getStepsForWorkflow($workflowName);
        $this->assertNotNull($steps);

        foreach ($steps as $index => $step) {
            $this->assertArrayHasKey('name', $step, "Step {$index} of {$workflowName} missing 'name'");
            $this->assertArrayHasKey('approver_role', $step, "Step {$index} of {$workflowName} missing 'approver_role'");
            $this->assertArrayHasKey('days_to_complete', $step, "Step {$index} of {$workflowName} missing 'days_to_complete'");
            $this->assertArrayHasKey('order', $step, "Step {$index} of {$workflowName} missing 'order'");
            $this->assertNotEmpty($step['name'], "Step {$index} of {$workflowName} has empty 'name'");
            $this->assertNotEmpty($step['approver_role'], "Step {$index} of {$workflowName} has empty 'approver_role'");
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function workflowRequiredFieldsProvider(): array
    {
        return array_map(
            static fn($name) => [$name],
            [
                'gdpr_data_breach', 'incident_high_severity', 'incident_low_severity',
                'risk_treatment', 'dpia', 'dsr', 'capa', 'change_request',
                'management_review', 'control_verification', 'supplier_assessment',
                'training_verification', 'bc_plan_activation', 'document_review',
                'incident_post_mortem',
            ]
        );
    }

    /**
     * S17: only `time_based` conditions are evaluated (app:process-timed-workflows); the 27
     * field_completion/auto/risk_appetite conditions nobody evaluated are gone (Gate 82).
     */
    #[Test]
    public function noStepCarriesAConditionNothingEvaluates(): void
    {
        foreach ($this->loader->getRegisteredRegulatoryWorkflowNames() as $name) {
            foreach ($this->loader->getStepsForWorkflow($name) ?? [] as $index => $step) {
                $type = $step['auto_progress_conditions']['type'] ?? 'time_based';
                $this->assertSame('time_based', $type, sprintf('%s step %d', $name, $index + 1));
            }
        }
    }

    #[Test]
    public function capaStep4HasLoopBackRejectAction(): void
    {
        $steps = $this->loader->getStepsForWorkflow('capa');
        $this->assertNotNull($steps);

        // Step 4 (index 3) = Effectiveness Verification — has loop_back
        $step4 = $steps[3];
        $this->assertSame('loop_back', $step4['reject_action']);
        $this->assertSame(3, $step4['reject_target_step']);
    }

    #[Test]
    public function controlVerificationStep3HasLoopBackRejectAction(): void
    {
        $steps = $this->loader->getStepsForWorkflow('control_verification');
        $this->assertNotNull($steps);

        $step3 = $steps[2];
        $this->assertSame('loop_back', $step3['reject_action']);
        $this->assertSame(1, $step3['reject_target_step']);
    }

    // -------------------------------------------------------------------------
    // Tests: getRegulatoryMetadata
    // -------------------------------------------------------------------------

    #[Test]
    public function gdprDataBreachMetadataHasCorrectSlaHours(): void
    {
        $meta = $this->loader->getRegulatoryMetadata('gdpr_data_breach');
        $this->assertNotNull($meta);
        $this->assertSame(72, $meta['sla_hours']);
        $this->assertStringContainsString('GDPR', $meta['standard']);
    }

    #[Test]
    public function dsrMetadataHas720HourSla(): void
    {
        $meta = $this->loader->getRegulatoryMetadata('dsr');
        $this->assertNotNull($meta);
        $this->assertSame(720, $meta['sla_hours']); // 30 days
    }

    #[Test]
    public function regulatoryMetadataDoesNotExposeStepsKey(): void
    {
        // getRegulatoryMetadata() strips the steps sub-key
        $meta = $this->loader->getRegulatoryMetadata('gdpr_data_breach');
        $this->assertNotNull($meta);
        $this->assertArrayNotHasKey('steps', $meta);
    }

    #[Test]
    public function returnsNullMetadataForUnregisteredWorkflow(): void
    {
        $this->assertNull($this->loader->getRegulatoryMetadata('unknown_workflow'));
    }

    // -------------------------------------------------------------------------
    // Tests: getRegisteredRegulatoryWorkflowNames
    // -------------------------------------------------------------------------

    #[Test]
    public function registeredNamesContainsAllFifteenWorkflows(): void
    {
        $names = $this->loader->getRegisteredRegulatoryWorkflowNames();

        $expected = [
            'gdpr_data_breach', 'incident_high_severity', 'incident_low_severity',
            'risk_treatment', 'dpia', 'dsr', 'capa', 'change_request',
            'management_review', 'control_verification', 'supplier_assessment',
            'training_verification', 'bc_plan_activation', 'document_review',
            'incident_post_mortem',
        ];

        foreach ($expected as $name) {
            $this->assertContains($name, $names, "'{$name}' should be in registered regulatory workflow names");
        }

        $this->assertCount(15, $names, 'Exactly 15 regulatory workflows should be registered');
    }

    // -------------------------------------------------------------------------
    // Tests: isRegistered
    // -------------------------------------------------------------------------

    #[Test]
    public function isRegisteredReturnsTrueForKnownWorkflow(): void
    {
        $this->assertTrue($this->loader->isRegistered('gdpr_data_breach'));
    }

    #[Test]
    public function stepsSitAtMetadataStepsInEveryRegulatoryYaml(): void
    {
        foreach ($this->getStubWorkflowDefinitions() as $name => $config) {
            $this->assertNotEmpty($config['metadata']['steps'] ?? null, "{$name}: steps belong at metadata.steps");
            $this->assertArrayNotHasKey('steps', $config['metadata']['regulatory_metadata'] ?? [], "{$name}: no nested steps");
        }
    }

    #[Test]
    public function theOlderNestedLayoutIsStillRead(): void
    {
        $registry = new Registry();
        $definition = new Definition(['pending', 'done'], [new Transition('start', 'pending', 'done')], 'pending', new InMemoryMetadataStore([
            'regulatory_metadata' => ['standard' => 'X', 'steps' => [['name' => 'Only', 'approver_role' => 'ROLE_CISO']]],
        ]));
        $registry->addWorkflow(new StateMachine($definition, null, null, 'nested'), new InstanceOfSupportStrategy(WorkflowInstance::class));

        $steps = (new RegulatoryWorkflowLoader($registry))->getStepsForWorkflow('nested');

        $this->assertSame([['name' => 'Only', 'approver_role' => 'ROLE_CISO']], $steps);
    }

    #[Test]
    public function isRegisteredReturnsFalseForUnknownWorkflow(): void
    {
        $this->assertFalse($this->loader->isRegistered('unknown'));
    }

    // -------------------------------------------------------------------------
    // Internal builder: creates a Registry with stub workflows
    // -------------------------------------------------------------------------

    private function buildLoader(): RegulatoryWorkflowLoader
    {
        $registry = new Registry();

        // Register all 15 regulatory workflows as stub StateMachines with correct metadata
        $regulatoryWorkflows = $this->getStubWorkflowDefinitions();
        foreach ($regulatoryWorkflows as $name => $config) {
            $places = ['pending', 'in_progress', 'approved', 'rejected', 'cancelled'];
            $transitions = [
                new Transition('start', 'pending', 'in_progress'),
                new Transition('approve', 'in_progress', 'approved'),
                new Transition('reject', 'in_progress', 'rejected'),
            ];

            $metadataStore = $this->buildMetadataStore($config['metadata']);
            $definition = new Definition($places, $transitions, 'pending', $metadataStore);
            $workflow = new StateMachine($definition, null, null, $name);
            $registry->addWorkflow($workflow, new InstanceOfSupportStrategy(WorkflowInstance::class));
        }

        // Register a workflow with no steps (entity-stage SM stub)
        {
            $places = ['draft', 'published'];
            $transitions = [new Transition('publish', 'draft', 'published')];
            $metadataStore = $this->buildMetadataStore([]);
            $definition = new Definition($places, $transitions, 'draft', $metadataStore);
            $workflow = new StateMachine($definition, null, null, 'no_steps_workflow');
            $registry->addWorkflow($workflow, new InstanceOfSupportStrategy(WorkflowInstance::class));
        }

        return new RegulatoryWorkflowLoader($registry);
    }

    /**
     * The metadata of the real config/workflows/regulatory/*.yaml (S17): the tests read what the
     * application loads, not a hand-written copy in another layout.
     *
     * @return array<string, array{metadata: array<string, mixed>}>
     */
    private function getStubWorkflowDefinitions(): array
    {
        $definitions = [];
        foreach (glob(\dirname(__DIR__, 3) . '/config/workflows/regulatory/*.yaml') ?: [] as $file) {
            foreach (Yaml::parseFile($file)['framework']['workflows'] ?? [] as $name => $workflow) {
                $definitions[$name] = ['metadata' => $workflow['metadata'] ?? []];
            }
        }

        return $definitions;
    }

    /**
     * Build an InMemoryMetadataStore from a plain array.
     */
    private function buildMetadataStore(array $metadata): InMemoryMetadataStore
    {
        return new InMemoryMetadataStore($metadata);
    }
}
