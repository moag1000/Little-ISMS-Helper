<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Person;
use App\Repository\PersonRepository;
use App\Repository\UserRepository;
use App\Service\PolicyWizard\WizardReferenceResolver;
use App\Service\PolicyWizard\WizardStepKeys;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class WizardReferenceResolverTest extends TestCase
{
    #[Test]
    public function collectsDeduplicatedUserAndPersonIdsFromTheInputs(): void
    {
        $resolver = new WizardReferenceResolver(
            $this->createMock(UserRepository::class),
            $this->createMock(PersonRepository::class),
        );
        $inputs = [
            WizardStepKeys::STEP_ROLES => [
                'approval_chain' => [3, 4, 'x', 0],
                'roles' => ['ciso' => 7],
                'function_owners' => ['hr' => 7, 'it' => 8],
            ],
            WizardStepKeys::STEP_OPERATIONAL_BASELINES => ['dpo_user_id' => 4, 'bcm_officer_user_id' => 5],
            WizardStepKeys::STEP_LIFECYCLE => ['default_approver_user_id' => 6, 'approver_per_template' => ['a' => 3]],
        ];

        self::assertSame([3, 4, 5, 6], $resolver->collectReferencedUserIds($inputs));
        self::assertSame([7, 8], $resolver->collectReferencedPersonIds($inputs));
    }

    #[Test]
    public function resolvesPersonsInOneQueryWithAPlaceholderForBlankNames(): void
    {
        $named = $this->createMock(Person::class);
        $named->method('getId')->willReturn(7);
        $named->method('getFullName')->willReturn('Ada Lovelace');
        $blank = $this->createMock(Person::class);
        $blank->method('getId')->willReturn(8);
        $blank->method('getFullName')->willReturn('  ');

        $persons = $this->createMock(PersonRepository::class);
        $persons->expects(self::once())->method('findBy')->with(['id' => [7, 8]])->willReturn([$named, $blank]);
        $resolver = new WizardReferenceResolver($this->createMock(UserRepository::class), $persons);

        self::assertSame([7 => 'Ada Lovelace', 8 => 'Person #8'], $resolver->resolvePersonDisplays([7, 8]));
        self::assertSame([], $resolver->resolveUserDisplays([]));
    }
}
