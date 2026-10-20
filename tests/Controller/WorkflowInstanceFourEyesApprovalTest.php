<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Four-eyes on the final step of a workflow instance (#1042).
 *
 * Runs the real chain — WorkflowController → WorkflowService →
 * LifecycleService → FourEyesValidator — so the transition's `roles`,
 * the role hierarchy and the step's approver_role meet exactly as in
 * production.
 */
#[AllowMockObjectsWithoutExpectations]
final class WorkflowInstanceFourEyesApprovalTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UrlGeneratorInterface $urlGenerator;
    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();

        $moduleService = $this->createMock(\App\Service\ModuleConfigurationService::class);
        $moduleService->method('isModuleActive')->willReturnCallback(
            static fn (string $key): bool => in_array($key, [
                'core', 'authentication', 'documents', 'audit_logging', 'workflows', 'objectives',
            ], true),
        );
        $container->set(\App\Service\ModuleConfigurationService::class, $moduleService);

        $this->em = $container->get(EntityManagerInterface::class);
        $this->urlGenerator = $container->get(UrlGeneratorInterface::class);

        $uid = uniqid('fe_', true);
        $this->tenant = new Tenant();
        $this->tenant->setName('Four Eyes ' . $uid);
        $this->tenant->setCode('fe_' . $uid);
        $this->em->persist($this->tenant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        try {
            $conn = $this->em->getConnection();
            $tenantId = $this->tenant?->getId();
            if ($tenantId !== null) {
                $conn->executeStatement('DELETE FROM workflow_instances WHERE tenant_id = ?', [$tenantId]);
                $conn->executeStatement(
                    'DELETE FROM workflow_steps WHERE workflow_id IN (SELECT id FROM workflows WHERE tenant_id = ?)',
                    [$tenantId],
                );
                $conn->executeStatement('DELETE FROM workflows WHERE tenant_id = ?', [$tenantId]);
                $conn->executeStatement('DELETE FROM users WHERE tenant_id = ?', [$tenantId]);
                $conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$tenantId]);
            }
        } catch (\Throwable) {
        }

        parent::tearDown();
    }

    #[Test]
    public function dpoCompletesFinalStepThatRequiresDpo(): void
    {
        $initiator = $this->user('initiator', ['ROLE_MANAGER']);
        $dpo = $this->user('dpo', ['ROLE_DPO']);
        $instance = $this->instance($initiator, [['approval', 'ROLE_DPO']]);

        $this->approveAs($dpo, $instance);

        self::assertNotSame(500, $this->client->getResponse()->getStatusCode());
        self::assertSame('approved', $this->reload($instance)->getStatus());
    }

    #[Test]
    public function adminCompletesFinalStep(): void
    {
        $initiator = $this->user('initiator', ['ROLE_MANAGER']);
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $instance = $this->instance($initiator, [['approval', 'ROLE_CISO']]);

        $this->approveAs($admin, $instance);

        self::assertNotSame(500, $this->client->getResponse()->getStatusCode());
        self::assertSame('approved', $this->reload($instance)->getStatus());
    }

    #[Test]
    public function stepApproverOutsideTransitionRolesCompletesFinalStep(): void
    {
        // control_verification-style: the final step belongs to the AUDITOR,
        // who does not reach ROLE_MANAGER / ROLE_CISO.
        $initiator = $this->user('initiator', ['ROLE_MANAGER']);
        $auditor = $this->user('auditor', ['ROLE_AUDITOR']);
        $instance = $this->instance($initiator, [['approval', 'ROLE_AUDITOR']]);

        $this->approveAs($auditor, $instance);

        self::assertNotSame(500, $this->client->getResponse()->getStatusCode());
        self::assertSame('approved', $this->reload($instance)->getStatus());
    }

    #[Test]
    public function approvalBeforeClosingNotificationStepCompletesWorkflow(): void
    {
        // dsr / risk_treatment-style: the last step is a notification step
        // that auto-progresses; the preceding approver grants the approval.
        $initiator = $this->user('initiator', ['ROLE_MANAGER']);
        $dpo = $this->user('dpo', ['ROLE_DPO']);
        $instance = $this->instance($initiator, [['approval', 'ROLE_DPO'], ['notification', 'ROLE_DPO']]);

        $this->approveAs($dpo, $instance);

        self::assertNotSame(500, $this->client->getResponse()->getStatusCode());
        self::assertSame('approved', $this->reload($instance)->getStatus());
    }

    #[Test]
    public function initiatorCannotBeSoleFinalApproverAndNothingIsWritten(): void
    {
        $initiator = $this->user('initiator', ['ROLE_CISO']);
        $this->user('second_ciso', ['ROLE_CISO']); // a second eligible approver exists
        $instance = $this->instance($initiator, [['approval', 'ROLE_CISO']]);
        $finalStepId = $instance->getCurrentStep()?->getId();

        $this->approveAs($initiator, $instance);

        $response = $this->client->getResponse();
        self::assertNotSame(500, $response->getStatusCode());
        self::assertTrue($response->isRedirection(), 'Refusal is a flash + redirect, not an error page.');

        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        $flashes = $session->getFlashBag()->peek('error');
        self::assertNotEmpty($flashes, 'A translated four-eyes error flash is shown.');
        self::assertStringNotContainsString('approval.error.', $flashes[0]);

        $reloaded = $this->reload($instance);
        self::assertSame('in_progress', $reloaded->getStatus());
        self::assertSame([], $reloaded->getApprovalHistory() ?? []);
        self::assertSame([], $reloaded->getCompletedSteps() ?? []);
        self::assertSame($finalStepId, $reloaded->getCurrentStep()?->getId());
        self::assertNull($reloaded->getCompletedAt());
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

    /** @param list<array{0: string, 1: string}> $steps [stepType, approverRole] */
    private function instance(User $initiator, array $steps): WorkflowInstance
    {
        $workflow = new Workflow();
        $workflow->setName('Four eyes test ' . uniqid('', true));
        $workflow->setEntityType('Risk');
        $workflow->setTenant($this->tenant);
        $this->em->persist($workflow);

        $first = null;
        foreach ($steps as $i => [$type, $role]) {
            $step = new WorkflowStep();
            $step->setName('Step ' . ($i + 1));
            $step->setStepOrder($i + 1);
            $step->setStepType($type);
            $step->setApproverRole($role);
            $step->setTenant($this->tenant);
            $workflow->addStep($step);
            $this->em->persist($step);
            $first ??= $step;
        }

        $instance = new WorkflowInstance(); // @lifecycle-initial-state
        $instance->setWorkflow($workflow);
        $instance->setEntityType('Risk');
        $instance->setEntityId(random_int(100000, 999999));
        $instance->setTenant($this->tenant);
        $instance->setInitiatedBy($initiator);
        $instance->setCurrentStep($first);
        $instance->setCurrentStepIndex(0);
        $instance->setStatus('in_progress'); // fixture: instance already started
        $this->em->persist($instance);
        $this->em->flush();

        return $instance;
    }

    private function approveAs(User $user, WorkflowInstance $instance): void
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', $this->urlGenerator->generate(
            'app_workflow_instance_show',
            ['_locale' => 'en', 'id' => $instance->getId()],
        ));
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('#approveModal form')->form();
        $this->client->submit($form);
    }

    private function reload(WorkflowInstance $instance): WorkflowInstance
    {
        $this->em->clear();
        $fresh = $this->em->find(WorkflowInstance::class, $instance->getId());
        self::assertInstanceOf(WorkflowInstance::class, $fresh);

        return $fresh;
    }
}
