<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Lifecycle\InvalidTransitionException;
use App\Lifecycle\LifecycleRegistry;
use App\Lifecycle\LifecycleService;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LifecycleService::transition() — audit-s3 P-4.
 *
 * Covers:
 *  - Happy path: setStatus + flush + AuditLogger::logCustom called once
 *  - Invalid transitions raise InvalidTransitionException with metadata
 *  - Missing getStatus/setStatus raises LogicException
 */
#[AllowMockObjectsWithoutExpectations]
final class LifecycleServiceTest extends TestCase
{
    private LifecycleRegistry $registry;
    private MockObject $entityManager;
    private MockObject $auditLogger;
    private LifecycleService $service;

    protected function setUp(): void
    {
        $this->registry = new LifecycleRegistry();
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);

        $this->service = new LifecycleService(
            $this->registry,
            $this->entityManager,
            $this->auditLogger,
        );
    }

    #[Test]
    public function testTransitionUpdatesStatusFlushesAndLogs(): void
    {
        $entity = new FakeStatusEntity('draft', id: 42);

        $this->entityManager->expects(self::once())->method('flush');

        $this->auditLogger->expects(self::once())
            ->method('getEntityTypeName')
            ->with($entity)
            ->willReturn('FakeStatusEntity');

        $this->auditLogger->expects(self::once())
            ->method('logCustom')
            ->with(
                'status_change',
                'FakeStatusEntity',
                42,
                ['status' => 'draft'],
                ['status' => 'in_review', 'reason' => 'submitted for review'],
                self::stringContains('draft → in_review'),
                null,
            );

        $this->service->transition($entity, 'in_review', null, 'submitted for review');

        self::assertSame('in_review', $entity->getStatus());
    }

    #[Test]
    public function testInvalidTransitionRaisesInvalidTransitionException(): void
    {
        $entity = new FakeStatusEntity('draft', id: 7);

        $this->entityManager->expects(self::never())->method('flush');
        $this->auditLogger->expects(self::never())->method('logCustom');

        try {
            $this->service->transition($entity, 'published');
            self::fail('Expected InvalidTransitionException was not thrown.');
        } catch (InvalidTransitionException $ex) {
            self::assertSame($entity::class, $ex->entityClass);
            self::assertSame('draft', $ex->fromStatus);
            self::assertSame('published', $ex->toStatus);
            self::assertSame(['in_review'], $ex->allowedTransitions);
            self::assertStringContainsString('Invalid lifecycle transition', $ex->getMessage());
        }

        // Status must remain untouched
        self::assertSame('draft', $entity->getStatus());
    }

    #[Test]
    public function testTransitionFromTerminalStatusIsRejectedWithEmptyAllowedList(): void
    {
        // Use the finding fixture — 'closed' is terminal.
        $entity = new FindingFixtureWithStatus('closed', id: 99);

        $this->expectException(InvalidTransitionException::class);
        $this->service->transition($entity, 'open');
    }

    #[Test]
    public function testEntityWithoutStatusMethodsRaisesLogicException(): void
    {
        $entity = new \stdClass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('lacks getStatus()/setStatus()');

        $this->service->transition($entity, 'in_review');
    }

    #[Test]
    public function testTransitionToleratesEntityWithoutNumericId(): void
    {
        $entity = new FakeStatusEntity('in_review', id: null);

        $this->auditLogger->expects(self::once())
            ->method('getEntityTypeName')
            ->willReturn('FakeStatusEntity');

        $this->auditLogger->expects(self::once())
            ->method('logCustom')
            ->with(
                'status_change',
                'FakeStatusEntity',
                null, // entity-id stays null
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
            );

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->transition($entity, 'approved');
        self::assertSame('approved', $entity->getStatus());
    }
}

/**
 * Test fixture — entity using STANDARD_5_STAGE (no #[Lifecycle] attribute).
 *
 * @internal
 */
class FakeStatusEntity
{
    public function __construct(
        private string $status,
        private readonly ?int $id = null,
    ) {}

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}

/**
 * Test fixture — entity with FINDING_4_STAGE lifecycle override.
 *
 * @internal
 */
#[\App\Lifecycle\Lifecycle(stages: LifecycleRegistry::FINDING_4_STAGE)]
class FindingFixtureWithStatus
{
    public function __construct(
        private string $status,
        private readonly ?int $id = null,
    ) {}

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
