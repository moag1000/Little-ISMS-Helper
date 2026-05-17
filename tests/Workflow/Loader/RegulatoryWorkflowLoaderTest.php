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

/**
 * Sprint Y.2 — Unit tests for RegulatoryWorkflowLoader.
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

    #[Test]
    public function gdprDataBreachHasAutoProgressConditionsOnStep1(): void
    {
        $steps = $this->loader->getStepsForWorkflow('gdpr_data_breach');
        $this->assertNotNull($steps);

        $step1 = $steps[0];
        $this->assertArrayHasKey('auto_progress_conditions', $step1);
        $conditions = $step1['auto_progress_conditions'];
        $this->assertSame('field_completion', $conditions['type']);
        $this->assertSame('DataBreach', $conditions['entity']);
        $this->assertContains('severity', $conditions['fields']);
        $this->assertContains('affectedDataSubjectsCount', $conditions['fields']);
        $this->assertContains('notificationRequired', $conditions['fields']);
    }

    #[Test]
    public function riskTreatmentHasRiskAppetiteConditionOnStep1(): void
    {
        $steps = $this->loader->getStepsForWorkflow('risk_treatment');
        $this->assertNotNull($steps);

        $step1 = $steps[0];
        $this->assertArrayHasKey('auto_progress_conditions', $step1);
        $conditions = $step1['auto_progress_conditions'];
        $this->assertSame('risk_appetite', $conditions['type']);
        $this->assertSame('Risk', $conditions['entity']);
    }

    #[Test]
    public function dpiaFinalStepHasComplexCondition(): void
    {
        $steps = $this->loader->getStepsForWorkflow('dpia');
        $this->assertNotNull($steps);

        $lastStep = end($steps);
        $this->assertArrayHasKey('auto_progress_conditions', $lastStep);
        $conditions = $lastStep['auto_progress_conditions'];
        $this->assertSame('field_completion', $conditions['type']);
        $this->assertStringContainsString('supervisoryConsultationDate', $conditions['condition']);
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
     * @return array<string, array{metadata: array<string, mixed>}>
     */
    private function getStubWorkflowDefinitions(): array
    {
        return [
            'gdpr_data_breach' => [
                'metadata' => [
                    'regulatory_metadata' => [
                        'standard' => 'GDPR Art. 33/34',
                        'sla_hours' => 72,
                        'escalation_threshold_hours' => 60,
                        'escalation_role' => 'ROLE_ADMIN',
                        'steps' => [
                            ['name' => 'Initial Assessment (DPO)', 'order' => 1, 'approver_role' => 'ROLE_DPO', 'step_type' => 'approval', 'days_to_complete' => 1, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'DataBreach', 'fields' => ['severity', 'affectedDataSubjectsCount', 'dataCategories', 'notificationRequired']]],
                            ['name' => 'Technical Assessment (CISO)', 'order' => 2, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 1, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'DataBreach', 'fields' => ['rootCause', 'affectedSystems', 'containmentMeasures', 'recoveryMeasures']]],
                            ['name' => 'Management Information', 'order' => 3, 'approver_role' => 'ROLE_MANAGER', 'step_type' => 'notification', 'days_to_complete' => 0, 'is_required' => false, 'auto_progress_conditions' => ['type' => 'auto', 'condition' => 'severity >= high']],
                            ['name' => 'Supervisory Authority Notification (DPO)', 'order' => 4, 'approver_role' => 'ROLE_DPO', 'step_type' => 'approval', 'days_to_complete' => 1, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'DataBreach', 'fields' => ['authorityNotificationDate', 'authorityNotificationMethod']]],
                            ['name' => 'Data Subject Notification (DPO)', 'order' => 5, 'approver_role' => 'ROLE_DPO', 'step_type' => 'approval', 'days_to_complete' => 1, 'is_required' => false, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'DataBreach', 'fields' => ['dataSubjectNotificationDate', 'dataSubjectNotificationMethod'], 'condition' => 'dataSubjectNotificationRequired = true']],
                            ['name' => 'Final Documentation (DPO)', 'order' => 6, 'approver_role' => 'ROLE_DPO', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'DataBreach', 'fields' => ['lessonsLearned', 'processImprovements']]],
                        ],
                    ],
                ],
            ],
            'incident_high_severity' => [
                'metadata' => [
                    'regulatory_metadata' => [
                        'standard' => 'ISO 27001:2022 Cl. 5.24-5.28',
                        'sla_hours' => 24,
                        'steps' => [
                            ['name' => 'Immediate Response (CISO)', 'order' => 1, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 0, 'is_required' => true],
                            ['name' => 'Crisis Management (Crisis Team)', 'order' => 2, 'approver_role' => 'ROLE_CRISIS_MANAGER', 'step_type' => 'approval', 'days_to_complete' => 0, 'is_required' => true],
                            ['name' => 'Technical Response (Security + IT)', 'order' => 3, 'approver_role' => 'ROLE_SECURITY_MANAGER', 'step_type' => 'approval', 'days_to_complete' => 0, 'is_required' => true],
                            ['name' => 'Legal/Compliance Check (Legal + DPO)', 'order' => 4, 'approver_role' => 'ROLE_DPO', 'step_type' => 'approval', 'days_to_complete' => 0, 'is_required' => true],
                            ['name' => 'Recovery Validation (CISO + IT Manager)', 'order' => 5, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 1, 'is_required' => true],
                            ['name' => 'Post-Incident Review (Management)', 'order' => 6, 'approver_role' => 'ROLE_MANAGER', 'step_type' => 'approval', 'days_to_complete' => 14, 'is_required' => true],
                        ],
                    ],
                ],
            ],
            'incident_low_severity' => [
                'metadata' => [
                    'regulatory_metadata' => [
                        'standard' => 'ISO 27001:2022 Cl. 5.24-5.28',
                        'sla_hours' => 168,
                        'steps' => [
                            ['name' => 'Triage (Security Analyst)', 'order' => 1, 'approver_role' => 'ROLE_SECURITY_ANALYST', 'step_type' => 'approval', 'days_to_complete' => 0, 'is_required' => true],
                            ['name' => 'Investigation (Security Team)', 'order' => 2, 'approver_role' => 'ROLE_SECURITY_MANAGER', 'step_type' => 'approval', 'days_to_complete' => 1, 'is_required' => true],
                            ['name' => 'Remediation (IT Operations)', 'order' => 3, 'approver_role' => 'ROLE_IT_MANAGER', 'step_type' => 'approval', 'days_to_complete' => 3, 'is_required' => true],
                            ['name' => 'Review (Security Manager)', 'order' => 4, 'approver_role' => 'ROLE_SECURITY_MANAGER', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => true],
                        ],
                    ],
                ],
            ],
            'risk_treatment' => [
                'metadata' => [
                    'regulatory_metadata' => [
                        'standard' => 'ISO 27001:2022 Cl. 6.1.3 / ISO 27005:2022',
                        'sla_hours' => null,
                        'steps' => [
                            ['name' => 'Risk Owner Approval', 'order' => 1, 'approver_role' => 'ROLE_RISK_OWNER', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'risk_appetite', 'entity' => 'Risk', 'risk_score_field' => 'residualRisk']],
                            ['name' => 'CISO Technical Review', 'order' => 2, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 3, 'is_required' => false],
                            ['name' => 'DPO Privacy Impact Review', 'order' => 3, 'approver_role' => 'ROLE_DPO', 'step_type' => 'approval', 'days_to_complete' => 2, 'is_required' => false],
                            ['name' => 'CFO Budget Approval', 'order' => 4, 'approver_role' => 'ROLE_CFO', 'step_type' => 'approval', 'days_to_complete' => 3, 'is_required' => false],
                            ['name' => 'CEO/Board Approval', 'order' => 5, 'approver_role' => 'ROLE_CEO', 'step_type' => 'approval', 'days_to_complete' => 3, 'is_required' => false],
                            ['name' => 'Audit Committee Notification', 'order' => 6, 'approver_role' => 'ROLE_AUDITOR', 'step_type' => 'notification', 'days_to_complete' => 0, 'is_required' => false, 'auto_progress_conditions' => ['type' => 'auto', 'condition' => 'residualRisk >= high']],
                        ],
                    ],
                ],
            ],
            'dpia' => [
                'metadata' => [
                    'regulatory_metadata' => [
                        'standard' => 'GDPR Art. 35/36',
                        'sla_hours' => null,
                        'steps' => [
                            ['name' => 'DPIA Creation (Data Owner)', 'order' => 1, 'approver_role' => 'ROLE_DATA_OWNER', 'step_type' => 'approval', 'days_to_complete' => 14, 'is_required' => true],
                            ['name' => 'DPO Review', 'order' => 2, 'approver_role' => 'ROLE_DPO', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => true],
                            ['name' => 'Technical Security Review (CISO)', 'order' => 3, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 5, 'is_required' => true],
                            ['name' => 'Risk Assessment', 'order' => 4, 'approver_role' => 'ROLE_RISK_MANAGER', 'step_type' => 'approval', 'days_to_complete' => 5, 'is_required' => true],
                            ['name' => 'Management Approval', 'order' => 5, 'approver_role' => 'ROLE_MANAGER', 'step_type' => 'approval', 'days_to_complete' => 3, 'is_required' => true],
                            ['name' => 'DPO Final Check & Supervisory Authority Consultation', 'order' => 6, 'approver_role' => 'ROLE_DPO', 'step_type' => 'approval', 'days_to_complete' => 2, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'DataProtectionImpactAssessment', 'fields' => ['residualRiskLevel'], 'condition' => '(residualRiskLevel = low OR residualRiskLevel = medium) OR ((residualRiskLevel = high OR residualRiskLevel = critical) AND supervisoryConsultationDate != null)']],
                        ],
                    ],
                ],
            ],
            'dsr' => [
                'metadata' => [
                    'regulatory_metadata' => [
                        'standard' => 'GDPR Art. 12(3) / Art. 12-22',
                        'sla_hours' => 720,
                        'steps' => [
                            ['name' => 'Identity Verification', 'order' => 1, 'approver_role' => 'ROLE_DPO', 'step_type' => 'approval', 'days_to_complete' => 3, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'DataSubjectRequest', 'fields' => ['identityVerified', 'identityVerificationMethod']]],
                            ['name' => 'Request Processing', 'order' => 2, 'approver_role' => 'ROLE_USER', 'step_type' => 'approval', 'days_to_complete' => 14, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'DataSubjectRequest', 'fields' => ['responseDescription']]],
                            ['name' => 'DPO Review & Approval', 'order' => 3, 'approver_role' => 'ROLE_DPO', 'step_type' => 'approval', 'days_to_complete' => 5, 'is_required' => true],
                            ['name' => 'Response Delivery', 'order' => 4, 'approver_role' => 'ROLE_DPO', 'step_type' => 'approval', 'days_to_complete' => 3, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'DataSubjectRequest', 'fields' => ['completedAt']]],
                            ['name' => 'Deadline Extension (Art. 12(3))', 'order' => 5, 'approver_role' => 'ROLE_DPO', 'step_type' => 'notification', 'days_to_complete' => 0, 'is_required' => false, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'DataSubjectRequest', 'fields' => ['extensionReason', 'extendedDeadlineAt'], 'condition' => 'extensionReason != null']],
                        ],
                    ],
                ],
            ],
            'capa' => [
                'metadata' => [
                    'regulatory_metadata' => [
                        'standard' => 'ISO 27001:2022 Cl. 10.1',
                        'sla_hours' => null,
                        'steps' => [
                            ['name' => 'Root Cause Analysis', 'order' => 1, 'approver_role' => 'ROLE_USER', 'step_type' => 'approval', 'days_to_complete' => 14, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'CorrectiveAction', 'fields' => ['rootCauseAnalysis']]],
                            ['name' => 'Action Plan Approval', 'order' => 2, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => true],
                            ['name' => 'Implementation', 'order' => 3, 'approver_role' => 'ROLE_USER', 'step_type' => 'approval', 'days_to_complete' => 30, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'CorrectiveAction', 'fields' => ['actualCompletionDate']]],
                            ['name' => 'Effectiveness Verification', 'order' => 4, 'approver_role' => 'ROLE_AUDITOR', 'step_type' => 'approval', 'days_to_complete' => 14, 'is_required' => true, 'reject_action' => 'loop_back', 'reject_target_step' => 3],
                            ['name' => 'Closure', 'order' => 5, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 3, 'is_required' => true],
                        ],
                    ],
                ],
            ],
            'change_request' => [
                'metadata' => [
                    'regulatory_metadata' => [
                        'standard' => 'ISO 27001:2022 A.8.32 / Cl. 6.3',
                        'sla_hours' => null,
                        'steps' => [
                            ['name' => 'Security Risk Assessment', 'order' => 1, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 5, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'ChangeRequest', 'fields' => ['riskAssessment']]],
                            ['name' => 'CAB Review & Approval', 'order' => 2, 'approver_role' => 'ROLE_MANAGER', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => true, 'reject_action' => 'loop_back', 'reject_target_step' => 1],
                            ['name' => 'Implementation', 'order' => 3, 'approver_role' => 'ROLE_USER', 'step_type' => 'approval', 'days_to_complete' => 14, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'ChangeRequest', 'fields' => ['actualImplementationDate']]],
                            ['name' => 'Post-Implementation Verification', 'order' => 4, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 5, 'is_required' => true],
                        ],
                    ],
                ],
            ],
            'management_review' => [
                'metadata' => [
                    'regulatory_metadata' => [
                        'standard' => 'ISO 27001:2022 Cl. 9.3',
                        'sla_hours' => null,
                        'steps' => [
                            ['name' => 'Input Preparation', 'order' => 1, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 14, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'ManagementReview', 'fields' => ['auditResults', 'changesRelevantToISMS']]],
                            ['name' => 'Review Execution', 'order' => 2, 'approver_role' => 'ROLE_ADMIN', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'ManagementReview', 'fields' => ['decisions', 'reviewDate']]],
                            ['name' => 'Action Planning', 'order' => 3, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'ManagementReview', 'fields' => ['actionItems']]],
                            ['name' => 'Follow-up Verification', 'order' => 4, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 30, 'is_required' => true],
                        ],
                    ],
                ],
            ],
            'control_verification' => [
                'metadata' => [
                    'regulatory_metadata' => [
                        'standard' => 'ISO 27001:2022 Cl. 8 / Annex A',
                        'sla_hours' => null,
                        'steps' => [
                            ['name' => 'Risk Owner Certification', 'order' => 1, 'approver_role' => 'ROLE_USER', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => true],
                            ['name' => 'CISO Technical Validation', 'order' => 2, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => true],
                            ['name' => 'Auditor Acceptance', 'order' => 3, 'approver_role' => 'ROLE_AUDITOR', 'step_type' => 'approval', 'days_to_complete' => 5, 'is_required' => true, 'reject_action' => 'loop_back', 'reject_target_step' => 1],
                        ],
                    ],
                ],
            ],
            'supplier_assessment' => [
                'metadata' => [
                    'regulatory_metadata' => [
                        'standard' => 'ISO 27001:2022 A.5.19-22 / GDPR Art. 28 / DORA Art. 28-30',
                        'sla_hours' => null,
                        'steps' => [
                            ['name' => 'Initial Security Assessment', 'order' => 1, 'approver_role' => 'ROLE_USER', 'step_type' => 'approval', 'days_to_complete' => 14, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'Supplier', 'fields' => ['criticality', 'lastSecurityAssessment']]],
                            ['name' => 'DPO Privacy Review (GDPR Art. 28)', 'order' => 2, 'approver_role' => 'ROLE_DPO', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => false, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'Supplier', 'fields' => ['gdprAvContractSigned'], 'condition' => 'gdprProcessorStatus != none']],
                            ['name' => 'Contract & SLA Verification', 'order' => 3, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'Supplier', 'fields' => ['contractStartDate']]],
                            ['name' => 'DORA ICT Assessment (Financial Sector)', 'order' => 4, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => false, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'Supplier', 'fields' => ['ictCriticality', 'substitutability', 'hasExitStrategy'], 'condition' => 'ictCriticality != null']],
                            ['name' => 'Management Onboarding Approval', 'order' => 5, 'approver_role' => 'ROLE_MANAGER', 'step_type' => 'approval', 'days_to_complete' => 5, 'is_required' => true, 'reject_action' => 'loop_back', 'reject_target_step' => 1],
                        ],
                    ],
                ],
            ],
            'training_verification' => [
                'metadata' => [
                    'regulatory_metadata' => [
                        'standard' => 'ISO 27001:2022 A.6.3',
                        'sla_hours' => null,
                        'steps' => [
                            ['name' => 'Training Completion', 'order' => 1, 'approver_role' => 'ROLE_USER', 'step_type' => 'approval', 'days_to_complete' => 30, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'Training', 'fields' => ['completionDate']]],
                            ['name' => 'Manager Verification', 'order' => 2, 'approver_role' => 'ROLE_MANAGER', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => true],
                        ],
                    ],
                ],
            ],
            'bc_plan_activation' => [
                'metadata' => [
                    'regulatory_metadata' => [
                        'standard' => 'ISO 22301:2019',
                        'sla_hours' => 1,
                        'steps' => [
                            ['name' => 'Crisis Declaration', 'order' => 1, 'approver_role' => 'ROLE_MANAGER', 'step_type' => 'approval', 'days_to_complete' => 0, 'is_required' => true],
                            ['name' => 'Crisis Team Notification', 'order' => 2, 'approver_role' => 'ROLE_USER', 'step_type' => 'notification', 'days_to_complete' => 0, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'auto', 'condition' => 'status = active']],
                            ['name' => 'Recovery Execution', 'order' => 3, 'approver_role' => 'ROLE_MANAGER', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => true],
                            ['name' => 'Plan Deactivation', 'order' => 4, 'approver_role' => 'ROLE_MANAGER', 'step_type' => 'approval', 'days_to_complete' => 1, 'is_required' => true],
                            ['name' => 'Post-Incident Review', 'order' => 5, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 14, 'is_required' => true],
                        ],
                    ],
                ],
            ],
            'document_review' => [
                'metadata' => [
                    'regulatory_metadata' => [
                        'standard' => 'ISO 27001:2022 Cl. 7.5.3',
                        'sla_hours' => null,
                        'steps' => [
                            ['name' => 'Review Required', 'order' => 1, 'approver_role' => 'ROLE_USER', 'step_type' => 'approval', 'days_to_complete' => 14, 'is_required' => true],
                            ['name' => 'Revision Draft', 'order' => 2, 'approver_role' => 'ROLE_USER', 'step_type' => 'approval', 'days_to_complete' => 14, 'is_required' => false],
                            ['name' => 'Approval', 'order' => 3, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => true],
                        ],
                    ],
                ],
            ],
            'incident_post_mortem' => [
                'metadata' => [
                    'regulatory_metadata' => [
                        'standard' => 'ISO 27001:2022 Cl. 5.24-5.28',
                        'sla_hours' => null,
                        'steps' => [
                            ['name' => 'Schedule Post-Mortem', 'order' => 1, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 5, 'is_required' => true],
                            ['name' => 'Conduct Review', 'order' => 2, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'Incident', 'fields' => ['rootCause', 'lessonsLearned']]],
                            ['name' => 'Create Improvement Actions', 'order' => 3, 'approver_role' => 'ROLE_CISO', 'step_type' => 'approval', 'days_to_complete' => 7, 'is_required' => true, 'auto_progress_conditions' => ['type' => 'field_completion', 'entity' => 'Incident', 'fields' => ['correctiveActions', 'preventiveActions']]],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Build an InMemoryMetadataStore from a plain array.
     */
    private function buildMetadataStore(array $metadata): InMemoryMetadataStore
    {
        return new InMemoryMetadataStore($metadata);
    }
}
