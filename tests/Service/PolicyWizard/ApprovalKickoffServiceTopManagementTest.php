<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Command\SeedPolicyApprovalWorkflowCommand;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Repository\UserRepository;
use App\Repository\WorkflowRepository;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\ApprovalKickoffService;
use App\Service\PolicyWizard\WizardStepKeys;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard — ApprovalKickoffService GF-routing unit tests.
 *
 * User-mandate (2026-05-08): "müsste die Erstellung der Dokumente nicht
 * einen Freigabeflow für die GF erzeugen (wenn Geschäftsführer Nutzer
 * vorhanden)".
 *
 * Verifies the routing branch added to {@see ApprovalKickoffService::kickoff}:
 *  - active ROLE_TOP_MGMT user present  → approval-history + audit event
 *  - no ROLE_TOP_MGMT user               → fallback to default chain (no extra event)
 *  - audit-event payload structure       → user_id / document_id / wizard_run_id
 */
#[AllowMockObjectsWithoutExpectations]
final class ApprovalKickoffServiceTopManagementTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    /** @var list<array{action: string, payload: array<string, mixed>|null}> */
    private array $auditEvents = [];

    protected function setUp(): void
    {
        $this->persisted = [];
        $this->auditEvents = [];
    }

    private function makeTenant(int $id = 21): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        return $stub;
    }

    private function makeUser(int $id, bool $isTopMgmt = false): User
    {
        $stub = $this->createStub(User::class);
        $stub->method('getId')->willReturn($id);
        if ($isTopMgmt) {
            $stub->method('getRoles')->willReturn(['ROLE_TOP_MGMT', 'ROLE_USER']);
        } else {
            $stub->method('getRoles')->willReturn(['ROLE_USER']);
        }
        return $stub;
    }

    private function makeWorkflow(): Workflow
    {
        $workflow = new Workflow();
        $workflow->setName(SeedPolicyApprovalWorkflowCommand::WORKFLOW_NAME);
        $workflow->setEntityType(SeedPolicyApprovalWorkflowCommand::WORKFLOW_ENTITY_TYPE);
        $workflow->setIsActive(true);

        foreach (['prepared', 'ciso_review', 'dpo_cross_check', 'function_owner_review', 'top_mgmt_signoff', 'published'] as $idx => $name) {
            $step = new WorkflowStep();
            $step->setName($name);
            $step->setStepOrder($idx + 1);
            $reflection = new \ReflectionProperty(WorkflowStep::class, 'id');
            $reflection->setValue($step, ($idx + 1) * 10);
            $workflow->addStep($step);
        }

        $reflection = new \ReflectionProperty(Workflow::class, 'id');
        $reflection->setValue($workflow, 7);

        return $workflow;
    }

    private function makeDocument(int $id, ?Tenant $tenant, ?WizardRun $run): Document
    {
        $document = new Document();
        $document->setTenant($tenant);
        if ($run !== null) {
            $document->setGeneratedFromWizardRun($run);
        }
        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($document, $id);
        return $document;
    }

    private function makeWizardRun(int $id = 99): WizardRun
    {
        $run = new WizardRun();
        $run->setMode(WizardStepKeys::MODE_FULL);
        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, $id);
        return $run;
    }

    /**
     * @param list<User>|null $topMgmtUsers null → no UserRepository wired (legacy path)
     */
    private function makeService(?array $topMgmtUsers): ApprovalKickoffService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
            if ($entity instanceof WorkflowInstance && $entity->getId() === null) {
                $reflection = new \ReflectionProperty(WorkflowInstance::class, 'id');
                $reflection->setValue($entity, count($this->persisted) + 200);
            }
        });
        $em->method('flush');

        $workflowRepo = $this->createMock(WorkflowRepository::class);
        $workflowRepo->method('findOneBy')->willReturn($this->makeWorkflow());

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->method('logCustom')->willReturnCallback(
            function (string $action, string $entityType, ?int $entityId, ?array $oldValues, ?array $newValues): void {
                $this->auditEvents[] = ['action' => $action, 'payload' => $newValues];
            },
        );

        $userRepo = null;
        if ($topMgmtUsers !== null) {
            $userRepo = $this->createMock(UserRepository::class);
            $userRepo->method('findByRoleInTenant')->willReturn($topMgmtUsers);
        }

        return new ApprovalKickoffService(
            $em,
            $workflowRepo,
            $auditLogger,
            null, // tenantSettingResolver — not relevant here
            new \Psr\Log\NullLogger(),
            null, // entityTagRepository
            $userRepo,
        );
    }

    #[Test]
    public function testApproverIsTopManagementUserWhenPresent(): void
    {
        $gf = $this->makeUser(501, isTopMgmt: true);
        $service = $this->makeService([$gf]);
        $document = $this->makeDocument(701, $this->makeTenant(), $this->makeWizardRun());

        $instance = $service->kickoff($document, $this->makeUser(9));

        self::assertNotNull($instance);
        $history = $instance->getApprovalHistory() ?? [];
        $routing = null;
        foreach ($history as $entry) {
            if (($entry['event'] ?? null) === 'approval_routed_to_top_management') {
                $routing = $entry;
                break;
            }
        }
        self::assertNotNull($routing, 'approval-history must contain approval_routed_to_top_management entry when GF user exists');
        self::assertSame(501, $routing['top_management_user_id'] ?? null);
        self::assertSame(701, $routing['document_id'] ?? null);
    }

    #[Test]
    public function testFallsBackToDefaultWhenNoTopManagement(): void
    {
        // Empty list AND no UserRepo wired both must keep the default chain.
        $service = $this->makeService([]);
        $document = $this->makeDocument(702, $this->makeTenant(), $this->makeWizardRun());

        $instance = $service->kickoff($document, $this->makeUser(10));

        self::assertNotNull($instance);
        $history = $instance->getApprovalHistory() ?? [];
        foreach ($history as $entry) {
            self::assertNotSame(
                'approval_routed_to_top_management',
                $entry['event'] ?? null,
                'fallback path must NOT emit a top-management routing entry',
            );
        }
        // currentStep is still ciso_review — default flow untouched.
        self::assertSame('ciso_review', $instance->getCurrentStep()?->getName());
    }

    #[Test]
    public function testAuditLogEmittedOnTopManagementRoute(): void
    {
        $gf = $this->makeUser(502, isTopMgmt: true);
        $service = $this->makeService([$gf]);
        $document = $this->makeDocument(703, $this->makeTenant(), $this->makeWizardRun(id: 11));

        $service->kickoff($document, $this->makeUser(11));

        $routedEvents = array_filter(
            $this->auditEvents,
            static fn (array $e): bool => $e['action'] === 'policy_wizard.approval_routed_to_top_management',
        );
        self::assertCount(1, $routedEvents, 'exactly one approval_routed_to_top_management audit event expected');

        $event = array_values($routedEvents)[0];
        $payload = $event['payload'] ?? [];
        self::assertSame(502, $payload['user_id'] ?? null);
        self::assertSame(703, $payload['document_id'] ?? null);
        self::assertSame(11, $payload['wizard_run_id'] ?? null);
    }
}
