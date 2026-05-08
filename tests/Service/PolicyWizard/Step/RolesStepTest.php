<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Step;

use App\Entity\User;
use App\Entity\WizardRun;
use App\Service\PolicyWizard\Step\RolesStep;
use App\Service\PolicyWizard\WizardStepKeys;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W2-A — RolesStep tests.
 *
 * Exercises the function-owner role-slot (P1 Risk-Owner) plus the
 * required-roles + self-approval guards.
 */
final class RolesStepTest extends TestCase
{
    private function makeRun(?int $authorId = 99, array $standards = ['iso27001']): WizardRun
    {
        $author = $this->createStub(User::class);
        $author->method('getId')->willReturn($authorId);

        $run = new WizardRun();
        $run->setStartedByUser($author);
        $run->setStandardsAdopted($standards);
        $run->setStep(WizardStepKeys::STEP_ROLES);
        $run->setMode(WizardStepKeys::MODE_FULL);
        return $run;
    }

    #[Test]
    public function functionOwnerSlotsHoistedOntoAffectedFunctions(): void
    {
        $step = new RolesStep();
        $run = $this->makeRun();

        $input = [
            'roles' => ['ciso' => 11, 'dpo' => 12],
            'function_owners' => [
                'sales' => 21,
                'hr' => 22,
                'rnd' => null, // null = unassigned, must NOT be hoisted
            ],
            'approval_chain' => [13],
        ];

        $result = $step->validate($run, $input);
        self::assertSame([], $result['errors'], 'Validate should pass with valid roles + chain.');

        $step->persist($run, $result['normalised_input']);

        $affected = $run->getAffectedFunctions();
        self::assertSame(['sales', 'hr'], $affected, 'Only assigned function-owners must land in affectedFunctions.');

        $persistedSlot = $run->getInputs()[WizardStepKeys::STEP_ROLES] ?? null;
        self::assertIsArray($persistedSlot);
        self::assertSame(21, $persistedSlot['function_owners']['sales']);
    }

    #[Test]
    public function selfApprovalGuardBlocksAuthorInApprovalChain(): void
    {
        $step = new RolesStep();
        $run = $this->makeRun(authorId: 77);

        $result = $step->validate($run, [
            'roles' => ['ciso' => 11, 'dpo' => 12],
            'function_owners' => [],
            'approval_chain' => [77, 88], // 77 == author
        ]);

        self::assertNotEmpty(
            $result['errors']['approval_chain'] ?? [],
            'Self-approval guard must reject author in approval chain (Junior P1).',
        );
        self::assertContains(
            'policy_wizard.error.self_approval_forbidden',
            $result['errors']['approval_chain'],
        );
    }

    #[Test]
    public function bcmOfficerRequiredOnlyWhenBcmInScope(): void
    {
        $step = new RolesStep();

        // No BCM in scope → bcm_officer NOT required.
        $runWithoutBcm = $this->makeRun(standards: ['iso27001']);
        $resultNoBcm = $step->validate($runWithoutBcm, [
            'roles' => ['ciso' => 11, 'dpo' => 12],
            'function_owners' => [],
            'approval_chain' => [13],
        ]);
        self::assertSame([], $resultNoBcm['errors'], 'BCM officer NOT required when BCM not adopted.');

        // BCM in scope, missing officer → error.
        $runWithBcm = $this->makeRun(standards: ['iso27001', 'bcm']);
        $resultMissingBcm = $step->validate($runWithBcm, [
            'roles' => ['ciso' => 11, 'dpo' => 12],
            'function_owners' => [],
            'approval_chain' => [13],
        ]);
        self::assertNotEmpty(
            $resultMissingBcm['errors']['roles'] ?? [],
            'BCM officer required when BCM is adopted.',
        );
        self::assertContains(
            'policy_wizard.error.role_required.bcm_officer',
            $resultMissingBcm['errors']['roles'],
        );

        // BCM in scope, officer assigned → no error.
        $resultWithBcm = $step->validate($runWithBcm, [
            'roles' => ['ciso' => 11, 'dpo' => 12, 'bcm_officer' => 14],
            'function_owners' => [],
            'approval_chain' => [13],
        ]);
        self::assertSame([], $resultWithBcm['errors']);
    }
}
