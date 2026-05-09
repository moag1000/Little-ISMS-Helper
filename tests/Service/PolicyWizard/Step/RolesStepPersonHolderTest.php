<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Step;

use App\Entity\Person;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\PersonRepository;
use App\Service\PolicyWizard\Step\RolesStep;
use App\Service\PolicyWizard\WizardStepKeys;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Person-Rollout (2026-05-08) — RolesStep now stores Person.id (not
 * User.id) for the four governance roles + the six function-owner
 * slots. Approval-chain stays User.id.
 *
 * These tests focus on the new validator behaviour:
 *   - happy path with Person ids
 *   - graceful no-repo fallback (unit-test friendly)
 *   - backward-compat: legacy User.id is auto-resolved to its
 *     linked Person.id when the User has a Person profile
 *   - approval-chain still rejects self-approval and requires a User
 *     pool (unchanged from W2-A behaviour)
 */
final class RolesStepPersonHolderTest extends TestCase
{
    private function makeRun(int $authorId = 99, array $standards = ['iso27001']): WizardRun
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
    public function validatorAcceptsPersonIdsForGovernanceRoles(): void
    {
        $repo = $this->createMock(PersonRepository::class);
        $repo->method('find')->willReturnCallback(fn (int $id): ?Person => $this->makePerson($id));

        $step = new RolesStep($repo);

        $result = $step->validate($this->makeRun(), [
            'roles' => ['ciso' => 101, 'dpo' => 102, 'isb' => 103],
            'function_owners' => ['sales' => 201, 'rnd' => null],
            'approval_chain' => [55],
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame(101, $result['normalised_input']['roles']['ciso']);
        self::assertSame(102, $result['normalised_input']['roles']['dpo']);
        self::assertSame(201, $result['normalised_input']['function_owners']['sales']);
    }

    #[Test]
    public function validatorWorksWithoutRepository(): void
    {
        // Pure unit-test harness: no PersonRepository wired. The
        // validator must still accept any positive integer id.
        $step = new RolesStep();

        $result = $step->validate($this->makeRun(), [
            'roles' => ['ciso' => 11, 'dpo' => 12],
            'function_owners' => [],
            'approval_chain' => [13],
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame(11, $result['normalised_input']['roles']['ciso']);
    }

    #[Test]
    public function legacyUserIdIsAutoResolvedToLinkedPersonId(): void
    {
        $linkedPerson = $this->makePerson(999);
        $repo = $this->createMock(PersonRepository::class);
        // First lookup: id 77 is not a Person → repository returns null.
        $repo->method('find')->willReturn(null);
        // Backwards-compat lookup: 77 is a User.id with a linked Person.
        $repo->method('findOneByLinkedUserId')->willReturn($linkedPerson);

        $step = new RolesStep($repo);

        $result = $step->validate($this->makeRun(), [
            'roles' => ['ciso' => 77, 'dpo' => 88],
            'function_owners' => [],
            'approval_chain' => [99],
        ]);

        self::assertSame(999, $result['normalised_input']['roles']['ciso']);
    }

    #[Test]
    public function approvalChainStaysUserIdAndRejectsSelfApproval(): void
    {
        $step = new RolesStep();

        $result = $step->validate($this->makeRun(authorId: 77), [
            'roles' => ['ciso' => 11, 'dpo' => 12],
            'function_owners' => [],
            'approval_chain' => [77, 88],
        ]);

        self::assertContains(
            'policy_wizard.error.self_approval_forbidden',
            $result['errors']['approval_chain'] ?? [],
            'Self-approval guard must reject the wizard starter (User-id) in the approval chain.',
        );
        // The chain itself must still be User.id integers, unchanged.
        self::assertContains(77, $result['normalised_input']['approval_chain']);
    }

    #[Test]
    public function bcmOfficerStaysRequiredWhenBcmInScope(): void
    {
        $step = new RolesStep();

        $missingBcm = $step->validate(
            $this->makeRun(standards: ['iso27001', 'bcm']),
            [
                'roles' => ['ciso' => 101, 'dpo' => 102],
                'function_owners' => [],
                'approval_chain' => [201],
            ],
        );

        self::assertContains(
            'policy_wizard.error.role_required.bcm_officer',
            $missingBcm['errors']['roles'] ?? [],
        );

        $withBcm = $step->validate(
            $this->makeRun(standards: ['iso27001', 'bcm']),
            [
                'roles' => ['ciso' => 101, 'dpo' => 102, 'bcm_officer' => 103],
                'function_owners' => [],
                'approval_chain' => [201],
            ],
        );
        self::assertSame([], $withBcm['errors']);
    }

    private function makePerson(int $id): Person
    {
        $stub = $this->createStub(Person::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getFullName')->willReturn('Person ' . $id);
        return $stub;
    }
}
