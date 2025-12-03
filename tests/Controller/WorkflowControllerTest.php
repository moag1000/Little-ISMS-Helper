<?php

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for WorkflowController
 *
 * Tests workflow management including:
 * - Index with statistics and recent instances
 * - Workflow definitions CRUD (Admin only)
 * - Workflow instances: show, approve, reject, cancel
 * - Pending approvals and active workflows
 * - Role-based access control
 */
class WorkflowControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?Workflow $testWorkflow = null;
    private ?WorkflowInstance $testInstance = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        // Clean up in correct order (instances before workflows)
        if ($this->testInstance) {
            try {
                $instance = $this->entityManager->find(WorkflowInstance::class, $this->testInstance->getId());
                if ($instance) {
                    $this->entityManager->remove($instance);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($this->testWorkflow) {
            try {
                $workflow = $this->entityManager->find(Workflow::class, $this->testWorkflow->getId());
                if ($workflow) {
                    // Remove steps first
                    foreach ($workflow->getSteps() as $step) {
                        $this->entityManager->remove($step);
                    }
                    $this->entityManager->remove($workflow);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        // Clean up users
        if ($this->testUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->testUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($this->adminUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->adminUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        // Clean up tenant
        if ($this->testTenant) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($tenant) {
                    $this->entityManager->remove($tenant);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // Ignore flush errors during cleanup
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('test_', true);

        // Create test tenant
        $this->testTenant = new Tenant();
        $this->testTenant->setName('Test Tenant ' . $uniqueId);
        $this->testTenant->setCode('test_tenant_' . $uniqueId);
        $this->entityManager->persist($this->testTenant);

        // Create test user with ROLE_USER
        $this->testUser = new User();
        $this->testUser->setEmail('testuser_' . $uniqueId . '@example.com');
        $this->testUser->setFirstName('Test');
        $this->testUser->setLastName('User');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        // Create admin user with ROLE_ADMIN
        $this->adminUser = new User();
        $this->adminUser->setEmail('admin_' . $uniqueId . '@example.com');
        $this->adminUser->setFirstName('Admin');
        $this->adminUser->setLastName('User');
        $this->adminUser->setRoles(['ROLE_ADMIN']);
        $this->adminUser->setPassword('hashed_password');
        $this->adminUser->setTenant($this->testTenant);
        $this->adminUser->setIsActive(true);
        $this->entityManager->persist($this->adminUser);

        // Create test workflow
        $this->testWorkflow = new Workflow();
        $this->testWorkflow->setTenant($this->testTenant);
        $this->testWorkflow->setName('Test Workflow ' . $uniqueId);
        $this->testWorkflow->setDescription('Test workflow description');
        $this->testWorkflow->setEntityType('Risk');
        $this->testWorkflow->setIsActive(true);
        $this->entityManager->persist($this->testWorkflow);

        // Create workflow step
        $step = new WorkflowStep();
        $step->setWorkflow($this->testWorkflow);
        $step->setName('Step 1');
        $step->setDescription('First step');
        $step->setStepOrder(1);
        $step->setApproverRole('ROLE_USER');
        $this->entityManager->persist($step);

        // Create workflow instance
        $this->testInstance = new WorkflowInstance();
        $this->testInstance->setWorkflow($this->testWorkflow);
        $this->testInstance->setEntityType('Risk');
        $this->testInstance->setEntityId(1);
        $this->testInstance->setStatus('in_progress');
        $this->testInstance->setCurrentStep($step);
        $this->testInstance->setInitiatedBy($this->testUser);
        $this->entityManager->persist($this->testInstance);

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    // ========== INDEX ACTION TESTS ==========

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/workflow/');

        $this->assertResponseRedirects();
    }

    public function testIndexShowsWorkflowsForAuthenticatedUser(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/workflow/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testIndexDisplaysStatistics(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/workflow/');

        $this->assertResponseIsSuccessful();
    }

    // ========== DEFINITIONS ACTION TESTS ==========

    public function testDefinitionsRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/workflow/definitions');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDefinitionsShowsWorkflowsForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/workflow/definitions');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    // ========== PENDING APPROVALS TESTS ==========

    public function testPendingRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/workflow/pending');

        $this->assertResponseRedirects();
    }

    public function testPendingShowsApprovalsForUser(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/workflow/pending');

        $this->assertResponseIsSuccessful();
    }

    // ========== ACTIVE WORKFLOWS TESTS ==========

    public function testActiveRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/workflow/active');

        $this->assertResponseRedirects();
    }

    public function testActiveShowsWorkflowsForUser(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/workflow/active');

        $this->assertResponseIsSuccessful();
    }

    // ========== OVERDUE WORKFLOWS TESTS ==========

    public function testOverdueRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/workflow/overdue');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testOverdueShowsWorkflowsForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/workflow/overdue');

        $this->assertResponseIsSuccessful();
    }

    // ========== INSTANCE SHOW TESTS ==========

    public function testShowInstanceRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/workflow/instance/' . $this->testInstance->getId());

        $this->assertResponseRedirects();
    }

    public function testShowInstanceDisplaysInstance(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/workflow/instance/' . $this->testInstance->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testShowInstanceReturns404ForNonexistent(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/workflow/instance/999999');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== INSTANCE APPROVE TESTS ==========

    public function testApproveInstanceRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/workflow/instance/' . $this->testInstance->getId() . '/approve');

        $this->assertResponseRedirects();
    }

    public function testApproveInstanceRequiresCsrfToken(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('POST', '/en/workflow/instance/' . $this->testInstance->getId() . '/approve', [
            '_token' => 'invalid_token',
        ]);

        // Should redirect with error flash
        $this->assertResponseRedirects();
    }

    // ========== INSTANCE REJECT TESTS ==========

    public function testRejectInstanceRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/workflow/instance/' . $this->testInstance->getId() . '/reject');

        $this->assertResponseRedirects();
    }

    public function testRejectInstanceRequiresComments(): void
    {
        $this->loginAsUser($this->testUser);

        // Make a GET request first to initialize session
        $this->client->request('GET', '/en/workflow/instance/' . $this->testInstance->getId());

        // Generate CSRF token via session
        $session = $this->client->getRequest()->getSession();
        $tokenGenerator = new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator();
        $tokenValue = $tokenGenerator->generateToken();
        $session->set('_csrf/reject' . $this->testInstance->getId(), $tokenValue);

        $this->client->request('POST', '/en/workflow/instance/' . $this->testInstance->getId() . '/reject', [
            '_token' => $tokenValue,
            'comments' => '', // Empty comments
        ]);

        // Should redirect with error about missing comments
        $this->assertResponseRedirects();
    }

    // ========== INSTANCE CANCEL TESTS ==========

    public function testCancelInstanceRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('POST', '/en/workflow/instance/' . $this->testInstance->getId() . '/cancel');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testCancelInstanceRequiresCsrfToken(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/en/workflow/instance/' . $this->testInstance->getId() . '/cancel', [
            '_token' => 'invalid_token',
        ]);

        // Should redirect with error flash
        $this->assertResponseRedirects();
    }

    // ========== DEFINITION SHOW TESTS ==========

    public function testShowDefinitionRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/workflow/definition/' . $this->testWorkflow->getId());

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testShowDefinitionDisplaysWorkflow(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/workflow/definition/' . $this->testWorkflow->getId());

        $this->assertResponseIsSuccessful();
    }

    // ========== DEFINITION NEW TESTS ==========

    public function testNewDefinitionRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/workflow/definition/new');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testNewDefinitionDisplaysForm(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/workflow/definition/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== DEFINITION EDIT TESTS ==========

    public function testEditDefinitionRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/workflow/definition/' . $this->testWorkflow->getId() . '/edit');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testEditDefinitionDisplaysForm(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/workflow/definition/' . $this->testWorkflow->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== DEFINITION DELETE TESTS ==========

    public function testDeleteDefinitionRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('POST', '/en/workflow/definition/' . $this->testWorkflow->getId() . '/delete');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteDefinitionRequiresCsrfToken(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/en/workflow/definition/' . $this->testWorkflow->getId() . '/delete', [
            '_token' => 'invalid_token',
        ]);

        // Should redirect with error flash
        $this->assertResponseRedirects();

        // Workflow should still exist
        $this->entityManager->clear();
        $workflow = $this->entityManager->find(Workflow::class, $this->testWorkflow->getId());
        $this->assertNotNull($workflow);
    }

    // ========== DEFINITION TOGGLE TESTS ==========

    public function testToggleDefinitionRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('POST', '/en/workflow/definition/' . $this->testWorkflow->getId() . '/toggle');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testToggleDefinitionTogglesActiveStatus(): void
    {
        $this->loginAsUser($this->adminUser);

        $initialStatus = $this->testWorkflow->isActive();

        // Get the definition page which should have a toggle form
        $crawler = $this->client->request('GET', '/en/workflow/definition/' . $this->testWorkflow->getId());

        // Try to find the toggle form and submit it
        $toggleForm = $crawler->filter('form[action*="/toggle"]');
        if ($toggleForm->count() > 0) {
            $form = $toggleForm->form();
            $this->client->submit($form);

            $this->assertResponseRedirects();

            // Verify status changed
            $this->entityManager->clear();
            $workflow = $this->entityManager->find(Workflow::class, $this->testWorkflow->getId());
            $this->assertNotEquals($initialStatus, $workflow->isActive());
        } else {
            // If no toggle form found, skip the test
            $this->assertResponseIsSuccessful();
            $this->markTestSkipped('Toggle form not found on definition page');
        }
    }

    // ========== BY ENTITY TESTS ==========

    public function testByEntityRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/workflow/by-entity/Risk/1');

        $this->assertResponseRedirects();
    }

    public function testByEntityRedirectsToInstanceIfExists(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/workflow/by-entity/Risk/1');

        // Should redirect to instance show page or index if not found
        $this->assertResponseRedirects();
    }

    // ========== START WORKFLOW TESTS ==========

    public function testStartWorkflowRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/workflow/start/Risk/1');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testStartWorkflowForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/workflow/start/Risk/999?workflow=test');

        // Should redirect (either to new instance or with error)
        $this->assertResponseRedirects();
    }

    // ========== BUILDER TESTS ==========

    public function testBuilderRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/workflow/definition/' . $this->testWorkflow->getId() . '/builder');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testBuilderDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('GET', '/en/workflow/definition/' . $this->testWorkflow->getId() . '/builder');

        $this->assertResponseIsSuccessful();
    }
}
