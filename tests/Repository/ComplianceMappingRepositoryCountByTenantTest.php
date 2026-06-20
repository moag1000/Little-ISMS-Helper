<?php
declare(strict_types=1);
namespace App\Tests\Repository;

use App\Repository\ComplianceMappingRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComplianceMappingRepositoryCountByTenantTest extends TestCase
{
    #[Test]
    public function exposes_count_by_tenant(): void
    {
        self::assertTrue(method_exists(ComplianceMappingRepository::class, 'countByTenant'));
        $rm = new \ReflectionMethod(ComplianceMappingRepository::class, 'countByTenant');
        self::assertSame('int', (string) $rm->getReturnType());
    }
}
