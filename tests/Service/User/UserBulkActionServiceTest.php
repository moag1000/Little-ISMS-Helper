<?php

declare(strict_types=1);

namespace App\Tests\Service\User;

use App\Entity\User;
use App\Repository\TenantRepository;
use App\Security\UserAdministrationGuard;
use App\Service\AuditLogger;
use App\Service\InitialAdminService;
use App\Service\TenantContext;
use App\Service\User\UserBulkActionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Bulk user actions: the acting user is never deleted or deactivated, and
 * every applied change goes into one audit batch (User is not covered by
 * the AuditLogSubscriber).
 */
#[AllowMockObjectsWithoutExpectations]
final class UserBulkActionServiceTest extends TestCase
{
    private MockObject&EntityManagerInterface $em;
    private MockObject&AuditLogger $auditLogger;
    private UserBulkActionService $service;

    protected function setUp(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $attribute): bool => $attribute !== 'ROLE_SUPER_ADMIN',
        );
        $initialAdmin = $this->createMock(InitialAdminService::class);
        $initialAdmin->method('isInitialAdmin')->willReturn(false);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->service = new UserBulkActionService(
            $security,
            $this->em,
            $this->auditLogger,
            $initialAdmin,
            new UserAdministrationGuard(
                $security,
                $this->createMock(TenantContext::class),
                $this->createMock(TenantRepository::class),
                new NullLogger(),
            ),
        );
    }

    private function user(int $id): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getEmail')->willReturn('u' . $id . '@example.com');

        return $user;
    }

    #[Test]
    public function bulkDeleteRefusesTheActingUser(): void
    {
        $actor = $this->user(1);

        $this->em->expects(self::never())->method('remove');
        $this->auditLogger->expects(self::never())->method('logBulk');

        $result = $this->service->apply('delete', [$actor], $actor);

        self::assertSame(0, $result['count']);
        self::assertSame(['current_user' => 1], $result['skipped_reasons']);
        self::assertNull($result['batch_id']);
    }

    #[Test]
    public function bulkDeleteRemovesOthersAndWritesOneAuditBatch(): void
    {
        $actor = $this->user(1);
        $other = $this->user(2);

        $this->em->expects(self::once())->method('remove')->with($other);
        $this->auditLogger->expects(self::once())->method('logBulk')
            ->with(
                'bulk_user_delete',
                'User',
                self::anything(),
                self::callback(static fn (array $rows): bool => count($rows) === 1
                    && $rows[0]['entity_id'] === 2
                    && $rows[0]['action'] === 'delete'),
            )
            ->willReturn('batch-uuid');

        $result = $this->service->apply('delete', [$actor, $other], $actor);

        self::assertSame(1, $result['count']);
        self::assertSame(['current_user' => 1], $result['skipped_reasons']);
        self::assertSame('batch-uuid', $result['batch_id']);
    }

    #[Test]
    public function bulkDeactivateSkipsTheActingUser(): void
    {
        $actor = $this->user(1);
        $actor->expects(self::never())->method('setIsActive');

        $result = $this->service->apply('deactivate', [$actor], $actor);

        self::assertSame(['current_user' => 1], $result['skipped_reasons']);
    }
}
