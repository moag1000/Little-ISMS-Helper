<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Asset;
use App\Entity\Tenant;
use App\Service\AuditLogger;
use App\Service\BulkDeleteService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class BulkDeleteServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AuditLogger&MockObject $auditLogger;
    private AuthorizationCheckerInterface&MockObject $authChecker;
    private BulkDeleteService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->service = new BulkDeleteService($this->em, $this->auditLogger, $this->authChecker, $translator);
    }

    #[Test]
    public function deletesOwnRowsRejectsForeignAndMissingAndLogsOneBatch(): void
    {
        $tenant = $this->tenant(1);
        $own = $this->asset(10, $tenant, 'Server');
        $foreign = $this->asset(11, $this->tenant(2), 'Foreign');
        $rows = [10 => $own, 11 => $foreign];

        $this->em->expects(self::once())->method('remove')->with($own);
        $this->em->expects(self::once())->method('flush');
        $this->auditLogger->expects(self::once())->method('logBulk')
            ->with(
                'bulk_delete',
                'Asset',
                ['requested' => 3, 'deleted' => 1, 'rejected' => 2],
                [[
                    'action' => 'delete',
                    'entity_id' => 10,
                    'old_values' => ['label' => 'Server'],
                    'new_values' => ['deleted' => true],
                ]],
                self::anything(),
            )
            ->willReturn('batch-uuid');

        $result = $this->service->delete('Asset', [10, 11, 12], $tenant, static fn (int $id): ?object => $rows[$id] ?? null);

        self::assertSame(1, $result['deleted']);
        self::assertSame('batch-uuid', $result['batch_id']);
        self::assertTrue($result['success']);
        self::assertSame([
            'Asset ID 11 does not belong to your organisation',
            'Asset ID 12 not found',
        ], $result['errors']);
    }

    #[Test]
    public function callerWithoutTenantCannotDeleteAnything(): void
    {
        $asset = $this->asset(10, $this->tenant(1), 'Server');
        $this->em->expects(self::never())->method('remove');
        $this->em->expects(self::never())->method('flush');
        $this->auditLogger->expects(self::never())->method('logBulk');

        $result = $this->service->delete('Asset', [10], null, static fn (): object => $asset);

        self::assertSame(0, $result['deleted']);
        self::assertFalse($result['success']);
    }

    #[Test]
    public function superAdminWithoutTenantMayDeleteAcrossTenants(): void
    {
        $asset = $this->asset(10, $this->tenant(1), 'Server');
        $this->authChecker->method('isGranted')->willReturnCallback(
            static fn (string $attribute): bool => $attribute === 'ROLE_SUPER_ADMIN',
        );
        $this->em->expects(self::once())->method('remove')->with($asset);

        $result = $this->service->delete('Asset', [10], null, static fn (): object => $asset);

        self::assertSame(1, $result['deleted']);
    }

    #[Test]
    public function rowWithoutTenantIsNeverDeletable(): void
    {
        $orphan = $this->createStub(Asset::class);
        $orphan->method('getTenant')->willReturn(null);
        $this->em->expects(self::never())->method('remove');

        $result = $this->service->delete('Asset', [10], $this->tenant(1), static fn (): object => $orphan);

        self::assertSame(0, $result['deleted']);
    }

    #[Test]
    public function voterDenialAndGuardRejectionSkipTheRow(): void
    {
        $tenant = $this->tenant(1);
        $a = $this->asset(1, $tenant, 'A');
        $b = $this->asset(2, $tenant, 'B');
        $rows = [1 => $a, 2 => $b];

        $this->authChecker->method('isGranted')->willReturnCallback(
            static fn (string $attr, object $subject): bool => $subject !== $a,
        );
        $this->em->expects(self::never())->method('remove');
        $this->auditLogger->expects(self::never())->method('logBulk');

        $result = $this->service->delete(
            'Asset',
            [1, 2],
            $tenant,
            static fn (int $id): ?object => $rows[$id] ?? null,
            guard: static fn (object $e): ?string => $e === $b ? 'inherited' : null,
            voterAttribute: 'delete',
        );

        self::assertSame(['Asset ID 1: access denied', 'Asset ID 2: inherited'], $result['errors']);
    }

    #[Test]
    public function flushFailureReportsNothingDeletedAndWritesNoAudit(): void
    {
        $tenant = $this->tenant(1);
        $asset = $this->asset(5, $tenant, 'X');
        $this->em->method('flush')->willThrowException(new \RuntimeException('FK violation'));
        $this->auditLogger->expects(self::never())->method('logBulk');
        $afterFlushCalled = false;

        $result = $this->service->delete(
            'Asset',
            [5],
            $tenant,
            static fn (): object => $asset,
            afterFlush: static function () use (&$afterFlushCalled): void {
                $afterFlushCalled = true;
            },
        );

        self::assertSame(0, $result['deleted']);
        self::assertStringContainsString('FK violation', $result['errors'][0]);
        self::assertFalse($afterFlushCalled);
    }

    #[Test]
    public function customDeleterAndAfterFlushAreUsed(): void
    {
        $tenant = $this->tenant(1);
        $asset = $this->asset(7, $tenant, 'Y');
        $deleted = [];
        $cleaned = [];
        $this->em->expects(self::never())->method('remove');
        $this->auditLogger->method('logBulk')->willReturn('b');

        $result = $this->service->delete(
            'Asset',
            [7],
            $tenant,
            static fn (): object => $asset,
            deleter: static function (object $e) use (&$deleted): void {
                $deleted[] = $e;
            },
            afterFlush: static function (object $e) use (&$cleaned): void {
                $cleaned[] = $e;
            },
        );

        self::assertSame(1, $result['deleted']);
        self::assertSame([$asset], $deleted);
        self::assertSame([$asset], $cleaned);
    }

    private function tenant(int $id): Tenant
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($id);

        return $tenant;
    }

    private function asset(int $id, Tenant $tenant, string $name): Asset
    {
        $asset = $this->createStub(Asset::class);
        $asset->method('getId')->willReturn($id);
        $asset->method('getTenant')->willReturn($tenant);
        $asset->method('getName')->willReturn($name);

        return $asset;
    }
}
