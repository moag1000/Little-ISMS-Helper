<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Lifecycle\LifecycleTransitionInterface;
use App\Repository\UserRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Repository\WorkflowRepository;
use App\Service\Deadline\DeadlineSourceRegistry;
use App\Service\Deadline\Source\RiskReviewSource;
use App\Service\EmailNotificationService;
use App\Service\Planning\Source\Adapter\WorkflowInstanceAdapter;
use App\Service\Responsibility\ResponsibilityResolver;
use App\Service\WorkflowService;
use App\Tests\Stub\PersonaResolverFactory;
use App\Workflow\Loader\RegulatoryWorkflowLoader;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * `approver_role: owner` — the record's owner decides the step (risk owner,
 * else the role fallback of the record's deadline source), not a role
 * nobody holds.
 */
#[AllowMockObjectsWithoutExpectations]
final class WorkflowServiceOwnerApproverTest extends TestCase
{
    private Tenant $tenant;
    private User $owner;
    private User $riskManager;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 1);
        $this->owner = $this->user(11, ['ROLE_USER']);
        $this->riskManager = $this->user(12, ['ROLE_RISK_MANAGER']);
    }

    #[Test]
    public function theRiskOwnerDecidesAndTheRiskManagerOnlyWithoutOwner(): void
    {
        $risk = (new Risk())->setTenant($this->tenant)->setRiskOwner($this->owner);
        [$service, $instance, $step] = $this->setUpFor($risk);

        self::assertTrue($service->canUserApprove($this->owner, $step, $instance));
        self::assertFalse($service->canUserApprove($this->riskManager, $step, $instance));
        self::assertSame([$this->owner], $service->getEligibleApprovers($step, $this->tenant, $instance));

        $risk->setRiskOwner(null);
        self::assertTrue($service->canUserApprove($this->riskManager, $step, $instance));
        self::assertFalse($service->canUserApprove($this->owner, $step, $instance));
    }

    #[Test]
    public function withoutTheInstanceNobodyButAnAdminCanDecideAnOwnerStep(): void
    {
        [$service, , $step] = $this->setUpFor((new Risk())->setTenant($this->tenant)->setRiskOwner($this->owner));

        self::assertFalse($service->canUserApprove($this->owner, $step));
        self::assertTrue($service->hasEligibleApprovers($step, $this->tenant));
    }

    /** @return array{WorkflowService, WorkflowInstance, WorkflowStep} */
    private function setUpFor(Risk $risk): array
    {
        $step = (new WorkflowStep())->setApproverRole(WorkflowInstanceAdapter::APPROVER_OWNER);
        $instance = (new WorkflowInstance())->setTenant($this->tenant)->setEntityType('Risk')->setEntityId(5)->setCurrentStep($step);

        $metadata = $this->createStub(ClassMetadataFactory::class);
        $metadata->method('hasMetadataFor')->willReturn(true);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getMetadataFactory')->willReturn($metadata);
        $entityManager->method('find')->willReturn($risk);

        $users = $this->createStub(UserRepository::class);
        $users->method('findActiveByTenant')->willReturn([$this->owner, $this->riskManager]);
        $users->method('findByIdsInTenant')->willReturn([]);

        $adapter = new WorkflowInstanceAdapter($this->createStub(WorkflowInstanceRepository::class), $users, $entityManager);
        $registry = new DeadlineSourceRegistry([$adapter, new RiskReviewSource($entityManager)]);
        $resolver = new ResponsibilityResolver($users, PersonaResolverFactory::create(), $registry);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(false);

        $service = new WorkflowService(
            $entityManager,
            $this->createStub(WorkflowRepository::class),
            $this->createStub(WorkflowInstanceRepository::class),
            $users,
            $this->createStub(EmailNotificationService::class),
            $security,
            $this->createStub(LifecycleTransitionInterface::class),
            $this->createStub(RegulatoryWorkflowLoader::class),
            null,
            $resolver,
            $adapter,
        );

        return [$service, $instance, $step];
    }

    /** @param list<string> $roles */
    private function user(int $id, array $roles): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        $user->setEmail('u' . $id . '@example.test');
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setTenant($this->tenant);

        return $user;
    }
}
