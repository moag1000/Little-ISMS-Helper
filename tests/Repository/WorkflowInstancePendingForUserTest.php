<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Repository\WorkflowInstanceRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Waiting for my decision" follows WorkflowService::canUserApprove(): explicit
 * approver ids (stored as integers), the approver role through the role
 * hierarchy, and ROLE_ADMIN. The former SQL match missed the first two.
 */
final class WorkflowInstancePendingForUserTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private WorkflowInstanceRepository $repository;
    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(WorkflowInstanceRepository::class);

        $uid = uniqid('wpu_', true);
        $this->tenant = (new Tenant())->setName('Pending for user ' . $uid)->setCode('wpu_' . substr($uid, -10));
        $this->em->persist($this->tenant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $tenantId = $this->tenant?->getId();
        if ($tenantId !== null) {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM workflow_instances WHERE tenant_id = ?', [$tenantId]);
            $conn->executeStatement(
                'DELETE FROM workflow_steps WHERE workflow_id IN (SELECT id FROM workflows WHERE tenant_id = ?)',
                [$tenantId],
            );
            $conn->executeStatement('DELETE FROM workflows WHERE tenant_id = ?', [$tenantId]);
            $conn->executeStatement('DELETE FROM users WHERE tenant_id = ?', [$tenantId]);
            $conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$tenantId]);
        }
        $this->tenant = null;

        parent::tearDown();
    }

    #[Test]
    public function explicitlyAssignedApproverSeesTheApproval(): void
    {
        $approver = $this->user('assigned', ['ROLE_USER']);
        $instance = $this->instance(approverRole: 'ROLE_DPO', approverUsers: [$approver->getId()]);

        self::assertSame([$instance->getId()], $this->pendingIds($approver));
        self::assertSame([], $this->pendingIds($this->user('other', ['ROLE_USER'])));
    }

    #[Test]
    public function approverRoleCountsThroughTheRoleHierarchy(): void
    {
        $instance = $this->instance(approverRole: 'ROLE_AUDITOR');

        self::assertSame([$instance->getId()], $this->pendingIds($this->user('manager', ['ROLE_MANAGER'])));
        self::assertSame([], $this->pendingIds($this->user('plain', ['ROLE_USER'])));
    }

    #[Test]
    public function adminMayDecideEveryOpenApproval(): void
    {
        $instance = $this->instance(approverRole: 'ROLE_DPO');

        self::assertSame([$instance->getId()], $this->pendingIds($this->user('admin', ['ROLE_ADMIN'])));
    }

    #[Test]
    public function finishedWorkflowsAreNotPending(): void
    {
        $approver = $this->user('assigned', ['ROLE_USER']);
        $instance = $this->instance(approverRole: 'ROLE_DPO', approverUsers: [$approver->getId()]);
        $instance->setStatus('approved'); // fixture: closed instance
        $this->em->flush();

        self::assertSame([], $this->pendingIds($approver));
    }

    /** @return list<int|null> */
    private function pendingIds(User $user): array
    {
        return array_map(
            static fn (WorkflowInstance $i): ?int => $i->getId(),
            $this->repository->findPendingForUser($user, $this->tenant),
        );
    }

    /** @param list<string> $roles */
    private function user(string $name, array $roles): User
    {
        $user = new User();
        $user->setEmail($name . '_' . uniqid('', true) . '@example.com');
        $user->setFirstName(ucfirst($name));
        $user->setLastName('Test');
        $user->setRoles($roles);
        $user->setPassword('hashed_password');
        $user->setTenant($this->tenant);
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @param list<int|null> $approverUsers */
    private function instance(?string $approverRole, array $approverUsers = []): WorkflowInstance
    {
        $workflow = new Workflow();
        $workflow->setName('Pending for user ' . uniqid('', true));
        $workflow->setEntityType('Risk');
        $workflow->setTenant($this->tenant);
        $this->em->persist($workflow);

        $step = new WorkflowStep();
        $step->setName('Decide');
        $step->setStepOrder(1);
        $step->setStepType('approval');
        if ($approverRole !== null) {
            $step->setApproverRole($approverRole);
        }
        if ($approverUsers !== []) {
            $step->setApproverUsers(array_map('intval', $approverUsers));
        }
        $step->setTenant($this->tenant);
        $workflow->addStep($step);
        $this->em->persist($step);

        $instance = new WorkflowInstance(); // @lifecycle-initial-state
        $instance->setWorkflow($workflow);
        $instance->setEntityType('Risk');
        $instance->setEntityId(random_int(100000, 999999));
        $instance->setTenant($this->tenant);
        $instance->setCurrentStep($step);
        $instance->setCurrentStepIndex(0);
        $instance->setStartedAt(new DateTimeImmutable('-1 hour'));
        $instance->setApprovalHistory([]);
        $instance->setStatus('in_progress'); // fixture: instance already started
        $this->em->persist($instance);
        $this->em->flush();

        return $instance;
    }
}
