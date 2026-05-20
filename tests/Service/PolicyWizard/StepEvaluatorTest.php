<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\WizardRun;
use App\Exception\InvalidArgument\InvalidArgumentException as AppInvalidArgumentException;
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
use App\Service\PolicyWizard\WizardStepKeys;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W2-A — flow / mode-branch evaluator tests.
 */
final class StepEvaluatorTest extends TestCase
{
    private function makeEvaluator(): StepEvaluator
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

    private function makeRun(string $mode, string $currentStep): WizardRun
    {
        $run = new WizardRun();
        $run->setMode($mode);
        $run->setStep($currentStep);
        return $run;
    }

    #[Test]
    public function fullModeAdvancesThroughDefaultFlowFromWelcomeToOrgScope(): void
    {
        $evaluator = $this->makeEvaluator();
        $run = $this->makeRun(WizardStepKeys::MODE_FULL, WizardStepKeys::STEP_WELCOME);

        self::assertSame(WizardStepKeys::STEP_ORG_SCOPE, $evaluator->nextStepFor($run));
    }

    #[Test]
    public function fullModeAdvancesAcrossEverySevenSteps(): void
    {
        $evaluator = $this->makeEvaluator();
        $expected = [
            WizardStepKeys::STEP_WELCOME => WizardStepKeys::STEP_ORG_SCOPE,
            WizardStepKeys::STEP_ORG_SCOPE => WizardStepKeys::STEP_ROLES,
            WizardStepKeys::STEP_ROLES => WizardStepKeys::STEP_RISK_CLASSIFICATION,
            WizardStepKeys::STEP_RISK_CLASSIFICATION => WizardStepKeys::STEP_OPERATIONAL_BASELINES,
            WizardStepKeys::STEP_OPERATIONAL_BASELINES => WizardStepKeys::STEP_LIFECYCLE,
            WizardStepKeys::STEP_LIFECYCLE => WizardStepKeys::STEP_REVIEW_GENERATE,
            WizardStepKeys::STEP_REVIEW_GENERATE => null,
        ];

        foreach ($expected as $current => $next) {
            $run = $this->makeRun(WizardStepKeys::MODE_FULL, $current);
            self::assertSame(
                $next,
                $evaluator->nextStepFor($run),
                sprintf('Full mode: after "%s" expected "%s".', $current, $next ?? 'null'),
            );
        }
    }

    #[Test]
    public function targetedModeSkipsDefaultStepsAndUsesTargetedSubFlow(): void
    {
        $evaluator = $this->makeEvaluator();
        $run = $this->makeRun(WizardStepKeys::MODE_TARGETED, WizardStepKeys::STEP_WELCOME);

        self::assertSame(WizardStepKeys::STEP_TARGETED_PICK, $evaluator->nextStepFor($run));

        $run->setStep(WizardStepKeys::STEP_TARGETED_PICK);
        self::assertSame(WizardStepKeys::STEP_TARGETED_FINDING, $evaluator->nextStepFor($run));

        $run->setStep(WizardStepKeys::STEP_TARGETED_FINDING);
        self::assertSame(WizardStepKeys::STEP_TARGETED_DIFF, $evaluator->nextStepFor($run));

        $run->setStep(WizardStepKeys::STEP_TARGETED_DIFF);
        self::assertSame(WizardStepKeys::STEP_TARGETED_GENERATE, $evaluator->nextStepFor($run));

        $run->setStep(WizardStepKeys::STEP_TARGETED_GENERATE);
        self::assertNull($evaluator->nextStepFor($run));
    }

    #[Test]
    public function sandboxModeFollowsDefaultFlow(): void
    {
        $evaluator = $this->makeEvaluator();
        $run = $this->makeRun(WizardStepKeys::MODE_SANDBOX, WizardStepKeys::STEP_WELCOME);

        // Sandbox mode == full mode flow: only persistence side differs.
        self::assertSame(WizardStepKeys::STEP_ORG_SCOPE, $evaluator->nextStepFor($run));

        $run->setStep(WizardStepKeys::STEP_LIFECYCLE);
        self::assertSame(WizardStepKeys::STEP_REVIEW_GENERATE, $evaluator->nextStepFor($run));
    }

    #[Test]
    public function isTerminalStepDetectsLastStepInFlow(): void
    {
        $evaluator = $this->makeEvaluator();

        $full = $this->makeRun(WizardStepKeys::MODE_FULL, WizardStepKeys::STEP_REVIEW_GENERATE);
        self::assertTrue($evaluator->isTerminalStep($full, WizardStepKeys::STEP_REVIEW_GENERATE));
        self::assertFalse($evaluator->isTerminalStep($full, WizardStepKeys::STEP_LIFECYCLE));

        $targeted = $this->makeRun(WizardStepKeys::MODE_TARGETED, WizardStepKeys::STEP_TARGETED_GENERATE);
        self::assertTrue($evaluator->isTerminalStep($targeted, WizardStepKeys::STEP_TARGETED_GENERATE));
        self::assertFalse($evaluator->isTerminalStep($targeted, WizardStepKeys::STEP_TARGETED_PICK));
    }

    #[Test]
    public function getStepThrowsForUnknownKey(): void
    {
        $evaluator = $this->makeEvaluator();
        $this->expectException(AppInvalidArgumentException::class);
        $evaluator->getStep('bogus_step_key');
    }

    #[Test]
    public function firstStepForFullModeIsWelcome(): void
    {
        $evaluator = $this->makeEvaluator();
        $run = $this->makeRun(WizardStepKeys::MODE_FULL, '');
        self::assertSame(WizardStepKeys::STEP_WELCOME, $evaluator->firstStepFor($run));
    }
}
