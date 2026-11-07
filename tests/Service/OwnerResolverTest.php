<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Person;
use App\Entity\User;
use App\Service\OwnerResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OwnerResolverTest extends TestCase
{
    #[Test]
    public function userTakesPrecedenceOverPerson(): void
    {
        $user = (new User())->setFirstName('Alice')->setLastName('A.');
        $person = (new Person())->setFullName('Bob B.');

        $this->assertSame('Alice A.', OwnerResolver::resolveEffective($user, $person));
    }

    #[Test]
    public function personUsedWhenNoUser(): void
    {
        $person = (new Person())->setFullName('Bob B.');
        $this->assertSame('Bob B.', OwnerResolver::resolveEffective(null, $person));
    }

    #[Test]
    public function returnsNullWhenNothingProvided(): void
    {
        $this->assertNull(OwnerResolver::resolveEffective(null, null));
    }

    #[Test]
    public function aggregateAllReturnsPrimaryThenDeputies(): void
    {
        $primaryUser = (new User())->setFirstName('Alice')->setLastName('A.');
        $deputy1 = (new Person())->setFullName('Bob B.');
        $deputy2 = (new Person())->setFullName('Carol C.');

        $names = OwnerResolver::resolveAll($primaryUser, null, [$deputy1, $deputy2]);
        $this->assertSame(['Alice A.', 'Bob B.', 'Carol C.'], $names);
    }

    #[Test]
    public function aggregateAllSkipsEmptyPrimary(): void
    {
        $deputy = (new Person())->setFullName('Bob B.');
        $this->assertSame(['Bob B.'], OwnerResolver::resolveAll(null, null, [$deputy]));
    }

    #[Test]
    public function aggregateAllReturnsEmptyArrayWhenNothing(): void
    {
        $this->assertSame([], OwnerResolver::resolveAll(null, null, []));
    }

    #[Test]
    public function recipientPrefersTheUserThenThePersonsAccountOrAddress(): void
    {
        $user = (new User())->setEmail('u@x.test');
        $linked = (new User())->setEmail('linked@x.test');
        $this->assertSame($user, OwnerResolver::recipient($user, (new Person())->setEmail('p@x.test')));
        $this->assertSame($linked, OwnerResolver::recipient(null, (new Person())->setLinkedUser($linked)));
        $this->assertSame('p@x.test', OwnerResolver::recipient(null, (new Person())->setEmail(' p@x.test ')));
    }

    #[Test]
    public function recipientIsNullForAPersonWithoutAccountOrAddress(): void
    {
        $this->assertNull(OwnerResolver::recipient(null, (new Person())->setFullName('No Mail')));
        $this->assertNull(OwnerResolver::recipient(null, null));
    }
}
