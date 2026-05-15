<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\WizardRunRepository;
use App\Service\PolicyWizard\AnnexAApplicabilityApplierInterface;
use App\Service\PolicyWizard\DocumentGeneratorStub;
use App\Service\PolicyWizard\HierarchyOverrideValidator;
use App\Service\PolicyWizard\Step\LifecycleStep;
use App\Service\PolicyWizard\Step\OperationalBaselinesStep;
use App\Service\PolicyWizard\Step\OrganisationScopeStep;
use App\Service\PolicyWizard\Step\ReviewGenerateStep;
use App\Service\PolicyWizard\Step\RiskClassificationStep;
use App\Service\PolicyWizard\Step\RolesStep;
use App\Service\PolicyWizard\Step\TargetedDiffPreviewStep;
use App\Service\PolicyWizard\Step\TargetedFindingReferenceStep;
use App\Service\PolicyWizard\Step\TargetedGenerateStep;
use App\Service\PolicyWizard\Step\TargetedPickTopicsStep;
use App\Service\PolicyWizard\Step\WelcomeStandardsStep;
use App\Service\PolicyWizard\StepEvaluator;
use App\Service\PolicyWizard\WizardOrchestrator;
use App\Service\PolicyWizard\WizardStepKeys;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration test — verifies that WizardOrchestrator calls
 * AnnexAApplicabilityApplierInterface when STEP_RISK_CLASSIFICATION is processed
 * and the applicability map is non-empty.
 */
#[AllowMockObjectsWithoutExpectations]
final class WizardOrchestratorAnnexAHookTest extends TestCase
{
    private function makeStepEvaluator(): StepEvaluator
    {
        return new StepEvaluator([
            new WelcomeStandardsStep(),
            new OrganisationScopeStep(),
            new RolesStep(),
            new RiskClassificationStep(),
            new OperationalBaselinesStep(),
            new LifecycleStep(),
            new ReviewGenerateStep(),
            new TargetedPickTopicsStep(),
            new TargetedFindingReferenceStep(),
            new TargetedDiffPreviewStep(),
            new TargetedGenerateStep(),
        ]);
    }

    private function makeTenant(int $id = 1): Tenant
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getAllAncestors')->willReturn([]);
        return $tenant;
    }

    private function makeUser(): User
    {
        $u = $this->createStub(User::class);
        $u->method('getId')->willReturn(99);
        return $u;
    }

    /**
     * Build a WizardRun already past the STEP_WELCOME+STEP_ORGANISATION_SCOPE
     * +STEP_ROLES steps so it sits at STEP_RISK_CLASSIFICATION.
     */
    private function makeRunAtRiskClassification(Tenant $tenant): WizardRun
    {
        $run = new WizardRun();
        $run->setTenant($tenant);
        $run->setMode(WizardStepKeys::MODE_FULL);
        $run->setStatus(WizardStepKeys::STATUS_IN_PROGRESS);
        $run->setStep(WizardStepKeys::STEP_RISK_CLASSIFICATION);
        $run->setStandardsAdopted(['iso27001']);
        $run->setInputs([
            WizardStepKeys::STEP_WELCOME => [
                'standards_adopted' => ['iso27001'],
            ],
        ]);
        return $run;
    }

    #[Test]
    public function processStep_callsApplierWhenRiskClassificationStepSubmitted(): void
    {
        $tenant = $this->makeTenant();
        $run = $this->makeRunAtRiskClassification($tenant);

        $applier = $this->createMock(AnnexAApplicabilityApplierInterface::class);
        $applier->expects(self::once())
            ->method('applyToTenant')
            ->with($tenant, ['5.1' => true, '5.2' => false])
            ->willReturn(['updated' => 1, 'not_found' => 0]);

        $validator = $this->createStub(HierarchyOverrideValidator::class);
        $validator->method('validate')->willReturn([]);

        $orchestrator = new WizardOrchestrator(
            entityManager: $this->createStub(EntityManagerInterface::class),
            wizardRunRepository: $this->createStub(WizardRunRepository::class),
            stepEvaluator: $this->makeStepEvaluator(),
            documentGenerator: new DocumentGeneratorStub(),
            hierarchyValidator: $validator,
            annexAApplicabilityApplier: $applier,
        );

        $orchestrator->processStep($run, WizardStepKeys::STEP_RISK_CLASSIFICATION, [
            'risk_appetite_tier' => 3,
            'annex_a_applicability' => ['5.1' => true, '5.2' => false],
        ]);
    }

    #[Test]
    public function processStep_doesNotCallApplierWhenMapIsEmpty(): void
    {
        $tenant = $this->makeTenant();
        $run = $this->makeRunAtRiskClassification($tenant);

        $applier = $this->createMock(AnnexAApplicabilityApplierInterface::class);
        $applier->expects(self::never())->method('applyToTenant');

        $validator = $this->createStub(HierarchyOverrideValidator::class);
        $validator->method('validate')->willReturn([]);

        $orchestrator = new WizardOrchestrator(
            entityManager: $this->createStub(EntityManagerInterface::class),
            wizardRunRepository: $this->createStub(WizardRunRepository::class),
            stepEvaluator: $this->makeStepEvaluator(),
            documentGenerator: new DocumentGeneratorStub(),
            hierarchyValidator: $validator,
            annexAApplicabilityApplier: $applier,
        );

        $orchestrator->processStep($run, WizardStepKeys::STEP_RISK_CLASSIFICATION, [
            'risk_appetite_tier' => 3,
            // annex_a_applicability absent → empty map → applier not called
        ]);
    }

    #[Test]
    public function processStep_worksWithoutApplierWired(): void
    {
        // Ensures backward-compat: orchestrator with null applier does not break.
        $tenant = $this->makeTenant();
        $run = $this->makeRunAtRiskClassification($tenant);

        $validator = $this->createStub(HierarchyOverrideValidator::class);
        $validator->method('validate')->willReturn([]);

        $orchestrator = new WizardOrchestrator(
            entityManager: $this->createStub(EntityManagerInterface::class),
            wizardRunRepository: $this->createStub(WizardRunRepository::class),
            stepEvaluator: $this->makeStepEvaluator(),
            documentGenerator: new DocumentGeneratorStub(),
            hierarchyValidator: $validator,
            // annexAApplicabilityApplier not passed → null
        );

        // Should complete without exception.
        $orchestrator->processStep($run, WizardStepKeys::STEP_RISK_CLASSIFICATION, [
            'risk_appetite_tier' => 3,
            'annex_a_applicability' => ['5.1' => true],
        ]);

        self::assertTrue(true); // If we get here, backward-compat is intact.
    }
}
