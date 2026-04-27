<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantRepository;
use App\Service\TenantContext;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\SecurityBundle\Security;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
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

    #[Test]
    public function testGetCurrentTenantReturnsNullWhenNoUserIsLoggedIn(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->assertNull($this->tenantContext->getCurrentTenant());
    }

    #[Test]
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

    #[Test]
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

    #[Test]
    public function testGetCurrentTenantIdReturnsNullWhenNoTenant(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->assertNull($this->tenantContext->getCurrentTenantId());
    }

    #[Test]
    public function testGetCurrentTenantIdReturnsTenantId(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(42);

        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);

        $this->security->method('getUser')->willReturn($user);

        $this->assertEquals(42, $this->tenantContext->getCurrentTenantId());
    }

    #[Test]
    public function testHasTenantReturnsFalseWhenNoTenant(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->assertFalse($this->tenantContext->hasTenant());
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function testBelongsToTenantReturnsFalseWhenNoCurrentTenant(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $checkTenant = $this->createMock(Tenant::class);
        $checkTenant->method('getId')->willReturn(100);

        $this->assertFalse($this->tenantContext->belongsToTenant($checkTenant));
    }

    #[Test]
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

    #[Test]
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

    #[Test]
    public function testGetAccessibleTenantsReturnsEmptyWithoutCurrentTenant(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->assertSame([], $this->tenantContext->getAccessibleTenants());
    }

    #[Test]
    public function testGetAccessibleTenantsReturnsSelfPlusDescendants(): void
    {
        $holding = (new Tenant())->setCode('holding')->setName('Holding');
        $sub1 = (new Tenant())->setCode('sub1')->setName('Sub 1');
        $sub2 = (new Tenant())->setCode('sub2')->setName('Sub 2');
        $grandchild = (new Tenant())->setCode('grand')->setName('Grandchild');

        $holding->addSubsidiary($sub1);
        $holding->addSubsidiary($sub2);
        $sub1->addSubsidiary($grandchild);

        $this->tenantContext->setCurrentTenant($holding);

        $accessible = $this->tenantContext->getAccessibleTenants();

        $this->assertCount(4, $accessible);
        $this->assertSame($holding, $accessible[0]);
        $this->assertContains($sub1, $accessible);
        $this->assertContains($sub2, $accessible);
        $this->assertContains($grandchild, $accessible);
    }

    #[Test]
    public function testCanAccessTenantCurrentTenantItself(): void
    {
        $current = $this->createMock(Tenant::class);
        $current->method('getId')->willReturn(10);

        $this->tenantContext->setCurrentTenant($current);

        $this->assertTrue($this->tenantContext->canAccessTenant($current));
    }

    #[Test]
    public function testCanAccessTenantDescendantReturnsTrue(): void
    {
        $holding = (new Tenant())->setCode('holding');
        $sub = (new Tenant())->setCode('sub');
        $holding->addSubsidiary($sub);

        $this->tenantContext->setCurrentTenant($holding);

        $this->assertTrue($this->tenantContext->canAccessTenant($sub));
    }

    #[Test]
    public function testCanAccessTenantSiblingReturnsFalse(): void
    {
        $holding = (new Tenant())->setCode('holding');
        $sub1 = (new Tenant())->setCode('sub1');
        $sub2 = (new Tenant())->setCode('sub2');
        $holding->addSubsidiary($sub1);
        $holding->addSubsidiary($sub2);

        $this->tenantContext->setCurrentTenant($sub1);

        $this->assertFalse($this->tenantContext->canAccessTenant($sub2));
    }

    #[Test]
    public function testGetCurrentRootReturnsRoot(): void
    {
        $holding = (new Tenant())->setCode('holding');
        $sub = (new Tenant())->setCode('sub');
        $leaf = (new Tenant())->setCode('leaf');
        $holding->addSubsidiary($sub);
        $sub->addSubsidiary($leaf);

        $this->tenantContext->setCurrentTenant($leaf);

        $this->assertSame($holding, $this->tenantContext->getCurrentRoot());
    }

    #[Test]
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
