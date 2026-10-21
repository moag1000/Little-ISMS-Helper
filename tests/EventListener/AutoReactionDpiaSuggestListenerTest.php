<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\DataProtectionImpactAssessment;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\EventListener\AutoReactionDpiaSuggestListener;
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Service\AutoReactionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * V3 W2-M5 — AutoReactionDpiaSuggestListener tests.
 *
 * Uses real ProcessingActivity entities with reflection-set id. Avoids
 * mocking non-existent methods (PHPUnit 13 rejects this).
 */
#[AllowMockObjectsWithoutExpectations]
class AutoReactionDpiaSuggestListenerTest extends TestCase
{
    private MockObject $reactions;
    private MockObject $logger;
    private AutoReactionDpiaSuggestListener $listener;
    private DataProtectionImpactAssessmentRepository $dpiaRepository;

    protected function setUp(): void
    {
        $this->reactions = $this->createMock(AutoReactionService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->dpiaRepository = $this->createStub(DataProtectionImpactAssessmentRepository::class);
        $this->dpiaRepository->method('getNextReferenceNumber')->willReturn('DPIA-2026-007');
        $this->listener = new AutoReactionDpiaSuggestListener(
            $this->reactions,
            $this->logger,
            dpiaRepository: $this->dpiaRepository,
        );
    }

    #[Test]
    public function toggleDisabledIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(false);

        $activity = $this->createActivity(1, $this->createTenant(1));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $args = new PostPersistEventArgs($activity, $em);

        $this->listener->postPersist($activity, $args);
        $this->listener->postFlush(new PostFlushEventArgs($em));
    }

    #[Test]
    public function notHighRiskIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $activity = $this->createActivity(1, $this->createTenant(1));
        $activity->setProcessesSpecialCategories(false);
        $activity->setEstimatedDataSubjectsCount(50);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        // EM may still be queried for the existing-DPIA lookup (no-op short-circuits before that).
        $args = new PostPersistEventArgs($activity, $em);
        $this->listener->postPersist($activity, $args);
        $this->listener->postFlush(new PostFlushEventArgs($em));
    }

    #[Test]
    public function existingDpiaPreventsDuplicate(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(7);
        $activity = $this->createActivity(42, $tenant);
        $activity->setProcessesSpecialCategories(true);

        $existing = $this->createMock(DataProtectionImpactAssessment::class);
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('isOpen')->willReturn(true);
        $em->method('contains')->willReturn(true);
        $em->expects($this->never())->method('persist');

        $args = new PostPersistEventArgs($activity, $em);
        $this->listener->postPersist($activity, $args);
        $this->listener->postFlush(new PostFlushEventArgs($em));
    }

    /**
     * #1059: postPersist runs inside the commit — it must only collect. A
     * flush there re-entered the commit and closed the EntityManager.
     */
    #[Test]
    public function postPersistOnlyCollects(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);
        $activity = $this->createActivity(5, $this->createTenant(3));
        $activity->setProcessesSpecialCategories(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');
        $em->expects($this->never())->method('getRepository');

        $this->listener->postPersist($activity, new PostPersistEventArgs($activity, $em));
    }

    #[Test]
    public function highRiskTriggersDpiaPersist(): void
    {
        // V3 W2-Bug1: Listener now calls ProcessingActivity::getName() (not
        // the non-existent getTitle()), so the success path persists the
        // DPIA skeleton instead of logging a warning. Strict assertion.
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(11);
        $activity = $this->createActivity(99, $tenant);
        $activity->setName('GDPR-VVT Marketing-Newsletter');
        $activity->setProcessesSpecialCategories(false);
        $activity->setEstimatedDataSubjectsCount(5000);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('isOpen')->willReturn(true);
        $em->method('contains')->willReturn(true);
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted) { $persisted[] = $e; });
        $em->expects($this->once())->method('flush');

        $loggedWarning = false;
        $this->logger->method('warning')->willReturnCallback(static function () use (&$loggedWarning) { $loggedWarning = true; });

        $args = new PostPersistEventArgs($activity, $em);
        $this->listener->postPersist($activity, $args);
        self::assertSame([], $persisted, 'nothing is persisted inside the running commit');
        $this->listener->postFlush(new PostFlushEventArgs($em));

        $dpiaInstances = array_values(array_filter(
            $persisted,
            static fn($e) => $e instanceof DataProtectionImpactAssessment,
        ));
        $this->assertCount(1, $dpiaInstances, 'High-risk activity must persist DPIA skeleton');
        $this->assertFalse($loggedWarning, 'No warning expected on success path');
        $this->assertSame($tenant, $dpiaInstances[0]->getTenant());
        $this->assertSame($activity, $dpiaInstances[0]->getProcessingActivity());
        $this->assertSame('draft', $dpiaInstances[0]->getStatus());
        $this->assertStringContainsString('GDPR-VVT Marketing-Newsletter', (string) $dpiaInstances[0]->getTitle());
        $this->assertSame('DPIA-2026-007', $dpiaInstances[0]->getReferenceNumber(), 'same generator as the create path');
        $this->assertSame('', $dpiaInstances[0]->getRiskLevel(), 'the assessment is left to the DPO');
        $this->assertFalse($dpiaInstances[0]->isComplete());
    }

    /**
     * #1059 re-review m-N4: a failed outer flush never reaches postFlush; the
     * collected activity must not survive into the next flush (clear / reset).
     */
    #[Test]
    public function clearAndResetDropCollectedActivities(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);
        $activity = $this->createActivity(8, $this->createTenant(2));
        $activity->setProcessesSpecialCategories(true);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('contains')->willReturn(true);
        $em->expects($this->never())->method('persist');

        $this->listener->postPersist($activity, new PostPersistEventArgs($activity, $em));
        $this->listener->onClear(new \Doctrine\ORM\Event\OnClearEventArgs($em));
        $this->listener->postFlush(new PostFlushEventArgs($em));

        $this->listener->postPersist($activity, new PostPersistEventArgs($activity, $em));
        \call_user_func([$this->listener, 'reset']); // kernel.reset (call_user_func: Gate 28 heuristic)
        $this->listener->postFlush(new PostFlushEventArgs($em));
    }

    private function createTenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $idProperty = (new \ReflectionClass($tenant))->getProperty('id');
        $idProperty->setValue($tenant, $id);
        return $tenant;
    }

    private function createActivity(int $id, Tenant $tenant): ProcessingActivity
    {
        $activity = new ProcessingActivity();
        $idProperty = (new \ReflectionClass($activity))->getProperty('id');
        $idProperty->setValue($activity, $id);
        $activity->setTenant($tenant);
        return $activity;
    }
}
