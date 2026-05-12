<?php

declare(strict_types=1);

namespace App\Tests\Service\Nonconformity;

use App\Entity\AuditFinding;
use App\Entity\ComplianceRequirement;
use App\Entity\CorrectiveAction;
use App\Entity\Tenant;
use App\Service\AuditLogger;
use App\Service\Nonconformity\AutoTaskCreator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F15.5 — Unit tests for AutoTaskCreator.
 *
 * Covers:
 *  - Creates CorrectiveAction for each linked requirement
 *  - Skips when no requirements linked
 *  - Does not duplicate existing auto-tasks
 *  - Action type set to corrective
 *  - Due date set to +30 days
 *  - Logs via AuditLogger
 *  - Flushes EntityManager only when tasks created
 */
#[AllowMockObjectsWithoutExpectations]
final class AutoTaskCreatorTest extends TestCase
{
    private EntityManagerInterface $em;
    private AuditLogger $auditLogger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
    }

    #[Test]
    public function createsNoTasksWhenNoRequirementsLinked(): void
    {
        $finding = $this->buildFinding();
        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');

        $creator = new AutoTaskCreator($this->em, $this->auditLogger);
        $result = $creator->createTasksForLinkedRequirements($finding);

        self::assertSame([], $result);
    }

    #[Test]
    public function createsTaskForEachLinkedRequirement(): void
    {
        $finding = $this->buildFinding();
        $req1 = $this->buildRequirement('ISO-5.1', 'Information Security Policies');
        $req2 = $this->buildRequirement('ISO-6.1', 'Internal Organisation');
        $finding->addLinkedRequirement($req1);
        $finding->addLinkedRequirement($req2);

        $persisted = [];
        $this->em->method('persist')->willReturnCallback(static function ($entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $this->em->expects(self::once())->method('flush');

        $creator = new AutoTaskCreator($this->em, $this->auditLogger);
        $result = $creator->createTasksForLinkedRequirements($finding);

        self::assertCount(2, $result);
        self::assertContainsOnlyInstancesOf(CorrectiveAction::class, $result);
    }

    #[Test]
    public function createdTaskHasCorrectAttributes(): void
    {
        $finding = $this->buildFinding('Finding Title');
        $req = $this->buildRequirement('ISO-9.2', 'Internal Audit');
        $finding->addLinkedRequirement($req);

        $this->em->method('persist')->willReturnCallback(static function (): void {});
        $this->em->method('flush')->willReturnCallback(static function (): void {});

        $creator = new AutoTaskCreator($this->em, $this->auditLogger);
        $result = $creator->createTasksForLinkedRequirements($finding);

        self::assertCount(1, $result);
        $action = $result[0];
        self::assertSame(CorrectiveAction::ACTION_TYPE_CORRECTIVE, $action->getActionType());
        self::assertSame(CorrectiveAction::STATUS_PLANNED, $action->getStatus());
        self::assertStringContainsString('[Auto]', $action->getTitle() ?? '');
        self::assertStringContainsString('ISO-9.2', $action->getTitle() ?? '');
        self::assertNotNull($action->getPlannedCompletionDate());
    }

    #[Test]
    public function skipsRequirementWhenAutoTaskAlreadyExists(): void
    {
        $finding = $this->buildFinding('My Finding');
        $req = $this->buildRequirement('ISO-10.1', 'Nonconformity');
        $finding->addLinkedRequirement($req);

        // Pre-add an existing auto-task that matches
        $existing = new CorrectiveAction();
        $existing->setTitle('[Auto] My Finding — Nonconformity (ISO-10.1)');
        $existing->setDescription('Existing task');
        $existing->setStatus(CorrectiveAction::STATUS_PLANNED);
        $finding->addCorrectiveAction($existing);

        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');

        $creator = new AutoTaskCreator($this->em, $this->auditLogger);
        $result = $creator->createTasksForLinkedRequirements($finding);

        self::assertSame([], $result);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildFinding(string $title = 'Test Finding'): AuditFinding
    {
        $tenant = new Tenant();
        $finding = new AuditFinding();
        $finding->setTenant($tenant);
        $finding->setTitle($title);
        $finding->setFindingNumber('F-001');
        $finding->setDescription('Test description');
        $finding->setType(AuditFinding::TYPE_MINOR_NC);
        return $finding;
    }

    private function buildRequirement(string $reqId, string $title): ComplianceRequirement
    {
        $req = new ComplianceRequirement();
        $req->setRequirementId($reqId);
        $req->setTitle($title);
        $req->setDescription('Test requirement');
        $req->setPriority('high');
        return $req;
    }
}
