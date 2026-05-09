<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\WizardRunRepository;
use App\Service\PolicyWizard\DocumentGeneratorInterface;
use App\Service\PolicyWizard\DocumentGeneratorStub;
use App\Service\PolicyWizard\HierarchyConflictException;
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
use App\Service\PolicyWizard\StepValidationException;
use App\Service\PolicyWizard\WizardOrchestrator;
use App\Service\PolicyWizard\WizardStepKeys;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W2-A — orchestrator façade tests.
 *
 * Covers start / resume / processStep / complete / cancel plus the
 * stub-generator swallowing path and hierarchy-conflict gating.
 */
#[AllowMockObjectsWithoutExpectations]
final class WizardOrchestratorTest extends TestCase
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

    private function makeOrchestrator(
        ?EntityManagerInterface $em = null,
        ?DocumentGeneratorInterface $generator = null,
        ?HierarchyOverrideValidator $validator = null,
    ): WizardOrchestrator {
        $em ??= $this->createMock(EntityManagerInterface::class);
        $repository = $this->createStub(WizardRunRepository::class);
        $generator ??= new DocumentGeneratorStub();
        $validator ??= $this->makeNullValidator();

        return new WizardOrchestrator(
            $em,
            $repository,
            $this->makeStepEvaluator(),
            $generator,
            $validator,
        );
    }

    private function makeNullValidator(): HierarchyOverrideValidator
    {
        $stub = $this->createStub(HierarchyOverrideValidator::class);
        $stub->method('validate')->willReturn([]);
        return $stub;
    }

    private function makeTenant(int $id = 1): Tenant
    {
        $t = $this->createStub(Tenant::class);
        $t->method('getId')->willReturn($id);
        $t->method('getAllAncestors')->willReturn([]);
        return $t;
    }

    private function makeUser(int $id = 99): User
    {
        $u = $this->createStub(User::class);
        $u->method('getId')->willReturn($id);
        return $u;
    }

    #[Test]
    public function startCreatesInProgressRunWithFirstStepPointer(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $orchestrator = $this->makeOrchestrator($em);
        $run = $orchestrator->start($this->makeTenant(), $this->makeUser(), ['iso27001']);

        self::assertSame(WizardStepKeys::MODE_FULL, $run->getMode());
        self::assertSame(WizardStepKeys::STATUS_IN_PROGRESS, $run->getStatus());
        self::assertSame(WizardStepKeys::STEP_WELCOME, $run->getStep());
        self::assertSame(['iso27001'], $run->getStandardsAdopted());
    }

    #[Test]
    public function startInSandboxModeFlipsStatusToSandbox(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $run = $orchestrator->start(
            $this->makeTenant(),
            $this->makeUser(),
            ['iso27001'],
            WizardStepKeys::MODE_SANDBOX,
        );
        self::assertSame(WizardStepKeys::STATUS_SANDBOX, $run->getStatus());
        self::assertSame(WizardStepKeys::MODE_SANDBOX, $run->getMode());
    }

    #[Test]
    public function startInTargetedModeStartsAtWelcomeAndPicksTopicsNext(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $run = $orchestrator->start(
            $this->makeTenant(),
            $this->makeUser(),
            ['iso27001'],
            WizardStepKeys::MODE_TARGETED,
            'NCR-2026-04',
        );
        self::assertSame(WizardStepKeys::STEP_WELCOME, $run->getStep());
        self::assertSame('NCR-2026-04', $run->getFindingReference());
    }

    #[Test]
    public function startRejectsUnknownMode(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $this->expectException(\InvalidArgumentException::class);
        $orchestrator->start($this->makeTenant(), $this->makeUser(), null, 'bogus_mode');
    }

    #[Test]
    public function processStepValidatesPersistsAndAdvancesPointer(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $run = $orchestrator->start($this->makeTenant(), $this->makeUser(), ['iso27001']);

        $orchestrator->processStep($run, WizardStepKeys::STEP_WELCOME, [
            'standards' => ['iso27001'],
            'mode' => WizardStepKeys::MODE_FULL,
        ]);

        self::assertSame(WizardStepKeys::STEP_ORG_SCOPE, $run->getStep());
        self::assertSame(['iso27001'], $run->getStandardsAdopted());
        $persistedSlot = $run->getInputs()[WizardStepKeys::STEP_WELCOME] ?? null;
        self::assertIsArray($persistedSlot);
        self::assertSame(['iso27001'], $persistedSlot['standards']);
    }

    #[Test]
    public function processStepThrowsValidationExceptionWhenInputInvalid(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $run = $orchestrator->start($this->makeTenant(), $this->makeUser(), ['iso27001']);

        $this->expectException(StepValidationException::class);
        $orchestrator->processStep($run, WizardStepKeys::STEP_WELCOME, [
            'standards' => [], // empty → required violation
            'mode' => WizardStepKeys::MODE_FULL,
        ]);
    }

    #[Test]
    public function processStepRejectsMismatchedStepKey(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $run = $orchestrator->start($this->makeTenant(), $this->makeUser(), ['iso27001']);

        $this->expectException(\InvalidArgumentException::class);
        $orchestrator->processStep($run, WizardStepKeys::STEP_ORG_SCOPE, []);
    }

    #[Test]
    public function resumeReturnsCurrentStepPointerAndDefaults(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $run = $orchestrator->start($this->makeTenant(), $this->makeUser(), ['iso27001']);

        $resume = $orchestrator->resume($run);
        self::assertSame(WizardStepKeys::STEP_WELCOME, $resume['next_step']);
        self::assertSame(WizardStepKeys::MODE_FULL, $resume['data']['mode']);
        self::assertSame(['iso27001'], $resume['data']['standards']);
    }

    #[Test]
    public function completeWithStubGeneratorReturnsEmptyDocumentIds(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $run = $orchestrator->start($this->makeTenant(), $this->makeUser(), ['iso27001']);

        $result = $orchestrator->complete($run);

        self::assertSame([], $result['document_ids'], 'W2 stub must yield empty document_ids.');
        self::assertSame($run, $result['wizard_run']);
        self::assertSame(WizardStepKeys::STATUS_COMPLETED, $run->getStatus());
        self::assertNotNull($run->getCompletedAt());
    }

    #[Test]
    public function completeBlocksOnHierarchyConflicts(): void
    {
        $blockingValidator = $this->createStub(HierarchyOverrideValidator::class);
        $blockingValidator->method('validate')->willReturn([[
            'key' => 'risk.appetite_tier',
            'parent_value' => 2,
            'child_value' => 5,
            'mode' => \App\Service\TenantSettingResolver\OverrideMode::CeilingOnly,
            'message' => 'policy_wizard.error.hierarchy.ceiling_only',
        ]]);
        $orchestrator = $this->makeOrchestrator(validator: $blockingValidator);
        $run = $orchestrator->start($this->makeTenant(), $this->makeUser(), ['iso27001']);

        $this->expectException(HierarchyConflictException::class);
        $orchestrator->complete($run);
    }

    #[Test]
    public function cancelDeletesRunWhenNoDocumentsPersisted(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist'); // start
        $em->expects(self::once())->method('remove');  // cancel deletes
        $em->expects(self::exactly(2))->method('flush');

        $orchestrator = $this->makeOrchestrator($em);
        $run = $orchestrator->start($this->makeTenant(), $this->makeUser(), ['iso27001']);

        $orchestrator->cancel($run);
        self::assertSame(WizardStepKeys::STATUS_CANCELLED, $run->getStatus());
    }

    #[Test]
    public function cancelKeepsRunWhenDocumentsArePersisted(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::never())->method('remove');
        $em->expects(self::exactly(2))->method('flush');

        $orchestrator = $this->makeOrchestrator($em);
        $run = $orchestrator->start($this->makeTenant(), $this->makeUser(), ['iso27001']);
        $run->setGeneratedDocumentIds([42, 43]);

        $orchestrator->cancel($run);
        self::assertSame(WizardStepKeys::STATUS_CANCELLED, $run->getStatus());
        self::assertSame([42, 43], $run->getGeneratedDocumentIds());
    }
}
