<?php

declare(strict_types=1);

namespace App\Tests\Functional;

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
 * F15.3 — Flow test: AuditFinding + linkedRequirements → CorrectiveActions.
 *
 * Verifies that the AutoTaskCreator integration works end-to-end:
 * - AuditFinding.linkedRequirements M2M is populated
 * - AutoTaskCreator creates CorrectiveAction per linked requirement
 * - Auto-tasks have correct type, status, and title
 * - Duplicate runs are idempotent (no duplicate tasks)
 * - Findings without requirements produce zero tasks
 */
#[AllowMockObjectsWithoutExpectations]
final class NonconformityLinkingFlowTest extends TestCase
{
    private EntityManagerInterface $em;
    private AuditLogger $auditLogger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
    }

    #[Test]
    public function findingWithLinkedRequirementsProducesCorrectiveActions(): void
    {
        $finding = $this->buildFinding('Access Control Failure');
        $req1 = $this->buildRequirement('ISO-5.15', 'Access Control');
        $req2 = $this->buildRequirement('ISO-8.3', 'Information Access Restriction');
        $finding->addLinkedRequirement($req1);
        $finding->addLinkedRequirement($req2);

        $persisted = [];
        $this->em->method('persist')->willReturnCallback(static function ($e) use (&$persisted): void {
            $persisted[] = $e;
        });
        $this->em->method('flush')->willReturnCallback(static function (): void {});

        $creator = new AutoTaskCreator($this->em, $this->auditLogger);
        $tasks = $creator->createTasksForLinkedRequirements($finding);

        self::assertCount(2, $tasks, 'One task per linked requirement expected');
        self::assertContainsOnlyInstancesOf(CorrectiveAction::class, $tasks);

        foreach ($tasks as $task) {
            self::assertSame(CorrectiveAction::ACTION_TYPE_CORRECTIVE, $task->getActionType());
            self::assertSame(CorrectiveAction::STATUS_PLANNED, $task->getStatus());
            self::assertStringContainsString('[Auto]', $task->getTitle() ?? '');
            self::assertStringContainsString('Access Control Failure', $task->getTitle() ?? '');
            self::assertNotNull($task->getPlannedCompletionDate());
        }
    }

    #[Test]
    public function findingWithoutLinkedRequirementsProducesNoTasks(): void
    {
        $finding = $this->buildFinding('No Requirements');
        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');

        $creator = new AutoTaskCreator($this->em, $this->auditLogger);
        $tasks = $creator->createTasksForLinkedRequirements($finding);

        self::assertSame([], $tasks);
    }

    #[Test]
    public function secondSaveDoesNotDuplicateTasks(): void
    {
        $finding = $this->buildFinding('Password Policy Violation');
        $req = $this->buildRequirement('ISO-9.4', 'System and Application Access Control');
        $finding->addLinkedRequirement($req);

        // Simulate first save — task already exists
        $existing = new CorrectiveAction();
        $existing->setTitle('[Auto] Password Policy Violation — System and Application Access Control (ISO-9.4)');
        $existing->setStatus(CorrectiveAction::STATUS_PLANNED);
        $finding->addCorrectiveAction($existing);

        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');

        $creator = new AutoTaskCreator($this->em, $this->auditLogger);
        $tasks = $creator->createTasksForLinkedRequirements($finding);

        self::assertSame([], $tasks, 'No duplicate tasks should be created on re-save');
    }

    #[Test]
    public function taskTitleContainsRequirementId(): void
    {
        $finding = $this->buildFinding('Encryption Gap');
        $req = $this->buildRequirement('ISO-8.24', 'Use of Cryptography');
        $finding->addLinkedRequirement($req);

        $this->em->method('persist')->willReturnCallback(static function (): void {});
        $this->em->method('flush')->willReturnCallback(static function (): void {});

        $creator = new AutoTaskCreator($this->em, $this->auditLogger);
        $tasks = $creator->createTasksForLinkedRequirements($finding);

        self::assertCount(1, $tasks);
        self::assertStringContainsString('ISO-8.24', $tasks[0]->getTitle() ?? '');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildFinding(string $title = 'Test Finding'): AuditFinding
    {
        $tenant = new Tenant();
        $finding = new AuditFinding();
        $finding->setTenant($tenant);
        $finding->setTitle($title);
        $finding->setFindingNumber('F-' . rand(100, 999));
        $finding->setDescription('Functional test finding');
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
