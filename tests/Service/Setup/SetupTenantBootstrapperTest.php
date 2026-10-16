<?php

declare(strict_types=1);

namespace App\Tests\Service\Setup;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AssetSubTypeRepository;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Service\AssetSubTypeSeeder;
use App\Service\Setup\SetupTenantBootstrapper;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The setup wizard creates its admin before the organisation exists. An
 * account without an organisation sees no organisation's data, so on a
 * single-organisation installation it must become a member.
 */
final class SetupTenantBootstrapperTest extends TestCase
{
    #[Test]
    public function accountsWithoutOrganisationJoinTheSoleOrganisation(): void
    {
        $tenant = new Tenant();
        $admin = (new User())->setRoles(['ROLE_ADMIN']);
        $operator = (new User())->setRoles(['ROLE_SUPER_ADMIN']);

        $this->bootstrapper($tenant, [$admin, $operator])->assignAccountsWithoutOrganisation();

        self::assertSame($tenant, $admin->getTenant());
        self::assertNull($operator->getTenant(), 'an instance operator stays instance-wide');
    }

    #[Test]
    public function nothingIsAssignedWhenThereAreSeveralOrganisations(): void
    {
        $admin = (new User())->setRoles(['ROLE_ADMIN']);

        $this->bootstrapper(null, [$admin])->assignAccountsWithoutOrganisation();

        self::assertNull($admin->getTenant());
    }

    /**
     * @param list<User> $orphans
     */
    private function bootstrapper(?Tenant $soleTenant, array $orphans): SetupTenantBootstrapper
    {
        $tenants = $this->createStub(TenantRepository::class);
        $tenants->method('findSoleTenant')->willReturn($soleTenant);
        $users = $this->createStub(UserRepository::class);
        $users->method('findOrphan')->willReturn($orphans);
        $em = $this->createStub(EntityManagerInterface::class);

        $seeder = new AssetSubTypeSeeder($em, $this->createStub(AssetSubTypeRepository::class));

        return new SetupTenantBootstrapper($tenants, $em, $seeder, new NullLogger(), $users);
    }
}
