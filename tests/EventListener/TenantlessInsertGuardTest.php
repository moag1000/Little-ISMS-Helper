<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Doctrine\SharedTenantRowsInterface;
use App\Entity\Location;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\Workflow;
use App\EventListener\TenantlessInsertGuard;
use App\Service\TenantContext;
use App\Tests\Contract\Support\ContractRecordFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * T2: Gate 72 at run time. A row of a tenant-owned entity created during a request of a person of
 * an organisation is saved with that organisation, or the flush fails in the test environment —
 * every functional test of the suite then checks it, also for factories, `clone` and helpers the
 * static gate cannot follow.
 */
final class TenantlessInsertGuardTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Tenant $tenant;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $id = bin2hex(random_bytes(4));
        $this->tenant = (new Tenant())->setName('Guard ' . $id)->setCode('grd_' . $id);
        $this->em->persist($this->tenant);
        $user = (new User())->setEmail('guard-' . $id . '@example.test')->setFirstName('G')->setLastName('Uard')
            ->setRoles(['ROLE_MANAGER'])->setPassword('x')->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        self::getContainer()->get('security.untracked_token_storage')
            ->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        self::getContainer()->get(TenantContext::class)->setCurrentTenant($this->tenant);
    }

    #[Test]
    public function aRowWithoutTenantFailsTheFlushDuringARequest(): void
    {
        self::getContainer()->get(RequestStack::class)->push(Request::create('/de/location/new', 'POST'));
        $location = $this->location();
        $location->setTenant(null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('without an organisation');
        $this->em->flush();
    }

    #[Test]
    public function aRowWithItsTenantIsSaved(): void
    {
        self::getContainer()->get(RequestStack::class)->push(Request::create('/de/location/new', 'POST'));
        $location = $this->location();

        $this->em->flush();

        self::assertNotNull($location->getId());
    }

    #[Test]
    public function anExistingRowWhoseTenantIsSetToNullFailsTheFlush(): void
    {
        $location = $this->location();
        $this->em->flush();
        self::getContainer()->get(RequestStack::class)->push(Request::create('/de/location/1/edit', 'POST'));

        $location->setTenant(null);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('without an organisation');
        $this->em->flush();
    }

    #[Test]
    public function anUpdateKeepingTheTenantIsSaved(): void
    {
        $location = $this->location();
        $this->em->flush();
        self::getContainer()->get(RequestStack::class)->push(Request::create('/de/location/1/edit', 'POST'));

        $location->setName('renamed');
        $this->em->flush();

        self::assertSame('renamed', $location->getName());
    }

    /**
     * T4 (T6 of the counter-check): outside the test environment a warning alone saved the row invisible to its
     * own organisation; now the guard repairs it before the write.
     */
    #[Test]
    public function outsideTheTestEnvironmentTheRowIsRepairedNotJustLogged(): void
    {
        $existing = $this->location();
        $this->em->flush();
        $stack = self::getContainer()->get(RequestStack::class);
        $stack->push(Request::create('/de/location/new', 'POST'));
        $new = $this->location();
        $new->setTenant(null);
        $existing->setTenant(null);

        $guard = new TenantlessInsertGuard(
            self::getContainer()->get(TenantContext::class),
            $stack,
            new \Psr\Log\NullLogger(),
            'prod',
        );
        $uow = $this->em->getUnitOfWork();
        $uow->computeChangeSets();
        $guard->onFlush(new \Doctrine\ORM\Event\OnFlushEventArgs($this->em));

        self::assertSame($this->tenant->getId(), $new->getTenant()?->getId(), 'a new row gets the organisation of the request');
        self::assertSame($this->tenant->getId(), $existing->getTenant()?->getId(), 'an existing row keeps its organisation');
        $change = $uow->getEntityChangeSet($existing)['tenant'] ?? null;
        self::assertTrue($change === null || $change[1] === $this->tenant, 'the repaired update writes the old organisation');
    }

    #[Test]
    public function outsideARequestNothingIsChecked(): void
    {
        $location = $this->location();
        $location->setTenant(null);

        $this->em->flush();

        self::assertNotNull($location->getId(), 'console commands and workers pass their tenant explicitly');
    }

    #[Test]
    public function tenantOwnedFollowsTheTenantFilter(): void
    {
        self::assertTrue(TenantlessInsertGuard::isTenantOwned(new \ReflectionClass(Location::class), true));
        self::assertFalse(TenantlessInsertGuard::isTenantOwned(new \ReflectionClass(User::class), true));
        self::assertFalse(TenantlessInsertGuard::isTenantOwned(new \ReflectionClass(Tenant::class), true));
        self::assertFalse(TenantlessInsertGuard::isTenantOwned(new \ReflectionClass(Location::class), false));
        self::assertFalse(
            TenantlessInsertGuard::isTenantOwned(new \ReflectionClass(Workflow::class), true),
            'rows meant for all organisations (SharedTenantRowsInterface) may carry no tenant',
        );
        self::assertTrue(
            new \ReflectionClass(Workflow::class)->implementsInterface(SharedTenantRowsInterface::class),
            'the fixture entity must really be a shared-rows entity',
        );
    }

    private function location(): Location
    {
        $location = new ContractRecordFactory($this->em)->create(Location::class, $this->tenant, 'guard');
        $this->em->persist($location);

        return $location;
    }
}
