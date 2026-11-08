<?php

declare(strict_types=1);

namespace App\Tests\Service\Owner;

use App\Entity\Asset;
use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Owner\OwnerTextResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A name or e-mail written as text becomes the User or Person it means in the
 * organisation, else one new Person — never a guess, never another organisation.
 * Runs inside a transaction that is rolled back.
 */
final class OwnerTextResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private Tenant $other;
    private User $max;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $uid = str_replace('.', '', uniqid('otr', true));
        $this->tenant = (new Tenant())->setName('OTR ' . $uid)->setCode('otr_' . $uid);
        $this->other = (new Tenant())->setName('OTR B ' . $uid)->setCode('otrb_' . $uid);
        $this->em->persist($this->tenant);
        $this->em->persist($this->other);
        $this->max = (new User())->setEmail('max_' . $uid . '@example.com')->setFirstName('Max')
            ->setLastName('Muster' . $uid)->setRoles(['ROLE_USER'])->setPassword('x')
            ->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($this->max);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    private function resolver(): OwnerTextResolver
    {
        return new OwnerTextResolver($this->em);
    }

    #[Test]
    public function aUserIsFoundByEmailAndByReversedName(): void
    {
        $resolver = $this->resolver();

        self::assertSame($this->max, $resolver->find($this->tenant, $this->max->getEmail()));
        self::assertSame(
            $this->max,
            $resolver->find($this->tenant, $this->max->getLastName() . ', Max'),
        );
        self::assertNull($resolver->find($this->other, $this->max->getEmail()), 'no match across organisations');
    }

    #[Test]
    public function findNeverWrites(): void
    {
        self::assertNull($this->resolver()->find($this->tenant, 'Niemand Bekannt'));
        self::assertSame([], $this->em->getUnitOfWork()->getScheduledEntityInsertions(), 'nothing scheduled for insert');
    }

    #[Test]
    public function anUnknownNameBecomesOnePersonForAllRows(): void
    {
        $resolver = $this->resolver();
        $first = $resolver->resolve($this->tenant, 'Neue  Kollegin');
        $second = $resolver->resolve($this->tenant, 'neue kollegin');

        self::assertInstanceOf(Person::class, $first);
        self::assertSame($first, $second, 'same text, same Person');
        self::assertSame('Neue Kollegin', $first->getFullName());
        self::assertSame('other', $first->getPersonType());
        self::assertSame($this->tenant, $first->getTenant());

        $this->em->flush();
        self::assertSame($first->getId(), $this->resolver()->find($this->tenant, 'Neue Kollegin')?->getId());
    }

    #[Test]
    public function anEmailTextKeepsTheAddressOnTheNewPerson(): void
    {
        $person = $this->resolver()->resolve($this->tenant, 'extern@example.org');

        self::assertInstanceOf(Person::class, $person);
        self::assertSame('extern@example.org', $person->getEmail());
    }

    #[Test]
    public function assignFillsTheMatchingSlotAndKeepsAFilledOne(): void
    {
        $resolver = $this->resolver();

        $asset = (new Asset())->setTenant($this->tenant);
        self::assertSame($this->max, $resolver->assign($asset, 'ownerUser', 'ownerPerson', $this->tenant, $this->max->getEmail()));
        self::assertSame($this->max, $asset->getOwnerUser());
        self::assertNull($asset->getOwnerPerson());

        $kept = (new Person())->setFullName('Bleibt');
        $taken = (new Asset())->setTenant($this->tenant)->setOwnerPerson($kept);
        self::assertNull($resolver->assign($taken, 'ownerUser', 'ownerPerson', $this->tenant, 'Jemand Anders'));
        self::assertSame($kept, $taken->getOwnerPerson());
    }

    #[Test]
    public function emptyTextOrNoOrganisationGivesNothing(): void
    {
        self::assertNull($this->resolver()->resolve($this->tenant, '   '));
        self::assertNull($this->resolver()->resolve(null, 'Max Muster'));
    }
}
