<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\AuditFinding;
use App\Entity\CorrectiveAction;
use App\Entity\Tenant;
use App\EventListener\AutoReactionCorrectiveActionListener;
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
 * V3 W2-M5 — AutoReactionCorrectiveActionListener tests.
 *
 * Toggle, severity gating, idempotency, tenant inheritance.
 */
#[AllowMockObjectsWithoutExpectations]
class AutoReactionCorrectiveActionListenerTest extends TestCase
{
    private MockObject $reactions;
    private MockObject $logger;
    private AutoReactionCorrectiveActionListener $listener;

    protected function setUp(): void
    {
        $this->reactions = $this->createMock(AutoReactionService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new AutoReactionCorrectiveActionListener($this->reactions, $this->logger);
    }

    #[Test]
    public function toggleDisabledIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(false);

        $finding = $this->createFinding(1, $this->createTenant(1), 'observation', 'low');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $args = new PostPersistEventArgs($finding, $em);
        $this->listener->postPersist($finding, $args);
    }

    #[Test]
    public function lowSeverityIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $finding = $this->createFinding(1, $this->createTenant(1), 'observation', 'low');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $args = new PostPersistEventArgs($finding, $em);
        $this->listener->postPersist($finding, $args);
    }

    #[Test]
    public function criticalSeverityCreatesCorrectiveActionWithTenant(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(7);
        $finding = $this->createFinding(7, $tenant, 'observation', AuditFinding::SEVERITY_CRITICAL);
        $finding->setTitle('Missing log retention');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted) { $persisted[] = $e; });
        $em->method('flush');

        $args = new PostPersistEventArgs($finding, $em);
        $this->listener->postPersist($finding, $args);

        $caInstances = array_values(array_filter(
            $persisted,
            static fn($e) => $e instanceof CorrectiveAction,
        ));
        $this->assertCount(1, $caInstances);
        /** @var CorrectiveAction $ca */
        $ca = $caInstances[0];
        $this->assertSame($tenant, $ca->getTenant());
        $this->assertSame($finding, $ca->getFinding());
        $this->assertSame(CorrectiveAction::STATUS_PLANNED, $ca->getStatus());
    }

    #[Test]
    public function majorNcTypeAlsoTriggers(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(7);
        $finding = $this->createFinding(8, $tenant, AuditFinding::TYPE_MAJOR_NC, 'low');
        $finding->setTitle('Major NC');

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted) { $persisted[] = $e; });
        $em->method('flush');

        $args = new PostPersistEventArgs($finding, $em);
        $this->listener->postPersist($finding, $args);

        $caInstances = array_values(array_filter(
            $persisted,
            static fn($e) => $e instanceof CorrectiveAction,
        ));
        $this->assertCount(1, $caInstances);
    }

    #[Test]
    public function existingCorrectiveActionPreventsDuplicate(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $finding = $this->createFinding(9, $this->createTenant(7), 'observation', AuditFinding::SEVERITY_HIGH);

        $existing = $this->createMock(CorrectiveAction::class);
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects($this->never())->method('persist');

        $args = new PostPersistEventArgs($finding, $em);
        $this->listener->postPersist($finding, $args);
    }

    private function createTenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $idProperty = (new \ReflectionClass($tenant))->getProperty('id');
        $idProperty->setValue($tenant, $id);
        return $tenant;
    }

    private function createFinding(int $id, Tenant $tenant, string $type, string $severity): AuditFinding
    {
        $finding = new AuditFinding();
        $idProperty = (new \ReflectionClass($finding))->getProperty('id');
        $idProperty->setValue($finding, $id);
        $finding->setTenant($tenant);
        $finding->setType($type);
        $finding->setSeverity($severity);
        return $finding;
    }
}
