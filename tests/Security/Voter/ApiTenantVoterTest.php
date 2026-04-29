<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\User;
use App\Entity\Asset;
use App\Security\Voter\ApiTenantVoter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class ApiTenantVoterTest extends TestCase
{
    private ApiTenantVoter $voter;

    protected function setUp(): void
    {
        $security = $this->createMock(Security::class);
        // ROLE_ADMIN check via Security::isGranted — default false, overridden per-test
        $security->method('isGranted')->willReturnCallback(
            fn(string $role): bool => $role === 'ROLE_ADMIN' && $this->currentUserIsAdmin
        );
        $this->voter = new ApiTenantVoter($security);
    }

    private bool $currentUserIsAdmin = false;

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private function createUser(?Tenant $tenant, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setTenant($tenant);
        $user->setEmail('test@example.com');
        $user->setPassword('hashed');
        $user->setRoles($roles);
        return $user;
    }

    private function createTenant(int $id): Tenant
    {
        $tenant = new Tenant();
        // Use reflection to set ID
        $ref = new \ReflectionProperty(Tenant::class, 'id');
        $ref->setValue($tenant, $id);
        return $tenant;
    }

    #[Test]
    public function skipsEntitiesWithDedicatedVoters(): void
    {
        $asset = new Asset();
        $result = $this->voter->supportsAttribute('API_VIEW');
        // Asset should be skipped — dedicated AssetVoter handles it
        // We can only test via vote() since supports() needs both attribute+subject
        $tenant = $this->createTenant(1);
        $user = $this->createUser($tenant);
        $asset->setTenant($tenant);

        // The voter should abstain for Asset
        $vote = $this->voter->vote(
            $this->createToken($user),
            $asset,
            ['API_VIEW']
        );
        $this->assertSame(0, $vote); // ABSTAIN
    }

    #[Test]
    public function grantsViewForSameTenant(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser($tenant);
        $supplier = new Supplier();
        $supplier->setTenant($tenant);

        $vote = $this->voter->vote(
            $this->createToken($user),
            $supplier,
            ['API_VIEW']
        );
        $this->assertSame(1, $vote); // ACCESS_GRANTED
    }

    #[Test]
    public function deniesViewForDifferentTenant(): void
    {
        $tenantA = $this->createTenant(1);
        $tenantB = $this->createTenant(2);
        $user = $this->createUser($tenantA);
        $supplier = new Supplier();
        $supplier->setTenant($tenantB);

        $vote = $this->voter->vote(
            $this->createToken($user),
            $supplier,
            ['API_VIEW']
        );
        $this->assertSame(-1, $vote); // ACCESS_DENIED
    }

    #[Test]
    public function grantsEditForSameTenant(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser($tenant);
        $training = new Training();
        $training->setTenant($tenant);

        $vote = $this->voter->vote(
            $this->createToken($user),
            $training,
            ['API_EDIT']
        );
        $this->assertSame(1, $vote);
    }

    #[Test]
    public function deniesEditForDifferentTenant(): void
    {
        $tenantA = $this->createTenant(1);
        $tenantB = $this->createTenant(2);
        $user = $this->createUser($tenantA);
        $training = new Training();
        $training->setTenant($tenantB);

        $vote = $this->voter->vote(
            $this->createToken($user),
            $training,
            ['API_EDIT']
        );
        $this->assertSame(-1, $vote);
    }

    #[Test]
    public function deniesDeleteForNonAdmin(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser($tenant);
        $supplier = new Supplier();
        $supplier->setTenant($tenant);

        $vote = $this->voter->vote(
            $this->createToken($user),
            $supplier,
            ['API_DELETE']
        );
        $this->assertSame(-1, $vote);
    }

    #[Test]
    public function grantsAllForAdmin(): void
    {
        $this->currentUserIsAdmin = true;
        $tenantA = $this->createTenant(1);
        $tenantB = $this->createTenant(2);
        $admin = $this->createUser($tenantA, ['ROLE_USER', 'ROLE_ADMIN']);
        $supplier = new Supplier();
        $supplier->setTenant($tenantB); // Different tenant

        foreach (['API_VIEW', 'API_EDIT', 'API_DELETE', 'API_CREATE'] as $attr) {
            $vote = $this->voter->vote(
                $this->createToken($admin),
                $supplier,
                [$attr]
            );
            $this->assertSame(1, $vote, "Admin should be granted $attr");
        }
    }

    #[Test]
    public function grantsCreateForAuthenticatedUser(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser($tenant);
        $supplier = new Supplier();
        $supplier->setTenant($tenant);

        $vote = $this->voter->vote(
            $this->createToken($user),
            $supplier,
            ['API_CREATE']
        );
        $this->assertSame(1, $vote);
    }

    #[Test]
    public function abstainForUnknownAttributes(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser($tenant);
        $supplier = new Supplier();
        $supplier->setTenant($tenant);

        $vote = $this->voter->vote(
            $this->createToken($user),
            $supplier,
            ['UNKNOWN_ATTRIBUTE']
        );
        $this->assertSame(0, $vote); // ABSTAIN
    }
}
