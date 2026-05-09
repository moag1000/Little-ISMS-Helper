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
use App\Repository\WorkflowRepository;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\ApprovalKickoffService;
use App\Service\PolicyWizard\WizardStepKeys;
use App\Service\TenantSettingResolver\OverrideMode;
use App\Service\TenantSettingResolver\SettingResolutionResult;
use App\Service\TenantSettingResolver\TenantSettingResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W3-I — ApprovalKickoffService unit tests.
 *
 * Exercises the production-trigger gap closure: every emitted Document
 * yields a `policy-approval` WorkflowInstance dispatched at the
 * `prepared` step and immediately advanced to `ciso_review` (§9.1).
 */
#[AllowMockObjectsWithoutExpectations]
final class ApprovalKickoffServiceTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->persisted = [];
    }

    private function makeTenant(int $id = 11): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        return $stub;
    }

    private function makeUser(int $id = 9): User
    {
        $stub = $this->createStub(User::class);
        $stub->method('getId')->willReturn($id);
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
            // Inject synthetic id so addCompletedStep can int-cast safely.
            $reflection = new \ReflectionProperty(WorkflowStep::class, 'id');
            $reflection->setValue($step, ($idx + 1) * 10);
            $workflow->addStep($step);
        }

        $reflection = new \ReflectionProperty(Workflow::class, 'id');
        $reflection->setValue($workflow, 5);

        return $workflow;
    }

    private function makeDocument(
        int $id,
        ?Tenant $tenant = null,
        ?WizardRun $run = null,
    ): Document {
        $document = new Document();
        $document->setTenant($tenant);
        if ($run !== null) {
            $document->setGeneratedFromWizardRun($run);
        }
        $reflection = new \ReflectionProperty(Document::class, 'id');
        $reflection->setValue($document, $id);
        return $document;
    }

    private function makeWizardRun(string $mode = WizardStepKeys::MODE_FULL, int $id = 42): WizardRun
    {
        $run = new WizardRun();
        $run->setMode($mode);
        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, $id);
        return $run;
    }

    /**
     * @param array<string, mixed> $opts
     */
    private function makeService(array $opts = []): ApprovalKickoffService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
            // Stamp synthetic id so audit-log calls reading getId() see a value.
            if ($entity instanceof WorkflowInstance && $entity->getId() === null) {
                $reflection = new \ReflectionProperty(WorkflowInstance::class, 'id');
                $reflection->setValue($entity, count($this->persisted) + 100);
            }
        });
        $em->method('flush');

        $workflowRepo = $this->createMock(WorkflowRepository::class);
        $workflow = $opts['workflow'] ?? $this->makeWorkflow();
        if ($workflow === false) {
            $workflowRepo->method('findOneBy')->willReturn(null);
        } else {
            $workflowRepo->method('findOneBy')->willReturn($workflow);
        }

        $auditLogger = $this->createMock(AuditLogger::class);
        if (isset($opts['audit_expects_count'])) {
            $auditLogger->expects(self::exactly($opts['audit_expects_count']))->method('logCustom');
        } else {
            $auditLogger->method('logCustom');
        }

        $resolver = null;
        if (isset($opts['dual_signoff'])) {
            $resolver = $this->createMock(TenantSettingResolver::class);
            $resolver->method('resolveFor')->willReturn(new SettingResolutionResult(
                value: $opts['dual_signoff'],
                sourceTenantId: 1,
                effectiveMode: OverrideMode::ForbiddenToRelax,
            ));
        }

        return new ApprovalKickoffService(
            $em,
            $workflowRepo,
            $auditLogger,
            $resolver,
        );
    }

    #[Test]
    public function testKickoffCreatesWorkflowInstance(): void
    {
        $service = $this->makeService();
        $tenant = $this->makeTenant();
        $document = $this->makeDocument(101, $tenant, $this->makeWizardRun());
        $instance = $service->kickoff($document, $this->makeUser());

        self::assertInstanceOf(WorkflowInstance::class, $instance);
        self::assertSame('Document', $instance->getEntityType());
        self::assertSame(101, $instance->getEntityId());
        self::assertSame($tenant, $instance->getTenant());

        $persistedInstances = array_filter(
            $this->persisted,
            static fn (object $o): bool => $o instanceof WorkflowInstance,
        );
        self::assertCount(1, $persistedInstances);
    }

    #[Test]
    public function testKickoffSetsInitialStepPrepared(): void
    {
        $service = $this->makeService();
        $document = $this->makeDocument(102, $this->makeTenant(), $this->makeWizardRun());
        $instance = $service->kickoff($document, $this->makeUser());

        self::assertNotNull($instance);
        // `prepared` is auto-completed during kickoff so its step id
        // ends up in completedSteps. With the makeWorkflow() seed,
        // `prepared`'s synthetic id is 10 (idx 0 → (0+1) * 10).
        self::assertContains(10, $instance->getCompletedSteps() ?? []);
    }

    #[Test]
    public function testKickoffTransitionsToCisoReview(): void
    {
        $service = $this->makeService();
        $document = $this->makeDocument(103, $this->makeTenant(), $this->makeWizardRun());
        $instance = $service->kickoff($document, $this->makeUser());

        self::assertNotNull($instance);
        self::assertSame('ciso_review', $instance->getCurrentStep()?->getName());

        $history = $instance->getApprovalHistory() ?? [];
        $kickoff = $history[0] ?? null;
        self::assertIsArray($kickoff);
        self::assertSame('kickoff', $kickoff['event'] ?? null);
        self::assertSame('prepared', $kickoff['from_step'] ?? null);
        self::assertSame('ciso_review', $kickoff['to_step'] ?? null);
    }

    #[Test]
    public function testKickoffSkippedInSandboxMode(): void
    {
        $service = $this->makeService(['audit_expects_count' => 0]);
        $document = $this->makeDocument(
            104,
            $this->makeTenant(),
            $this->makeWizardRun(WizardStepKeys::MODE_SANDBOX),
        );
        $instance = $service->kickoff($document, $this->makeUser());

        self::assertNull($instance, 'sandbox runs must NOT dispatch a workflow');
        $persistedInstances = array_filter(
            $this->persisted,
            static fn (object $o): bool => $o instanceof WorkflowInstance,
        );
        self::assertCount(0, $persistedInstances);
    }

    #[Test]
    public function testKickoffRecordsAuditLogEntry(): void
    {
        $service = $this->makeService(['audit_expects_count' => 1]);
        $document = $this->makeDocument(105, $this->makeTenant(), $this->makeWizardRun());
        $service->kickoff($document, $this->makeUser());
    }

    #[Test]
    public function testKickoffSilentlySkipsWhenWorkflowNotSeeded(): void
    {
        $service = $this->makeService([
            'workflow' => false, // simulate WorkflowRepository::findOneBy → null
            'audit_expects_count' => 0,
        ]);
        $document = $this->makeDocument(106, $this->makeTenant(), $this->makeWizardRun());
        $instance = $service->kickoff($document, $this->makeUser());

        self::assertNull($instance, 'unseeded workflow must skip silently (logger.warning only)');
        self::assertSame([], $this->persisted, 'no WorkflowInstance persisted when not seeded');
    }

    #[Test]
    public function testKickoffTagsDualSignoffForRegulatedTenants(): void
    {
        $service = $this->makeService(['dual_signoff' => true]);
        $document = $this->makeDocument(107, $this->makeTenant(), $this->makeWizardRun());
        $instance = $service->kickoff($document, $this->makeUser());

        self::assertNotNull($instance);
        $history = $instance->getApprovalHistory() ?? [];
        $dualEntry = null;
        foreach ($history as $entry) {
            if (($entry['event'] ?? null) === 'dual_signoff_enforced') {
                $dualEntry = $entry;
                break;
            }
        }
        self::assertNotNull($dualEntry, 'dual_signoff_enforced history entry must be present for DORA-tenant');
        self::assertTrue($dualEntry['bulk_approval_dual_signoff'] ?? false);
    }
}
