<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Entity\InterestedParty;
use App\Entity\ISMSObjective;
use App\Entity\Location;
use App\Entity\Patch;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\Vulnerability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Entity-level (API / service path) guards of the person-picker sweep:
 * SameTenantOwner on the User FK, plus the ISMSObjective owner rule and length.
 */
final class SameTenantOwnerEntityTest extends KernelTestCase
{
    /** @return iterable<string, array{class-string, string}> */
    public static function entityProvider(): iterable
    {
        yield 'ISMSObjective' => [ISMSObjective::class, 'responsiblePersonUser'];
        yield 'InterestedParty' => [InterestedParty::class, 'contactPersonUser'];
        yield 'Location' => [Location::class, 'responsiblePersonUser'];
        yield 'Patch' => [Patch::class, 'responsiblePersonUser'];
        yield 'Supplier' => [Supplier::class, 'contactPersonUser'];
        yield 'Vulnerability' => [Vulnerability::class, 'responsiblePersonUser'];
    }

    private static function tenant(int $id): Tenant
    {
        $t = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($t, $id);

        return $t;
    }

    private static function validator(): ValidatorInterface
    {
        self::bootKernel();

        return static::getContainer()->get(ValidatorInterface::class);
    }

    /** @return list<string> property paths violating with the other-tenant message */
    private static function tenantViolations(object $entity, string $path): array
    {
        $out = [];
        foreach (self::validator()->validateProperty($entity, $path) as $v) {
            $out[] = (string) $v->getMessageTemplate();
        }

        return $out;
    }

    /** @param class-string $class */
    #[Test]
    #[DataProvider('entityProvider')]
    public function userOfAnotherTenantIsRejected(string $class, string $property): void
    {
        $entity = new $class();
        $entity->setTenant(self::tenant(1));
        $setter = 'set' . ucfirst($property);
        $entity->{$setter}((new User())->setTenant(self::tenant(2)));

        self::assertContains('tenant.validation.owner_other_tenant', self::tenantViolations($entity, $property));
    }

    /** @param class-string $class */
    #[Test]
    #[DataProvider('entityProvider')]
    public function userOfSameTenantIsAccepted(string $class, string $property): void
    {
        $entity = new $class();
        $entity->setTenant(self::tenant(1));
        $entity->{'set' . ucfirst($property)}((new User())->setTenant(self::tenant(1)));

        self::assertSame([], self::tenantViolations($entity, $property));
    }

    #[Test]
    public function objectiveRequiresSomeOwnerAtEntityLevel(): void
    {
        $objective = new ISMSObjective();
        $templates = array_map(
            static fn ($v) => $v->getMessageTemplate(),
            iterator_to_array(self::validator()->validate($objective)),
        );
        self::assertContains('objective.validation.owner_required', $templates);

        $objective->setResponsiblePerson('Legacy Owner');
        $templates = array_map(
            static fn ($v) => $v->getMessageTemplate(),
            iterator_to_array(self::validator()->validate($objective)),
        );
        self::assertNotContains('objective.validation.owner_required', $templates);
    }

    #[Test]
    public function objectiveFreeTextOwnerIsLimitedTo100Characters(): void
    {
        $objective = (new ISMSObjective())->setResponsiblePerson(str_repeat('x', 101));

        self::assertCount(1, self::validator()->validateProperty($objective, 'responsiblePerson'));
    }
}
