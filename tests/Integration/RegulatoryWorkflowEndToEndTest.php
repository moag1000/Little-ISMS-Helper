<?php

declare(strict_types=1);

namespace App\Tests\Integration;

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
 * Sprint Y.2 — Integration tests for the 5 critical regulatory approval chains.
 *
 * Tests the RegulatoryWorkflowLoader against in-memory stubs of the 5 critical
 * workflows from the spec:
 *   1. gdpr_data_breach (6 steps, 72h SLA, GDPR Art. 33/34)
 *   2. incident_high_severity (6 steps, ISO 27001)
 *   3. risk_treatment (6 steps, risk_appetite auto-progress)
 *   4. dpia (6 steps, GDPR Art. 35/36 conditional logic)
 *   5. dsr (5 steps, GDPR Art. 12-22, 30-day SLA)
 *
 * Each test verifies:
 *  - The workflow loads from the registry without error
 *  - All steps are present with correct metadata
 *  - SLA hours / escalation role are accessible via getRegulatoryMetadata()
 *  - Auto-progress conditions are correctly encoded per entity/field
 *  - The approval-chain SM places match the workflow_instance_lifecycle baseline
 */
final class RegulatoryWorkflowEndToEndTest extends TestCase
{
    private RegulatoryWorkflowLoader $loader;

    protected function setUp(): void
    {
        $this->loader = $this->buildLoader();
    }

    // =========================================================================
    // 1. GDPR Data Breach (gdpr_data_breach)
    // =========================================================================

    #[Test]
    public function gdprDataBreachLoadsWithSixSteps(): void
    {
        $steps = $this->loader->getStepsForWorkflow('gdpr_data_breach');
        $this->assertNotNull($steps);
        $this->assertCount(6, $steps);
    }

    #[Test]
    public function gdprDataBreachHas72HourSla(): void
    {
        $meta = $this->loader->getRegulatoryMetadata('gdpr_data_breach');
        $this->assertNotNull($meta);
        $this->assertSame(72, $meta['sla_hours']);
        $this->assertSame(60, $meta['escalation_threshold_hours']);
    }

    #[Test]
    public function gdprDataBreachStep1IsTheDpoAssessment(): void
    {
        $steps = $this->loader->getStepsForWorkflow('gdpr_data_breach');
        $this->assertNotNull($steps);

        $step1 = $steps[0];
        $this->assertSame('Initial Assessment (DPO)', $step1['name']);
        $this->assertSame('ROLE_DPO', $step1['approver_role']);
        $this->assertSame(1, $step1['days_to_complete']);
        $this->assertTrue($step1['is_required']);
        // S17: a person approves it; filled fields no longer pretend to approve (nothing evaluated them).
        $this->assertArrayNotHasKey('auto_progress_conditions', $step1);
    }

    #[Test]
    public function gdprDataBreachStep3IsNotificationAndOptional(): void
    {
        $steps = $this->loader->getStepsForWorkflow('gdpr_data_breach');
        $this->assertNotNull($steps);

        $step3 = $steps[2];
        $this->assertSame('notification', $step3['step_type']);
        $this->assertFalse($step3['is_required']);
    }

    #[Test]
    public function gdprDataBreachFinalStepIsDpoWithSevenDays(): void
    {
        $steps = $this->loader->getStepsForWorkflow('gdpr_data_breach');
        $this->assertNotNull($steps);

        $lastStep = end($steps);
        $this->assertSame('ROLE_DPO', $lastStep['approver_role']);
        $this->assertSame(7, $lastStep['days_to_complete']);
    }

    // =========================================================================
    // 2. Incident High Severity (incident_high_severity)
    // =========================================================================

    #[Test]
    public function incidentHighSeverityLoadsWithSixSteps(): void
    {
        $steps = $this->loader->getStepsForWorkflow('incident_high_severity');
        $this->assertNotNull($steps);
        $this->assertCount(6, $steps);
    }

    #[Test]
    public function incidentHighSeverityHas24HourSla(): void
    {
        $meta = $this->loader->getRegulatoryMetadata('incident_high_severity');
        $this->assertNotNull($meta);
        $this->assertSame(24, $meta['sla_hours']);
    }

    #[Test]
    public function incidentHighSeverityStep1IsCisoImmediate(): void
    {
        $steps = $this->loader->getStepsForWorkflow('incident_high_severity');
        $this->assertNotNull($steps);

        $step1 = $steps[0];
        $this->assertSame('Immediate Response (CISO)', $step1['name']);
        $this->assertSame('ROLE_CISO', $step1['approver_role']);
        $this->assertSame(0, $step1['days_to_complete']); // immediate
        $this->assertTrue($step1['is_required']);
    }

    #[Test]
    public function incidentHighSeverityAllSixStepsAreRequired(): void
    {
        $steps = $this->loader->getStepsForWorkflow('incident_high_severity');
        $this->assertNotNull($steps);

        foreach ($steps as $index => $step) {
            $this->assertTrue($step['is_required'], "Step {$index} should be required");
        }
    }

    #[Test]
    public function incidentHighSeverityFinalStepIsManagement14Days(): void
    {
        $steps = $this->loader->getStepsForWorkflow('incident_high_severity');
        $this->assertNotNull($steps);

        $lastStep = end($steps);
        $this->assertSame('ROLE_MANAGER', $lastStep['approver_role']);
        $this->assertSame(14, $lastStep['days_to_complete']);
    }

    // =========================================================================
    // 3. Risk Treatment (risk_treatment)
    // =========================================================================

    #[Test]
    public function riskTreatmentLoadsWithSixSteps(): void
    {
        $steps = $this->loader->getStepsForWorkflow('risk_treatment');
        $this->assertNotNull($steps);
        $this->assertCount(6, $steps);
    }

    #[Test]
    public function riskTreatmentStandardIsIso270012022(): void
    {
        $meta = $this->loader->getRegulatoryMetadata('risk_treatment');
        $this->assertNotNull($meta);
        $this->assertStringContainsString('ISO 27001', $meta['standard']);
        $this->assertStringContainsString('6.1.3', $meta['standard']);
    }

    #[Test]
    public function riskTreatmentStep1WaitsOnTheRiskOwner(): void
    {
        $steps = $this->loader->getStepsForWorkflow('risk_treatment');
        $this->assertNotNull($steps);

        $step1 = $steps[0];
        $this->assertSame('Risk Owner Approval', $step1['name']);
        $this->assertSame('owner', $step1['approver_role']);
    }

    #[Test]
    public function riskTreatmentStep6IsAuditNotification(): void
    {
        $steps = $this->loader->getStepsForWorkflow('risk_treatment');
        $this->assertNotNull($steps);

        $lastStep = end($steps);
        $this->assertSame('ROLE_AUDITOR', $lastStep['approver_role']);
        $this->assertSame('notification', $lastStep['step_type']);
        $this->assertFalse($lastStep['is_required']);
    }

    #[Test]
    public function riskTreatmentHasNullSlaHours(): void
    {
        $meta = $this->loader->getRegulatoryMetadata('risk_treatment');
        $this->assertNotNull($meta);
        $this->assertNull($meta['sla_hours']);
    }

    // =========================================================================
    // 4. DPIA (dpia)
    // =========================================================================

    #[Test]
    public function dpiaLoadsWithSixSteps(): void
    {
        $steps = $this->loader->getStepsForWorkflow('dpia');
        $this->assertNotNull($steps);
        $this->assertCount(6, $steps);
    }

    #[Test]
    public function dpiaStandardIsGdprArt35And36(): void
    {
        $meta = $this->loader->getRegulatoryMetadata('dpia');
        $this->assertNotNull($meta);
        $this->assertStringContainsString('GDPR', $meta['standard']);
        $this->assertStringContainsString('35', $meta['standard']);
        $this->assertStringContainsString('36', $meta['standard']);
    }

    #[Test]
    public function dpiaFinalStepIsTheDpo(): void
    {
        $steps = $this->loader->getStepsForWorkflow('dpia');
        $this->assertNotNull($steps);

        $finalStep = end($steps);
        $this->assertSame('ROLE_DPO', $finalStep['approver_role']);
    }

    #[Test]
    public function dpiaAllStepsAreRequired(): void
    {
        $steps = $this->loader->getStepsForWorkflow('dpia');
        $this->assertNotNull($steps);

        foreach ($steps as $index => $step) {
            $this->assertTrue($step['is_required'], "DPIA step {$index} should be required");
        }
    }

    // =========================================================================
    // 5. Data Subject Request (dsr)
    // =========================================================================

    #[Test]
    public function dsrLoadsWithFiveSteps(): void
    {
        $steps = $this->loader->getStepsForWorkflow('dsr');
        $this->assertNotNull($steps);
        $this->assertCount(5, $steps);
    }

    #[Test]
    public function dsrHas720HourSla(): void
    {
        $meta = $this->loader->getRegulatoryMetadata('dsr');
        $this->assertNotNull($meta);
        $this->assertSame(720, $meta['sla_hours']); // 30 days = 720 hours
        $this->assertSame(600, $meta['escalation_threshold_hours']); // 25 days
        $this->assertSame('ROLE_DPO', $meta['escalation_role']);
    }

    #[Test]
    public function dsrStandardIsGdprArt12To22(): void
    {
        $meta = $this->loader->getRegulatoryMetadata('dsr');
        $this->assertNotNull($meta);
        $this->assertStringContainsString('GDPR', $meta['standard']);
        $this->assertStringContainsString('12', $meta['standard']);
    }

    #[Test]
    public function dsrStep1IsIdentityVerificationByTheDpo(): void
    {
        $steps = $this->loader->getStepsForWorkflow('dsr');
        $this->assertNotNull($steps);

        $step1 = $steps[0];
        $this->assertSame('Identity Verification', $step1['name']);
        $this->assertSame('ROLE_DPO', $step1['approver_role']);
        $this->assertSame(3, $step1['days_to_complete']);
    }

    #[Test]
    public function dsrFinalStepIsOptionalExtension(): void
    {
        $steps = $this->loader->getStepsForWorkflow('dsr');
        $this->assertNotNull($steps);

        $lastStep = end($steps);
        $this->assertSame('Deadline Extension (Art. 12(3))', $lastStep['name']);
        $this->assertFalse($lastStep['is_required']);
        $this->assertSame('notification', $lastStep['step_type']);
    }

    // =========================================================================
    // Cross-workflow: all 5 critical workflows use WorkflowInstance-compatible places
    // =========================================================================

    #[Test]
    #[DataProvider('criticalWorkflowNamesProvider')]
    public function workflowIsRegisteredInLoader(string $workflowName): void
    {
        $this->assertTrue($this->loader->isRegistered($workflowName));
    }

    #[Test]
    #[DataProvider('criticalWorkflowNamesProvider')]
    public function workflowHasRegulatoryMetadataWithStandard(string $workflowName): void
    {
        $meta = $this->loader->getRegulatoryMetadata($workflowName);
        $this->assertNotNull($meta, "Workflow '{$workflowName}' should have regulatory metadata");
        $this->assertArrayHasKey('standard', $meta);
        $this->assertNotEmpty($meta['standard']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function criticalWorkflowNamesProvider(): array
    {
        return [
            'gdpr_data_breach' => ['gdpr_data_breach'],
            'incident_high_severity' => ['incident_high_severity'],
            'risk_treatment' => ['risk_treatment'],
            'dpia' => ['dpia'],
            'dsr' => ['dsr'],
        ];
    }

    // =========================================================================
    // Internal builder
    // =========================================================================

    private function buildLoader(): RegulatoryWorkflowLoader
    {
        $registry = new Registry();
        $places = ['pending', 'in_progress', 'approved', 'rejected', 'cancelled'];
        $transitions = [
            new Transition('start', 'pending', 'in_progress'),
            new Transition('approve', 'in_progress', 'approved'),
            new Transition('reject', 'in_progress', 'rejected'),
            new Transition('cancel', 'pending', 'cancelled'),
            new Transition('cancel_in_progress', 'in_progress', 'cancelled'),
        ];

        foreach ($this->getCriticalWorkflowMetadata() as $name => $metadata) {
            $metadataStore = new InMemoryMetadataStore($metadata);
            $definition = new Definition($places, $transitions, 'pending', $metadataStore);
            $workflow = new StateMachine($definition, null, null, $name);
            $registry->addWorkflow($workflow, new InstanceOfSupportStrategy(WorkflowInstance::class));
        }

        return new RegulatoryWorkflowLoader($registry);
    }

    /**
     * Metadata of the five critical chains straight from config/workflows/regulatory (S17).
     *
     * @return array<string, array<string, mixed>>
     */
    private function getCriticalWorkflowMetadata(): array
    {
        $metadata = [];
        foreach (array_column(self::criticalWorkflowNamesProvider(), 0) as $name) {
            $file = \dirname(__DIR__, 2) . '/config/workflows/regulatory/' . $name . '.yaml';
            $metadata[$name] = Yaml::parseFile($file)['framework']['workflows'][$name]['metadata'];
        }

        return $metadata;
    }
}
