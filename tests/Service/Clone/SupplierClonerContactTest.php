<?php

declare(strict_types=1);

namespace App\Tests\Service\Clone;

use App\Entity\Person;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Clone\SupplierCloner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The supplier clone keeps the contact incl. the structured owner pair and deputies. */
final class SupplierClonerContactTest extends TestCase
{
    private function source(Tenant $tenant): Supplier
    {
        $deputy = (new Person())->setFullName('Deputy');
        return (new Supplier())
            ->setName('CloudCo')
            ->setTenant($tenant)
            ->setContactPerson('Legacy Contact')
            ->setContactPersonUser(new User())
            ->setContactPersonRef((new Person())->setFullName('Ref'))
            ->addContactDeputyPerson($deputy);
    }

    #[Test]
    public function structuredContactAndDeputiesAreCopiedWithinTheOrganisation(): void
    {
        $tenant = new Tenant();
        $source = $this->source($tenant);

        $clone = (new SupplierCloner($this->createStub(EntityManagerInterface::class)))->clone($source);

        self::assertSame('Legacy Contact', $clone->getContactPerson());
        self::assertSame($source->getContactPersonUser(), $clone->getContactPersonUser());
        self::assertSame($source->getContactPersonRef(), $clone->getContactPersonRef());
        self::assertCount(1, $clone->getContactDeputyPersons());
        self::assertNotSame($source->getContactDeputyPersons(), $clone->getContactDeputyPersons(), 'own collection');
        self::assertSame($source->getEffectiveContactPerson(), $clone->getEffectiveContactPerson());
    }

    #[Test]
    public function cloningIntoAnotherOrganisationDropsTheForeignMasterData(): void
    {
        $source = $this->source(new Tenant());

        $clone = (new SupplierCloner($this->createStub(EntityManagerInterface::class)))->clone($source, new Tenant());

        self::assertSame('Legacy Contact', $clone->getContactPerson(), 'free text travels along');
        self::assertNull($clone->getContactPersonUser());
        self::assertNull($clone->getContactPersonRef());
        self::assertCount(0, $clone->getContactDeputyPersons());
    }
}
