<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Repository\TrainingRepository;
use App\Service\TenantContext;
use App\Service\TenantRecords;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * No silent unscoped fallback: without an explicit or TenantContext tenant
 * nothing is returned — no role (SUPER_ADMIN included) reads every record
 * implicitly; instance-wide reads need the explicit `instanceWide` argument.
 */
final class TenantRecordsTest extends TestCase
{
    #[Test]
    public function explicitTenantIsUsed(): void
    {
        $tenant = new Tenant();
        $repo = $this->createMock(TrainingRepository::class);
        $repo->expects(self::once())->method('findBy')->with(['tenant' => $tenant])->willReturn([]);
        $repo->expects(self::never())->method('findAll');

        (new TenantRecords())->all($repo, $tenant);
    }

    #[Test]
    public function tenantContextTenantIsTheFallback(): void
    {
        $tenant = new Tenant();
        $context = $this->createStub(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn($tenant);
        $repo = $this->createMock(TrainingRepository::class);
        $repo->expects(self::once())->method('findBy')->with(['tenant' => $tenant])->willReturn([]);
        $repo->expects(self::never())->method('findAll');

        (new TenantRecords($context))->all($repo, null);
    }

    #[Test]
    public function noTenantFailsClosedEvenForASuperAdmin(): void
    {
        $context = $this->createStub(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn(null);
        $repo = $this->createMock(TrainingRepository::class);
        $repo->expects(self::never())->method('findAll');
        $repo->expects(self::never())->method('findBy');

        self::assertSame([], (new TenantRecords($context))->all($repo, null));
        self::assertSame([], (new TenantRecords())->all($repo, null), 'unwired: fails closed too');
        self::assertFalse(
            method_exists(TenantRecords::class, 'isInstanceWide'),
            'no role-derived hidden instance-wide mode',
        );
    }

    #[Test]
    public function onlyAnExplicitInstanceWideCallerReadsEverything(): void
    {
        $context = $this->createStub(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn(null);
        $repo = $this->createMock(TrainingRepository::class);
        $repo->expects(self::once())->method('findAll')->willReturn([]);

        (new TenantRecords($context))->all($repo, null, instanceWide: true);
    }

    #[Test]
    public function instanceWideFlagNeverWidensAnExistingTenantScope(): void
    {
        $tenant = new Tenant();
        $repo = $this->createMock(TrainingRepository::class);
        $repo->expects(self::once())->method('findBy')->with(['tenant' => $tenant])->willReturn([]);
        $repo->expects(self::never())->method('findAll');

        (new TenantRecords())->all($repo, $tenant, true);
    }
}
