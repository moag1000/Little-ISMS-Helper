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
    private OwnerResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new OwnerResolver();
    }

    #[Test]
    public function userTakesPrecedenceOverPersonAndLegacy(): void
    {
        $user = (new User())->setFirstName('Alice')->setLastName('A.');
        $person = (new Person())->setFullName('Bob B.');
        $legacy = 'Carol C.';

        $this->assertSame('Alice A.', $this->resolver->resolveEffective($user, $person, $legacy));
    }

    #[Test]
    public function personUsedWhenNoUser(): void
    {
        $person = (new Person())->setFullName('Bob B.');
        $this->assertSame('Bob B.', $this->resolver->resolveEffective(null, $person, 'Carol'));
    }

    #[Test]
    public function legacyUsedWhenNoUserOrPerson(): void
    {
        $this->assertSame('Carol', $this->resolver->resolveEffective(null, null, 'Carol'));
    }

    #[Test]
    public function returnsNullWhenNothingProvided(): void
    {
        $this->assertNull($this->resolver->resolveEffective(null, null, null));
    }

    #[Test]
    public function aggregateAllReturnsPrimaryThenDeputies(): void
    {
        $primaryUser = (new User())->setFirstName('Alice')->setLastName('A.');
        $deputy1 = (new Person())->setFullName('Bob B.');
        $deputy2 = (new Person())->setFullName('Carol C.');

        $names = $this->resolver->resolveAll($primaryUser, null, null, [$deputy1, $deputy2]);
        $this->assertSame(['Alice A.', 'Bob B.', 'Carol C.'], $names);
    }

    #[Test]
    public function aggregateAllSkipsEmptyPrimary(): void
    {
        $deputy = (new Person())->setFullName('Bob B.');
        $this->assertSame(['Bob B.'], $this->resolver->resolveAll(null, null, null, [$deputy]));
    }

    #[Test]
    public function aggregateAllReturnsEmptyArrayWhenNothing(): void
    {
        $this->assertSame([], $this->resolver->resolveAll(null, null, null, []));
    }
}
