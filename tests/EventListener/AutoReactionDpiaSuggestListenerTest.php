<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\DataProtectionImpactAssessment;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\EventListener\AutoReactionDpiaSuggestListener;
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

    protected function setUp(): void
    {
        $this->reactions = $this->createMock(AutoReactionService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new AutoReactionDpiaSuggestListener($this->reactions, $this->logger);
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
        $em->expects($this->never())->method('persist');

        $args = new PostPersistEventArgs($activity, $em);
        $this->listener->postPersist($activity, $args);
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
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted) { $persisted[] = $e; });

        $loggedWarning = false;
        $this->logger->method('warning')->willReturnCallback(static function () use (&$loggedWarning) { $loggedWarning = true; });

        $args = new PostPersistEventArgs($activity, $em);
        $this->listener->postPersist($activity, $args);

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
