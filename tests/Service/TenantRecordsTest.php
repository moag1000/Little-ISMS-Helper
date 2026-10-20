<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Repository\TrainingRepository;
use App\Service\TenantContext;
use App\Service\TenantRecords;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * No silent unscoped fallback: without an explicit or TenantContext tenant
 * only an instance-wide SUPER_ADMIN reads every record; everyone else (a
 * cron that set no tenant) gets nothing.
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
    public function noTenantAndNoInstanceWideActorFailsClosed(): void
    {
        $context = $this->createStub(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn(null);
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(false);
        $repo = $this->createMock(TrainingRepository::class);
        $repo->expects(self::never())->method('findAll');
        $repo->expects(self::never())->method('findBy');

        self::assertSame([], (new TenantRecords($context, $security))->all($repo, null));
        self::assertSame([], (new TenantRecords())->all($repo, null), 'unwired: fails closed too');
    }

    #[Test]
    public function onlyAnInstanceWideSuperAdminReadsEverything(): void
    {
        $context = $this->createStub(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn(null);
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $a): bool => $a === 'ROLE_SUPER_ADMIN');
        $repo = $this->createMock(TrainingRepository::class);
        $repo->expects(self::once())->method('findAll')->willReturn([]);

        (new TenantRecords($context, $security))->all($repo, null);
    }
}
