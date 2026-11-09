<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Controller\LifecycleController;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Lifecycle\EntityTypeRegistry;
use App\Lifecycle\Exception\TransitionAccessDeniedException;
use App\Lifecycle\LifecycleService;
use App\Service\TenantContext;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Workflow\Registry;

/**
 * S27 (8th assessment F-01a): an approval chain is decided step by step —
 * WorkflowService::canUserApprove() with the step's approver role — never by
 * the coarse roles of `workflow_instance_lifecycle` (ROLE_MANAGER/ROLE_CISO).
 *
 * The test discovers every lifecycle slug whose class is a WorkflowInstance and
 * every transition of its workflow, and asserts the generic endpoint, the bulk
 * endpoint and LifecycleService itself refuse each one but `start` for a CISO.
 * It also pins the step rules: top management and DPO steps need the assigned
 * role (no admin override), an auditor step is audit work (no top management,
 * no group roles). And every call site that tells LifecycleService "authority
 * verified" is listed with its reason — a new one fails until reviewed.
 */
final class GenericEndpointRespectsStepAuthorityTest extends KernelTestCase
{
    /**
     * Files allowed to pass `approverAuthorityVerified: true` and the rule they checked.
     * WorkflowService passes it positionally for the closing step (moveToNextStep).
     */
    private const array VERIFIED_CALL_SITES = [
        'src/Service/WorkflowService.php' => 'canUserApprove() on the step (reject); ROLE_ADMIN caller (cancel)',
        'src/Service/RiskAcceptanceWorkflowService.php' => 'RiskAcceptanceVoter tier / criteria approved by top management',
    ];

    private EntityManagerInterface $em;
    private Tenant $tenant;
    private string $uid;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = str_replace('.', '', uniqid('gesa', true));
        $this->tenant = (new Tenant())->setName('GESA ' . $this->uid)->setCode('gesa_' . $this->uid);
        $this->em->persist($this->tenant);
        $this->em->flush();
        static::getContainer()->get(TenantContext::class)->setCurrentTenant($this->tenant); // @tenant-pin-ok: test fixture, kernel is shut down after each test
    }

    protected function tearDown(): void
    {
        $tenantId = $this->tenant->getId();
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
        parent::tearDown();
    }

    #[Test]
    public function theGenericEndpointNeverDecidesAnApprovalChain(): void
    {
        $initiator = $this->user('initiator', ['ROLE_MANAGER']);
        $ciso = $this->user('ciso', ['ROLE_CISO']);
        $this->loginAs($ciso);
        $controller = static::getContainer()->get(LifecycleController::class);
        $registry = static::getContainer()->get(EntityTypeRegistry::class);

        $slugs = array_values(array_filter(
            $registry->knownSlugs(),
            static fn (string $slug): bool => ($registry->lookup($slug)['class'] ?? null) === WorkflowInstance::class,
        ));
        self::assertNotSame([], $slugs, 'the approval chain is a lifecycle type');

        foreach ($slugs as $slug) {
            $instance = $this->instance($initiator, 'ROLE_TOP_MGMT');
            foreach ($this->transitionsOf($instance, $registry->lookup($slug)['workflow']) as $transition) {
                $request = Request::create('/lifecycle/' . $slug . '/' . $instance->getId() . '/transition', 'POST', content: (string) json_encode([
                    'transition' => $transition,
                    'reason' => 'Manager tries to close the chain',
                ]));
                $response = $controller->transition($slug, (int) $instance->getId(), $request);
                $body = json_decode((string) $response->getContent(), true);
                self::assertSame(422, $response->getStatusCode(), sprintf('%s.%s on the generic endpoint', $slug, $transition));
                self::assertSame('approval_chain_step', $body['error'] ?? null);

                $bulk = Request::create('/lifecycle/' . $slug . '/bulk-transition', 'POST', content: (string) json_encode([
                    'transition' => $transition,
                    'ids' => [$instance->getId()],
                    'reason' => 'Manager tries to close the chain',
                ]));
                self::assertSame(422, $controller->bulkTransition($slug, $bulk)->getStatusCode(), sprintf('%s.%s in bulk', $slug, $transition));
            }

            $allowed = json_decode((string) $controller->allowedTransitions($slug, (int) $instance->getId())->getContent(), true);
            self::assertSame([], $allowed['allowed_transitions'] ?? null, 'no status buttons on a running chain');

            $this->em->refresh($instance);
            self::assertSame('in_progress', $instance->getStatus());
        }
    }

    #[Test]
    public function lifecycleServiceRefusesChainDecisionsWithoutStepAuthority(): void
    {
        $initiator = $this->user('initiator', ['ROLE_MANAGER']);
        $ciso = $this->user('ciso', ['ROLE_CISO']);
        $this->loginAs($ciso);
        $instance = $this->instance($initiator, 'ROLE_DPO');
        $lifecycle = static::getContainer()->get(LifecycleService::class);

        foreach ($this->transitionsOf($instance, 'workflow_instance_lifecycle') as $transition) {
            if ($transition === 'start') {
                continue;
            }
            try {
                $lifecycle->transition($instance, 'workflow_instance_lifecycle', $transition, $ciso, 'no step authority');
                self::fail(sprintf('workflow_instance_lifecycle.%s applied without step authority (REST/MCP path)', $transition));
            } catch (TransitionAccessDeniedException) {
                // refused before anything changed
            }
        }
        $this->em->refresh($instance);
        self::assertSame('in_progress', $instance->getStatus());
    }

    #[Test]
    public function stepRulesNeedTheAssignedRoleForLeitungDpoAndAuditWork(): void
    {
        $service = static::getContainer()->get(WorkflowService::class);
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $dpo = $this->user('dpo', ['ROLE_DPO']);
        $top = $this->user('top', ['ROLE_TOP_MGMT']);
        $groupCiso = $this->user('groupciso', ['ROLE_GROUP_CISO']);
        $groupAuditor = $this->user('groupaud', ['ROLE_KONZERN_AUDITOR']);
        $auditor = $this->user('auditor', ['ROLE_AUDITOR']);
        $manager = $this->user('manager', ['ROLE_MANAGER']);

        $dpoStep = $this->step('ROLE_DPO');
        self::assertFalse($service->canUserApprove($admin, $dpoStep), 'admin inherits ROLE_DPO only to operate the tool');
        self::assertTrue($service->canUserApprove($dpo, $dpoStep));
        self::assertFalse($service->canUserApprove($manager, $dpoStep));

        $topStep = $this->step('ROLE_TOP_MGMT');
        self::assertFalse($service->canUserApprove($admin, $topStep));
        self::assertTrue($service->canUserApprove($top, $topStep));

        $auditStep = $this->step('ROLE_AUDITOR');
        self::assertTrue($service->canUserApprove($auditor, $auditStep));
        self::assertTrue($service->canUserApprove($manager, $auditStep));
        foreach ([$top, $groupCiso, $groupAuditor] as $reviewer) {
            self::assertFalse(
                $service->canUserApprove($reviewer, $auditStep),
                $reviewer->getRoles()[0] . ' reads like an auditor but does no audit work',
            );
        }

        $cisoStep = $this->step('ROLE_CISO');
        self::assertTrue($service->canUserApprove($admin, $cisoStep), 'admin override stays for ordinary steps');
    }

    #[Test]
    public function stepRulesAnswerForTheGivenUserNotTheLoggedInOne(): void
    {
        $service = static::getContainer()->get(WorkflowService::class);
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $plain = $this->user('plain', ['ROLE_USER']);
        $this->loginAs($admin);

        self::assertFalse($service->canUserApprove($plain, $this->step('ROLE_CISO')), 'F-08: decided for $user');
    }

    #[Test]
    public function everyAuthorityVerifiedCallSiteIsReviewed(): void
    {
        $root = dirname(__DIR__, 2);
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (str_starts_with($relative, 'src/Lifecycle/')) {
                continue; // the facade and the access check themselves
            }
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/approverAuthorityVerified:\s*true|\$authorizedForStep instanceof WorkflowStep,/', $code) === 1) {
                $found[] = $relative;
            }
        }
        sort($found);
        $expected = array_keys(self::VERIFIED_CALL_SITES);
        sort($expected);

        self::assertSame($expected, $found, 'A call site that skips the transition roles needs a reviewed rule of its own (S21/S27).');
    }

    /** @return list<string> */
    private function transitionsOf(WorkflowInstance $instance, string $workflowName): array
    {
        $definition = static::getContainer()->get(Registry::class)->get($instance, $workflowName)->getDefinition();

        return array_values(array_unique(array_map(static fn ($t): string => $t->getName(), $definition->getTransitions())));
    }

    private function step(string $role): WorkflowStep
    {
        return (new WorkflowStep())->setName('Step ' . $role)->setStepOrder(1)->setStepType('approval')->setApproverRole($role);
    }

    private function instance(User $initiator, string $stepRole): WorkflowInstance
    {
        $workflow = new Workflow();
        $workflow->setName('S27 chain ' . uniqid('', true));
        $workflow->setEntityType('Risk');
        $workflow->setTenant($this->tenant);
        $this->em->persist($workflow);

        $step = $this->step($stepRole);
        $step->setTenant($this->tenant);
        $workflow->addStep($step);
        $this->em->persist($step);

        $instance = new WorkflowInstance(); // @lifecycle-initial-state
        $instance->setWorkflow($workflow);
        $instance->setEntityType('Risk');
        $instance->setEntityId(random_int(100000, 999999));
        $instance->setTenant($this->tenant);
        $instance->setInitiatedBy($initiator);
        $instance->setCurrentStep($step);
        $instance->setCurrentStepIndex(0);
        $instance->setStatus('in_progress'); // @phpstan-ignore lifecycle.directSetStatus (test fixture: chain already started)
        $this->em->persist($instance);
        $this->em->flush();

        return $instance;
    }

    /** @param list<string> $roles */
    private function user(string $name, array $roles): User
    {
        $user = (new User())
            ->setEmail($name . '_' . $this->uid . '@example.com')
            ->setFirstName(ucfirst($name))
            ->setLastName('S27')
            ->setRoles($roles)
            ->setPassword('hashed_password')
            ->setTenant($this->tenant)
            ->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function loginAs(User $user): void
    {
        static::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
