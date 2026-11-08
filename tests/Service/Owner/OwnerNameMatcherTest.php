<?php

declare(strict_types=1);

namespace App\Tests\Service\Owner;

use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Owner\OwnerNameMatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OwnerNameMatcherTest extends TestCase
{
    private Tenant $tenant;
    private Tenant $other;

    protected function setUp(): void
    {
        $this->tenant = (new Tenant())->setName('A')->setCode('a');
        $this->other = (new Tenant())->setName('B')->setCode('b');
    }

    #[Test]
    public function matchesUserByNameEmailAndReversedNameIgnoringCaseAndBlanks(): void
    {
        $max = $this->user('Max', 'Muster', 'max.muster@firma.test', $this->tenant);
        $matcher = new OwnerNameMatcher([$max], []);

        self::assertSame($max, $matcher->match('  max   MUSTER ', $this->tenant));
        self::assertSame($max, $matcher->match('Muster, Max', $this->tenant));
        self::assertSame($max, $matcher->match('MAX.MUSTER@firma.test', $this->tenant));
        self::assertNull($matcher->match('Max', $this->tenant));
    }

    #[Test]
    public function staysInsideTheRecordsOrganisation(): void
    {
        $matcher = new OwnerNameMatcher([$this->user('Max', 'Muster', 'm@x.test', $this->other)], []);

        self::assertNull($matcher->match('Max Muster', $this->tenant));
    }

    #[Test]
    public function anAmbiguousNameGivesNoSuggestion(): void
    {
        $matcher = new OwnerNameMatcher([
            $this->user('Max', 'Muster', 'm1@x.test', $this->tenant),
            $this->user('Max', 'Muster', 'm2@x.test', $this->tenant),
        ], [$this->person('Max Muster', $this->tenant)]);

        self::assertNull($matcher->match('Max Muster', $this->tenant));
    }

    #[Test]
    public function fallsBackToAPersonAndSkipsInactiveAccounts(): void
    {
        $inactive = $this->user('Eva', 'Extern', 'eva@x.test', $this->tenant)->setIsActive(false);
        $person = $this->person('Eva Extern', $this->tenant);
        $matcher = new OwnerNameMatcher([$inactive], [$person]);

        self::assertSame($person, $matcher->match('eva extern', $this->tenant));
        self::assertNull($matcher->match('', $this->tenant));
        self::assertNull($matcher->match(null, $this->tenant));
    }

    private function user(string $first, string $last, string $email, Tenant $tenant): User
    {
        return (new User())->setFirstName($first)->setLastName($last)->setEmail($email)
            ->setTenant($tenant)->setIsActive(true);
    }

    private function person(string $name, Tenant $tenant): Person
    {
        return (new Person())->setFullName($name)->setTenant($tenant);
    }
}
