<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\TrainingParticipation;
use App\Entity\User;
use App\EventListener\AutoReactionTrainingAssignListener;
use App\Service\AutoReactionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\PostPersistEventArgs;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * V3 W2-C3 — AutoReactionTrainingAssignListener tests.
 *
 * Covers:
 *   - Toggle disabled => no-op
 *   - User without tenant => no-op (cannot tenant-scope)
 *   - findBy on Training is tenant-scoped (regression guard for W2-C3)
 *   - Successful run persists TrainingParticipation rows
 *   - Idempotency: existing TrainingParticipation prevents duplicate
 */
#[AllowMockObjectsWithoutExpectations]
class AutoReactionTrainingAssignListenerTest extends TestCase
{
    private MockObject $reactions;
    private MockObject $logger;
    private AutoReactionTrainingAssignListener $listener;

    protected function setUp(): void
    {
        $this->reactions = $this->createMock(AutoReactionService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new AutoReactionTrainingAssignListener($this->reactions, $this->logger);
    }

    #[Test]
    public function toggleDisabledIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(false);

        $user = $this->createUser(1, $this->createTenant(1));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');

        $args = new PostPersistEventArgs($user, $em);
        $this->listener->postPersist($user, $args);
    }

    #[Test]
    public function userWithoutTenantIsNoOp(): void
    {
        // Audit V3 W2-C3 regression guard: must not query without tenant scope.
        $this->reactions->method('isEnabled')->willReturn(true);

        $user = $this->createUser(1, null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');

        $args = new PostPersistEventArgs($user, $em);
        $this->listener->postPersist($user, $args);
    }

    #[Test]
    public function trainingFindByIsTenantScoped(): void
    {
        // Audit V3 W2-C3 critical regression: findBy MUST include 'tenant'.
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(7);
        $user = $this->createUser(1, $tenant);

        $trainingRepo = $this->createMock(EntityRepository::class);
        $trainingRepo->expects($this->once())
            ->method('findBy')
            ->with(self::callback(static function (array $criteria) use ($tenant): bool {
                return ($criteria['mandatory'] ?? null) === true
                    && ($criteria['tenant'] ?? null) === $tenant;
            }))
            ->willReturn([]);

        $participationRepo = $this->createMock(EntityRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [Training::class, $trainingRepo],
            [TrainingParticipation::class, $participationRepo],
        ]);

        $args = new PostPersistEventArgs($user, $em);
        $this->listener->postPersist($user, $args);
    }

    #[Test]
    public function persistsTrainingParticipationRow(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(7);
        $user = $this->createUser(42, $tenant);

        $training = $this->createMock(Training::class);

        $trainingRepo = $this->createMock(EntityRepository::class);
        $trainingRepo->method('findBy')->willReturn([$training]);

        $participationRepo = $this->createMock(EntityRepository::class);
        // No existing assignment.
        $participationRepo->method('findOneBy')->willReturn(null);

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [Training::class, $trainingRepo],
            [TrainingParticipation::class, $participationRepo],
        ]);
        $em->expects($this->once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            });
        $em->expects($this->once())->method('flush');

        $args = new PostPersistEventArgs($user, $em);
        $this->listener->postPersist($user, $args);

        self::assertInstanceOf(TrainingParticipation::class, $persisted);
        /** @var TrainingParticipation $persisted */
        self::assertSame(TrainingParticipation::STATUS_PENDING, $persisted->getStatus());
        self::assertSame($tenant, $persisted->getTenant());
        self::assertSame($user, $persisted->getUser());
        self::assertSame($training, $persisted->getTraining());
        self::assertSame('auto:user_create', $persisted->getAssignmentSource());
    }

    #[Test]
    public function existingAssignmentPreventsDuplicate(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(7);
        $user = $this->createUser(7, $tenant);

        $training = $this->createMock(Training::class);

        $trainingRepo = $this->createMock(EntityRepository::class);
        $trainingRepo->method('findBy')->willReturn([$training]);

        $existing = new TrainingParticipation();
        $participationRepo = $this->createMock(EntityRepository::class);
        $participationRepo->method('findOneBy')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [Training::class, $trainingRepo],
            [TrainingParticipation::class, $participationRepo],
        ]);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $args = new PostPersistEventArgs($user, $em);
        $this->listener->postPersist($user, $args);
    }

    private function createTenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $idProperty = (new \ReflectionClass($tenant))->getProperty('id');
        $idProperty->setValue($tenant, $id);
        return $tenant;
    }

    private function createUser(int $id, ?Tenant $tenant): User
    {
        $user = new User();
        $idProperty = (new \ReflectionClass($user))->getProperty('id');
        $idProperty->setValue($user, $id);
        $user->setEmail('user' . $id . '@example.com');
        $user->setRoles(['ROLE_USER']);
        if ($tenant !== null) {
            $user->setTenant($tenant);
        }
        return $user;
    }
}
