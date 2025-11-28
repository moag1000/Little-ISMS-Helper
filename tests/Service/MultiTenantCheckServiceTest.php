<?php

namespace App\Tests\Service;

use App\Repository\TenantRepository;
use App\Service\MultiTenantCheckService;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MultiTenantCheckServiceTest extends TestCase
{
    private MockObject $tenantRepository;
    private MultiTenantCheckService $service;

    protected function setUp(): void
    {
        $this->tenantRepository = $this->createMock(TenantRepository::class);
        $this->service = new MultiTenantCheckService($this->tenantRepository);
    }

    public function testIsMultiTenantReturnsTrueWhenMultipleTenantsExist(): void
    {
        $this->tenantRepository->method('count')
            ->with(['isActive' => true])
            ->willReturn(2);

        $result = $this->service->isMultiTenant();

        $this->assertTrue($result);
    }

    public function testIsMultiTenantReturnsFalseWhenOnlyOneTenantExists(): void
    {
        $this->tenantRepository->method('count')
            ->with(['isActive' => true])
            ->willReturn(1);

        $result = $this->service->isMultiTenant();

        $this->assertFalse($result);
    }

    public function testIsMultiTenantReturnsFalseWhenNoTenantsExist(): void
    {
        $this->tenantRepository->method('count')
            ->with(['isActive' => true])
            ->willReturn(0);

        $result = $this->service->isMultiTenant();

        $this->assertFalse($result);
    }

    public function testIsMultiTenantUsesCacheOnSubsequentCalls(): void
    {
        $this->tenantRepository->expects($this->once())
            ->method('count')
            ->with(['isActive' => true])
            ->willReturn(3);

        // First call - should hit the repository
        $result1 = $this->service->isMultiTenant();
        $this->assertTrue($result1);

        // Second call - should use cache, not hit repository again
        $result2 = $this->service->isMultiTenant();
        $this->assertTrue($result2);

        // Third call - verify cache is still used
        $result3 = $this->service->isMultiTenant();
        $this->assertTrue($result3);
    }

    public function testGetActiveTenantCountReturnsCorrectCount(): void
    {
        $this->tenantRepository->method('count')
            ->with(['isActive' => true])
            ->willReturn(5);

        $count = $this->service->getActiveTenantCount();

        $this->assertEquals(5, $count);
    }

    public function testGetActiveTenantCountReturnsZeroWhenNoTenants(): void
    {
        $this->tenantRepository->method('count')
            ->with(['isActive' => true])
            ->willReturn(0);

        $count = $this->service->getActiveTenantCount();

        $this->assertEquals(0, $count);
    }

    public function testGetActiveTenantCountUsesCacheOnSubsequentCalls(): void
    {
        $this->tenantRepository->expects($this->once())
            ->method('count')
            ->with(['isActive' => true])
            ->willReturn(7);

        // First call - should hit the repository
        $count1 = $this->service->getActiveTenantCount();
        $this->assertEquals(7, $count1);

        // Second call - should use cache
        $count2 = $this->service->getActiveTenantCount();
        $this->assertEquals(7, $count2);
    }

    public function testGetActiveTenantCountReturnsZeroWhenTableNotFound(): void
    {
        $this->tenantRepository->method('count')
            ->willThrowException($this->createMock(TableNotFoundException::class));

        $count = $this->service->getActiveTenantCount();

        $this->assertEquals(0, $count);
    }

    public function testGetActiveTenantCountReturnsZeroWhenConnectionFails(): void
    {
        $this->tenantRepository->method('count')
            ->willThrowException($this->createMock(ConnectionException::class));

        $count = $this->service->getActiveTenantCount();

        $this->assertEquals(0, $count);
    }

    public function testGetActiveTenantCountReturnsZeroWhenGenericExceptionThrown(): void
    {
        $this->tenantRepository->method('count')
            ->willThrowException(new Exception('Database error'));

        $count = $this->service->getActiveTenantCount();

        $this->assertEquals(0, $count);
    }

    public function testIsMultiTenantReturnsFalseWhenExceptionOccurs(): void
    {
        $this->tenantRepository->method('count')
            ->willThrowException($this->createMock(TableNotFoundException::class));

        $result = $this->service->isMultiTenant();

        $this->assertFalse($result);
    }

    public function testShowCorporateFeaturesReturnsTrueForMultipleTenants(): void
    {
        $this->tenantRepository->method('count')
            ->with(['isActive' => true])
            ->willReturn(3);

        $result = $this->service->showCorporateFeatures();

        $this->assertTrue($result);
    }

    public function testShowCorporateFeaturesReturnsFalseForSingleTenant(): void
    {
        $this->tenantRepository->method('count')
            ->with(['isActive' => true])
            ->willReturn(1);

        $result = $this->service->showCorporateFeatures();

        $this->assertFalse($result);
    }

    public function testShowCorporateFeaturesReturnsFalseWhenNoTenants(): void
    {
        $this->tenantRepository->method('count')
            ->with(['isActive' => true])
            ->willReturn(0);

        $result = $this->service->showCorporateFeatures();

        $this->assertFalse($result);
    }

    public function testClearCacheResetsCountCache(): void
    {
        // First call to populate cache
        $this->tenantRepository->expects($this->exactly(2))
            ->method('count')
            ->with(['isActive' => true])
            ->willReturnOnConsecutiveCalls(2, 5);

        // Initial call - returns 2
        $count1 = $this->service->getActiveTenantCount();
        $this->assertEquals(2, $count1);

        // Call again - should return cached value (2)
        $count2 = $this->service->getActiveTenantCount();
        $this->assertEquals(2, $count2);

        // Clear cache
        $this->service->clearCache();

        // Call again - should hit repository again and return 5
        $count3 = $this->service->getActiveTenantCount();
        $this->assertEquals(5, $count3);
    }

    public function testClearCacheResetsIsMultiTenantCache(): void
    {
        // First call to populate cache
        $this->tenantRepository->expects($this->exactly(2))
            ->method('count')
            ->with(['isActive' => true])
            ->willReturnOnConsecutiveCalls(1, 3);

        // Initial call - returns false (1 tenant)
        $result1 = $this->service->isMultiTenant();
        $this->assertFalse($result1);

        // Call again - should return cached value (false)
        $result2 = $this->service->isMultiTenant();
        $this->assertFalse($result2);

        // Clear cache
        $this->service->clearCache();

        // Call again - should hit repository again and return true (3 tenants)
        $result3 = $this->service->isMultiTenant();
        $this->assertTrue($result3);
    }

    public function testClearCacheResetsBothCaches(): void
    {
        // Populate both caches
        $this->tenantRepository->expects($this->exactly(2))
            ->method('count')
            ->with(['isActive' => true])
            ->willReturnOnConsecutiveCalls(4, 1);

        $this->service->getActiveTenantCount();
        $this->service->isMultiTenant();

        // Clear cache
        $this->service->clearCache();

        // Both should hit repository again
        $count = $this->service->getActiveTenantCount();
        $this->assertEquals(1, $count);

        $isMulti = $this->service->isMultiTenant();
        $this->assertFalse($isMulti);
    }

    public function testGetHiddenReasonReturnsMessageWhenNoTenants(): void
    {
        $this->tenantRepository->method('count')
            ->with(['isActive' => true])
            ->willReturn(0);

        $reason = $this->service->getHiddenReason();

        $this->assertEquals('No active tenants exist', $reason);
    }

    public function testGetHiddenReasonReturnsMessageWhenOnlyOneTenant(): void
    {
        $this->tenantRepository->method('count')
            ->with(['isActive' => true])
            ->willReturn(1);

        $reason = $this->service->getHiddenReason();

        $this->assertEquals(
            'Corporate structure features are only available when multiple tenants exist',
            $reason
        );
    }

    public function testGetHiddenReasonReturnsEmptyStringWhenMultipleTenants(): void
    {
        $this->tenantRepository->method('count')
            ->with(['isActive' => true])
            ->willReturn(2);

        $reason = $this->service->getHiddenReason();

        $this->assertEquals('', $reason);
    }

    public function testGetHiddenReasonReturnsEmptyStringWhenManyTenants(): void
    {
        $this->tenantRepository->method('count')
            ->with(['isActive' => true])
            ->willReturn(10);

        $reason = $this->service->getHiddenReason();

        $this->assertEquals('', $reason);
    }

    public function testGetHiddenReasonUsesCachedCount(): void
    {
        $this->tenantRepository->expects($this->once())
            ->method('count')
            ->with(['isActive' => true])
            ->willReturn(1);

        // First call to populate cache
        $this->service->getActiveTenantCount();

        // getHiddenReason should use cached value
        $reason = $this->service->getHiddenReason();

        $this->assertEquals(
            'Corporate structure features are only available when multiple tenants exist',
            $reason
        );
    }

    public function testExactlyTwoTenantsIsConsideredMultiTenant(): void
    {
        $this->tenantRepository->method('count')
            ->with(['isActive' => true])
            ->willReturn(2);

        $this->assertTrue($this->service->isMultiTenant());
        $this->assertEquals('', $this->service->getHiddenReason());
    }

    public function testCacheIsSharedBetweenIsMultiTenantAndGetActiveTenantCount(): void
    {
        $this->tenantRepository->expects($this->once())
            ->method('count')
            ->with(['isActive' => true])
            ->willReturn(4);

        // First call via getActiveTenantCount
        $count = $this->service->getActiveTenantCount();
        $this->assertEquals(4, $count);

        // Second call via isMultiTenant should use the same cached count
        $isMulti = $this->service->isMultiTenant();
        $this->assertTrue($isMulti);

        // Third call via showCorporateFeatures should also use cache
        $show = $this->service->showCorporateFeatures();
        $this->assertTrue($show);
    }

    public function testServiceHandlesEdgeCaseOfExactlyOneTenant(): void
    {
        $this->tenantRepository->method('count')
            ->with(['isActive' => true])
            ->willReturn(1);

        $this->assertFalse($this->service->isMultiTenant());
        $this->assertFalse($this->service->showCorporateFeatures());
        $this->assertEquals(1, $this->service->getActiveTenantCount());
        $this->assertEquals(
            'Corporate structure features are only available when multiple tenants exist',
            $this->service->getHiddenReason()
        );
    }

    public function testServiceHandlesLargeNumberOfTenants(): void
    {
        $this->tenantRepository->method('count')
            ->with(['isActive' => true])
            ->willReturn(999);

        $this->assertTrue($this->service->isMultiTenant());
        $this->assertTrue($this->service->showCorporateFeatures());
        $this->assertEquals(999, $this->service->getActiveTenantCount());
        $this->assertEquals('', $this->service->getHiddenReason());
    }

    public function testExceptionDuringCachePopulationIsHandledGracefully(): void
    {
        // First call throws exception, subsequent calls should work
        $this->tenantRepository->expects($this->exactly(2))
            ->method('count')
            ->willReturnOnConsecutiveCalls(
                $this->throwException($this->createMock(TableNotFoundException::class)),
                3
            );

        // First call - exception should be caught and return 0
        $count1 = $this->service->getActiveTenantCount();
        $this->assertEquals(0, $count1);

        // Cache should contain 0 now
        $isMulti1 = $this->service->isMultiTenant();
        $this->assertFalse($isMulti1);

        // Clear cache
        $this->service->clearCache();

        // Second call after clearing - should work normally
        $count2 = $this->service->getActiveTenantCount();
        $this->assertEquals(3, $count2);
    }

    public function testMultipleSequentialClearCacheCalls(): void
    {
        $this->tenantRepository->method('count')
            ->with(['isActive' => true])
            ->willReturn(2);

        // Populate cache
        $this->service->getActiveTenantCount();

        // Multiple clear calls should not cause issues
        $this->service->clearCache();
        $this->service->clearCache();
        $this->service->clearCache();

        // Should still work normally
        $count = $this->service->getActiveTenantCount();
        $this->assertEquals(2, $count);
    }

    public function testIsMultiTenantCachesResultIndependentlyFromGetActiveTenantCount(): void
    {
        $this->tenantRepository->expects($this->once())
            ->method('count')
            ->with(['isActive' => true])
            ->willReturn(5);

        // Calling isMultiTenant first should cache both values
        $isMulti = $this->service->isMultiTenant();
        $this->assertTrue($isMulti);

        // getActiveTenantCount should use the cached count value
        $count = $this->service->getActiveTenantCount();
        $this->assertEquals(5, $count);
    }
}
