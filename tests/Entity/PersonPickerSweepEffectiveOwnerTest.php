<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\InterestedParty;
use App\Entity\ISMSObjective;
use App\Entity\Location;
use App\Entity\Patch;
use App\Entity\Person;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Vulnerability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Person-picker sweep: the 6 entities that gained the Pattern A dual-state
 * owner cluster resolve their effective owner as User > Person > free text,
 * and expose primary + deputies through the roster accessor.
 */
final class PersonPickerSweepEffectiveOwnerTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function entityProvider(): iterable
    {
        yield 'ISMSObjective' => [ISMSObjective::class, 'ResponsiblePerson'];
        yield 'InterestedParty' => [InterestedParty::class, 'ContactPerson'];
        yield 'Location' => [Location::class, 'ResponsiblePerson'];
        yield 'Patch' => [Patch::class, 'ResponsiblePerson'];
        yield 'Supplier' => [Supplier::class, 'ContactPerson'];
        yield 'Vulnerability' => [Vulnerability::class, 'ResponsiblePerson'];
    }

    private static function user(string $first, string $last): User
    {
        return (new User())->setFirstName($first)->setLastName($last)->setEmail(strtolower($first) . '@example.com');
    }

    private static function person(string $fullName): Person
    {
        return (new Person())->setFullName($fullName);
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('entityProvider')]
    public function freeTextIsTheFallbackWhenNoStructuredOwnerIsSet(string $class, string $base): void
    {
        $entity = new $class();
        self::assertNull($entity->{'getEffective' . $base}());

        $entity->{'set' . $base}('Legacy Owner');
        self::assertSame('Legacy Owner', $entity->{'getEffective' . $base}());
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('entityProvider')]
    public function personBeatsFreeText(string $class, string $base): void
    {
        $entity = new $class();
        $entity->{'set' . $base}('Legacy Owner');
        $entity->{'set' . $base . 'Ref'}(self::person('Paula Person'));

        self::assertSame('Paula Person', $entity->{'getEffective' . $base}());
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('entityProvider')]
    public function userBeatsPersonAndFreeText(string $class, string $base): void
    {
        $entity = new $class();
        $entity->{'set' . $base}('Legacy Owner');
        $entity->{'set' . $base . 'Ref'}(self::person('Paula Person'));
        $entity->{'set' . $base . 'User'}(self::user('Ulla', 'User'));

        self::assertSame('Ulla User', $entity->{'getEffective' . $base}());
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('entityProvider')]
    public function rosterListsPrimaryFirstThenDeputiesWithoutDuplicates(string $class, string $base): void
    {
        $role = str_replace('Person', '', $base); // Responsible | Contact
        $entity = new $class();
        $entity->{'set' . $base . 'User'}(self::user('Ulla', 'User'));
        $deputy = self::person('Dora Deputy');
        $entity->{'add' . $role . 'DeputyPerson'}($deputy);
        $entity->{'add' . $role . 'DeputyPerson'}($deputy); // idempotent

        self::assertCount(1, $entity->{'get' . $role . 'DeputyPersons'}());
        self::assertSame(['Ulla User', 'Dora Deputy'], $entity->{'getAll' . $base . 's'}());

        $entity->{'remove' . $role . 'DeputyPerson'}($deputy);
        self::assertSame(['Ulla User'], $entity->{'getAll' . $base . 's'}());
    }
}
