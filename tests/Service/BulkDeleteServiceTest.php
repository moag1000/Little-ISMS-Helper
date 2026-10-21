<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Asset;
use App\Entity\Tenant;
use App\Service\AuditLogger;
use App\Security\TenantOwnership;
use App\Service\BulkDeleteService;
use App\Service\TenantContext;
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
    private TenantContext&MockObject $tenantContext;
    private BulkDeleteService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->tenantContext = $this->createMock(TenantContext::class);
        $this->tenantContext->method('canAccessTenant')->willReturnCallback(
            fn (Tenant $candidate): bool => TenantOwnership::isSameOrDescendant(
                $this->tenantContext->getCurrentTenant(),
                $candidate,
            ),
        );

        $this->service = new BulkDeleteService(
            $this->em,
            $this->auditLogger,
            $this->authChecker,
            $translator,
            $this->tenantContext,
        );
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

        $result = $this->actingIn($tenant)->delete('Asset', [10, 11, 12], static fn (int $id): ?object => $rows[$id] ?? null);

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

        $result = $this->actingIn(null)->delete('Asset', [10], static fn (): object => $asset);

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

        $result = $this->actingIn(null)->delete('Asset', [10], static fn (): object => $asset);

        self::assertSame(1, $result['deleted']);
    }

    #[Test]
    public function rowWithoutTenantIsNeverDeletable(): void
    {
        $orphan = $this->createStub(Asset::class);
        $orphan->method('getTenant')->willReturn(null);
        $this->em->expects(self::never())->method('remove');

        $result = $this->actingIn($this->tenant(1))->delete('Asset', [10], static fn (): object => $orphan);

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
            static fn (string $attr, ?object $subject = null): bool => $attr !== 'ROLE_SUPER_ADMIN' && $subject !== $a,
        );
        $this->em->expects(self::never())->method('remove');
        $this->auditLogger->expects(self::never())->method('logBulk');

        $result = $this->actingIn($tenant)->delete(
            'Asset',
            [1, 2],
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

        $result = $this->actingIn($tenant)->delete(
            'Asset',
            [5],
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

        $result = $this->actingIn($tenant)->delete(
            'Asset',
            [7],
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

    private function actingIn(?Tenant $tenant): BulkDeleteService
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        return $this->service;
    }

    #[Test]
    public function tenantComesFromContextNotFromTheUser(): void
    {
        // The service never reads the user's tenant column: the acting
        // tenant is whatever TenantContext says (non-SUPER_ADMIN actor).
        $asset = $this->asset(10, $this->tenant(1), 'Server');
        $this->authChecker->method('isGranted')->willReturn(false);
        $this->em->expects(self::once())->method('remove')->with($asset);
        $this->auditLogger->method('logBulk')->willReturn('b');

        $result = $this->actingIn($this->tenant(1))->delete('Asset', [10], static fn (): object => $asset);

        self::assertSame(1, $result['deleted']);
    }

    /**
     * Record scope: ROLE_SUPER_ADMIN is instance-wide even while it views an
     * organisation through TenantContext.
     */
    #[Test]
    public function superAdminWithTenantContextStaysGlobal(): void
    {
        $asset = $this->asset(10, $this->tenant(2), 'Server');
        $this->authChecker->method('isGranted')->willReturnCallback(
            static fn (string $attribute): bool => $attribute === 'ROLE_SUPER_ADMIN',
        );
        $this->em->expects(self::once())->method('remove')->with($asset);
        $this->auditLogger->method('logBulk')->willReturn('b');

        $result = $this->actingIn($this->tenant(1))->delete('Asset', [10], static fn (): object => $asset);

        self::assertSame(1, $result['deleted']);
    }

    /**
     * Record scope: never a subsidiary's rows, not even for the holding.
     */
    #[Test]
    public function subsidiaryRowIsNotDeletableFromTheHolding(): void
    {
        $holding = $this->tenant(1);
        $subsidiary = $this->tenant(2);
        $subsidiary->method('getParent')->willReturn($holding);
        $asset = $this->asset(10, $subsidiary, 'Server');
        $this->authChecker->method('isGranted')->willReturn(false);
        $this->em->expects(self::never())->method('remove');

        $result = $this->actingIn($holding)->delete('Asset', [10], static fn (): object => $asset);

        self::assertSame(0, $result['deleted']);
    }

    #[Test]
    public function atomicBatchRollsBackWhenALaterDeleterFailsAndSaysSo(): void
    {
        $tenant = $this->tenant(1);
        $a = $this->asset(1, $tenant, 'A');
        $b = $this->asset(2, $tenant, 'B');
        $rows = [1 => $a, 2 => $b];
        $deleted = [];

        $this->em->expects(self::once())->method('beginTransaction');
        $this->em->expects(self::never())->method('commit');
        $this->em->expects(self::once())->method('rollback');
        $this->em->expects(self::once())->method('clear');
        $this->auditLogger->expects(self::never())->method('logBulk');

        $result = $this->actingIn($tenant)->delete(
            'Asset',
            [1, 2],
            static fn (int $id): ?object => $rows[$id] ?? null,
            deleter: static function (object $e) use (&$deleted, $b): void {
                if ($e === $b) {
                    throw new \RuntimeException('EntityManager is closed');
                }
                $deleted[] = $e; // a domain service that already flushed internally
            },
        );

        self::assertSame(0, $result['deleted']);
        self::assertFalse($result['success']);
        self::assertStringContainsString('nothing was deleted', $result['errors'][0]);
        self::assertNull($result['batch_id']);
    }

    /**
     * #1059 fix round 1: the audit batch is part of the transaction — written
     * after the batch flush, before the commit — so deletions and their trail
     * commit together.
     */
    #[Test]
    public function auditBatchIsWrittenAfterTheFlushAndBeforeTheCommit(): void
    {
        $tenant = $this->tenant(1);
        $asset = $this->asset(3, $tenant, 'C');
        $order = [];
        $this->em->method('flush')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'flush';
        });
        $this->em->method('commit')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'commit';
        });
        $this->auditLogger->method('logBulk')->willReturnCallback(static function (...$args) use (&$order): string {
            $order[] = 'audit' . (($args['strict'] ?? $args[5] ?? false) === true ? ':strict' : '');

            return 'b';
        });

        $result = $this->actingIn($tenant)->delete('Asset', [3], static fn (): object => $asset);

        self::assertSame(1, $result['deleted']);
        self::assertSame('b', $result['batch_id']);
        self::assertSame(['flush', 'audit:strict', 'commit'], $order);
    }

    #[Test]
    public function auditFailureRollsTheBatchBack(): void
    {
        $tenant = $this->tenant(1);
        $asset = $this->asset(3, $tenant, 'C');
        $this->auditLogger->method('logBulk')->willThrowException(new \RuntimeException('audit down'));
        $this->em->expects(self::never())->method('commit');
        $this->em->expects(self::once())->method('rollback');

        $result = $this->actingIn($tenant)->delete('Asset', [3], static fn (): object => $asset);

        self::assertSame(0, $result['deleted']);
        self::assertFalse($result['success']);
        self::assertStringContainsString('nothing was deleted', $result['errors'][0]);
        self::assertStringContainsString('audit down', $result['errors'][0]);
    }

    #[Test]
    public function domainAuditValuesAreMergedIntoThePerEntityRow(): void
    {
        $tenant = $this->tenant(1);
        $asset = $this->asset(3, $tenant, 'C');
        $captured = null;
        $this->auditLogger->method('logBulk')->willReturnCallback(static function (...$args) use (&$captured): string {
            $captured = $args['perEntityData'] ?? $args[3];

            return 'b';
        });

        $this->actingIn($tenant)->delete(
            'Asset',
            [3],
            static fn (): object => $asset,
            auditValues: static fn (object $e): array => ['reference_number' => 'REF-3'],
        );

        self::assertSame(['label' => 'C', 'reference_number' => 'REF-3'], $captured[0]['old_values']);
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
