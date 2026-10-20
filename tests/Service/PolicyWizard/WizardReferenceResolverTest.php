<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
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
        $tenant = new Tenant();
        $persons->expects(self::once())->method('findBy')->with(['id' => [7, 8], 'tenant' => $tenant])->willReturn([$named, $blank]);
        $resolver = new WizardReferenceResolver($this->createMock(UserRepository::class), $persons);

        self::assertSame([7 => 'Ada Lovelace', 8 => 'Person #8'], $resolver->resolvePersonDisplays([7, 8], $tenant));
        self::assertSame([], $resolver->resolveUserDisplays([], $tenant));
    }

    #[Test]
    public function resolvesOnlyUsersOfTheRunOrganisation(): void
    {
        $tenant = new Tenant();
        $own = $this->createMock(User::class);
        $own->method('getId')->willReturn(3);
        $own->method('getFullName')->willReturn('Ada Own');
        $own->method('getEmail')->willReturn('ada@example.com');

        $users = $this->createMock(UserRepository::class);
        // The tenant criterion is part of the query, so a foreign id (4) is never hydrated.
        $users->expects(self::once())->method('findBy')->with(['id' => [3, 4], 'tenant' => $tenant])->willReturn([$own]);
        $resolver = new WizardReferenceResolver($users, $this->createMock(PersonRepository::class));

        self::assertSame([3 => 'Ada Own <ada@example.com>'], $resolver->resolveUserDisplays([3, 4], $tenant));
    }

    #[Test]
    public function resolvesNothingWithoutAnOrganisation(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('findBy');
        $persons = $this->createMock(PersonRepository::class);
        $persons->expects(self::never())->method('findBy');
        $resolver = new WizardReferenceResolver($users, $persons);

        self::assertSame([], $resolver->resolveUserDisplays([3], null));
        self::assertSame([], $resolver->resolvePersonDisplays([7], null));
    }
}
