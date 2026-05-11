<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Dto;

use App\Entity\Tenant;
use App\Service\Import\Dto\DeltaConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DeltaConfig DTO — defaults and overrides.
 */
#[AllowMockObjectsWithoutExpectations]
final class DeltaConfigTest extends TestCase
{
    #[Test]
    public function testDefaultsAreSet(): void
    {
        /** @var Tenant $tenant */
        $tenant = $this->createMock(Tenant::class);

        $config = new DeltaConfig(
            entityType: 'Asset',
            tenant: $tenant,
        );

        self::assertSame('Asset', $config->entityType);
        self::assertSame($tenant, $config->tenant);
        self::assertFalse($config->includeDeletes);
        self::assertSame(['updatedAt', 'createdAt'], $config->ignoredFields);
    }

    #[Test]
    public function testIgnoredFieldsCanBeOverridden(): void
    {
        /** @var Tenant $tenant */
        $tenant = $this->createMock(Tenant::class);

        $config = new DeltaConfig(
            entityType: 'Supplier',
            tenant: $tenant,
            includeDeletes: true,
            ignoredFields: ['updatedAt', 'createdAt', 'deletedAt'],
        );

        self::assertTrue($config->includeDeletes);
        self::assertSame(['updatedAt', 'createdAt', 'deletedAt'], $config->ignoredFields);
        self::assertSame('Supplier', $config->entityType);
    }
}
