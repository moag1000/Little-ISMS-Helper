<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\TrainingParticipation;
use App\Entity\User;
use App\EventListener\AutoReactionTrainingAudienceAssignListener;
use App\Service\AutoReactionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Junior-ISB-Audit C3-01 (S14 Cluster C, 2026-05-23) —
 * AutoReactionTrainingAudienceAssignListener tests.
 *
 * Covers:
 *   - Toggle disabled => no-op
 *   - Non-mandatory training => no-op
 *   - Training without tenant => no-op (cannot tenant-scope)
 *   - Tenant-scoped active-user query (regression guard)
 *   - Successful run persists TrainingParticipation rows
 *   - Idempotency: existing TrainingParticipation prevents duplicate
 *   - postUpdate behaves identically to postPersist
 */
#[AllowMockObjectsWithoutExpectations]
class AutoReactionTrainingAudienceAssignListenerTest extends TestCase
{
    private MockObject $reactions;
    private MockObject $logger;
    private AutoReactionTrainingAudienceAssignListener $listener;

    protected function setUp(): void
    {
        $this->reactions = $this->createMock(AutoReactionService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new AutoReactionTrainingAudienceAssignListener(
            $this->reactions,
            $this->logger,
        );
    }

    #[Test]
    public function toggleDisabledIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(false);

        $training = $this->createTraining(1, $this->createTenant(1), mandatory: true);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');

        $args = new PostPersistEventArgs($training, $em);
        $this->listener->postPersist($training, $args);
    }

    #[Test]
    public function nonMandatoryTrainingIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $training = $this->createTraining(1, $this->createTenant(1), mandatory: false);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');

        $args = new PostUpdateEventArgs($training, $em);
        $this->listener->postUpdate($training, $args);
    }

    #[Test]
    public function trainingWithoutTenantIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $training = $this->createTraining(1, null, mandatory: true);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');

        $args = new PostPersistEventArgs($training, $em);
        $this->listener->postPersist($training, $args);
    }

    #[Test]
    public function userQueryIsTenantScoped(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(7);
        $training = $this->createTraining(11, $tenant, mandatory: true);

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->expects(self::once())
            ->method('findBy')
            ->with(self::callback(static function (array $criteria) use ($tenant): bool {
                return ($criteria['isActive'] ?? null) === true
                    && ($criteria['tenant'] ?? null) === $tenant;
            }))
            ->willReturn([]);

        $participationRepo = $this->createMock(EntityRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [User::class, $userRepo],
            [TrainingParticipation::class, $participationRepo],
        ]);

        $args = new PostPersistEventArgs($training, $em);
        $this->listener->postPersist($training, $args);
    }

    #[Test]
    public function persistsTrainingParticipationForExistingUser(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(7);
        $training = $this->createTraining(42, $tenant, mandatory: true);
        $user = $this->createUser(99, $tenant);

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findBy')->willReturn([$user]);

        $participationRepo = $this->createMock(EntityRepository::class);
        $participationRepo->method('findOneBy')->willReturn(null);

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [User::class, $userRepo],
            [TrainingParticipation::class, $participationRepo],
        ]);
        $em->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            });
        $em->expects(self::once())->method('flush');

        $args = new PostUpdateEventArgs($training, $em);
        $this->listener->postUpdate($training, $args);

        self::assertInstanceOf(TrainingParticipation::class, $persisted);
        /** @var TrainingParticipation $persisted */
        self::assertSame(TrainingParticipation::STATUS_PENDING, $persisted->getStatus());
        self::assertSame($tenant, $persisted->getTenant());
        self::assertSame($user, $persisted->getUser());
        self::assertSame($training, $persisted->getTraining());
        self::assertSame('auto:mandatory_assign', $persisted->getAssignmentSource());
    }

    #[Test]
    public function existingParticipationPreventsDuplicate(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(7);
        $training = $this->createTraining(11, $tenant, mandatory: true);
        $user = $this->createUser(99, $tenant);

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findBy')->willReturn([$user]);

        $existing = new TrainingParticipation();
        $participationRepo = $this->createMock(EntityRepository::class);
        $participationRepo->method('findOneBy')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [User::class, $userRepo],
            [TrainingParticipation::class, $participationRepo],
        ]);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $args = new PostPersistEventArgs($training, $em);
        $this->listener->postPersist($training, $args);
    }

    private function createTenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $idProperty = (new \ReflectionClass($tenant))->getProperty('id');
        $idProperty->setValue($tenant, $id);
        return $tenant;
    }

    private function createTraining(int $id, ?Tenant $tenant, bool $mandatory): Training
    {
        $training = new Training();
        $idProperty = (new \ReflectionClass($training))->getProperty('id');
        $idProperty->setValue($training, $id);
        $training->setMandatory($mandatory);
        if ($tenant !== null) {
            $training->setTenant($tenant);
        }
        $training->setTitle('Test training ' . $id);
        return $training;
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
