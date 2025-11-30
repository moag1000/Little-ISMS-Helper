<?php

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use App\Service\TenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class TenantContextTest extends TestCase
{
    private TenantContext $tenantContext;
    private Security $security;
    private TenantRepository $tenantRepository;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->tenantRepository = $this->createMock(TenantRepository::class);
        $this->tenantContext = new TenantContext($this->security, $this->tenantRepository);
    }

    public function testGetCurrentTenantReturnsNullWhenNoUserIsLoggedIn(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->assertNull($this->tenantContext->getCurrentTenant());
    }

    public function testGetCurrentTenantReturnsUserTenant(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Test Tenant');
        $tenant->setCode('test_tenant');
        $tenant->setCode('test_tenant');
        $tenant->setCode('test_tenant');

        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);

        $this->security->method('getUser')->willReturn($user);

        $result = $this->tenantContext->getCurrentTenant();

        $this->assertSame($tenant, $result);
        $this->assertEquals('Test Tenant', $result->getName());
    }

    public function testSetCurrentTenantOverridesInitialization(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Manual Tenant');
        $tenant->setCode('manual_tenant');
        $tenant->setCode('manual_tenant');
        $tenant->setCode('manual_tenant');

        $this->tenantContext->setCurrentTenant($tenant);

        $this->assertSame($tenant, $this->tenantContext->getCurrentTenant());
    }

    public function testGetCurrentTenantIdReturnsNullWhenNoTenant(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->assertNull($this->tenantContext->getCurrentTenantId());
    }

    public function testGetCurrentTenantIdReturnsTenantId(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(42);

        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);

        $this->security->method('getUser')->willReturn($user);

        $this->assertEquals(42, $this->tenantContext->getCurrentTenantId());
    }

    public function testHasTenantReturnsFalseWhenNoTenant(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->assertFalse($this->tenantContext->hasTenant());
    }

    public function testHasTenantReturnsTrueWhenTenantExists(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Test Tenant');
        $tenant->setCode('test_tenant');
        $tenant->setCode('test_tenant');
        $tenant->setCode('test_tenant');

        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);

        $this->security->method('getUser')->willReturn($user);

        $this->assertTrue($this->tenantContext->hasTenant());
    }

    public function testBelongsToTenantReturnsTrueForSameTenant(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(100);

        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);

        $this->security->method('getUser')->willReturn($user);

        $checkTenant = $this->createMock(Tenant::class);
        $checkTenant->method('getId')->willReturn(100);

        $this->assertTrue($this->tenantContext->belongsToTenant($checkTenant));
    }

    public function testBelongsToTenantReturnsFalseForDifferentTenant(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(100);

        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);

        $this->security->method('getUser')->willReturn($user);

        $checkTenant = $this->createMock(Tenant::class);
        $checkTenant->method('getId')->willReturn(200);

        $this->assertFalse($this->tenantContext->belongsToTenant($checkTenant));
    }

    public function testBelongsToTenantReturnsFalseWhenNoCurrentTenant(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $checkTenant = $this->createMock(Tenant::class);
        $checkTenant->method('getId')->willReturn(100);

        $this->assertFalse($this->tenantContext->belongsToTenant($checkTenant));
    }

    public function testGetActiveTenantsReturnsActiveTenants(): void
    {
        $tenant1 = new Tenant();
        $tenant1->setName('Active Tenant 1');
        $tenant1->setCode('active_tenant_1');
        $tenant1->setCode('active_tenant_1');
        $tenant1->setCode('active_1');
        $tenant1->setIsActive(true);

        $tenant2 = new Tenant();
        $tenant2->setName('Active Tenant 2');
        $tenant2->setCode('active_tenant_2');
        $tenant2->setCode('active_tenant_2');
        $tenant2->setCode('active_2');
        $tenant2->setIsActive(true);

        $activeTenants = [$tenant1, $tenant2];

        $this->tenantRepository
            ->method('findBy')
            ->with(['isActive' => true], ['name' => 'ASC'])
            ->willReturn($activeTenants);

        $result = $this->tenantContext->getActiveTenants();

        $this->assertCount(2, $result);
        $this->assertSame($activeTenants, $result);
    }

    public function testResetClearsCurrentTenant(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Test Tenant');
        $tenant->setCode('test_tenant');
        $tenant->setCode('test_tenant');
        $tenant->setCode('test_tenant');

        $this->tenantContext->setCurrentTenant($tenant);
        $this->assertTrue($this->tenantContext->hasTenant());

        $this->tenantContext->reset();

        // After reset, should re-initialize from security (which returns null in our setup)
        $this->security->method('getUser')->willReturn(null);
        $this->assertFalse($this->tenantContext->hasTenant());
    }

    public function testMultipleCallsToGetCurrentTenantUseCachedValue(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Cached Tenant');
        $tenant->setCode('cached_tenant');
        $tenant->setCode('cached_tenant');
        $tenant->setCode('cached');

        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);

        // Security should only be called once due to caching
        $this->security->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Multiple calls to getCurrentTenant
        $result1 = $this->tenantContext->getCurrentTenant();
        $result2 = $this->tenantContext->getCurrentTenant();
        $result3 = $this->tenantContext->getCurrentTenant();

        $this->assertSame($result1, $result2);
        $this->assertSame($result2, $result3);
    }
}
