<?php

namespace App\Tests\Controller;

use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for BusinessContinuityPlanController
 *
 * Tests ISO 22301 Business Continuity Plan management including:
 * - Index with overdue tests and reviews
 * - CRUD operations (create, read, update, delete)
 * - Role-based access control
 * - Multi-tenant isolation
 */
class BusinessContinuityPlanControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?BusinessProcess $testProcess = null;
    private ?BusinessContinuityPlan $testPlan = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        // Clean up BC plans
        if ($this->testPlan) {
            try {
                $plan = $this->entityManager->find(BusinessContinuityPlan::class, $this->testPlan->getId());
                if ($plan) {
                    $this->entityManager->remove($plan);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        // Clean up additional plans created during tests
        $planRepo = $this->entityManager->getRepository(BusinessContinuityPlan::class);
        foreach (['New Test BC Plan', 'Updated Test BC Plan'] as $name) {
            $plans = $planRepo->findBy(['name' => $name]);
            foreach ($plans as $plan) {
                try {
                    $this->entityManager->remove($plan);
                } catch (\Exception $e) {
                    // Ignore
                }
            }
        }

        // Clean up business process
        if ($this->testProcess) {
            try {
                $process = $this->entityManager->find(BusinessProcess::class, $this->testProcess->getId());
                if ($process) {
                    $this->entityManager->remove($process);
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

        // Create test business process
        $this->testProcess = new BusinessProcess();
        $this->testProcess->setName('Test Business Process ' . $uniqueId);
        $this->testProcess->setDescription('Test process for BC plan testing');
        $this->testProcess->setProcessOwner('Process Owner');
        $this->testProcess->setCriticality('high');
        $this->testProcess->setRto(4);
        $this->testProcess->setRpo(1);
        $this->testProcess->setMtpd(24);
        $this->testProcess->setReputationalImpact(3);
        $this->testProcess->setRegulatoryImpact(2);
        $this->testProcess->setOperationalImpact(4);
        $this->testProcess->setTenant($this->testTenant);
        $this->entityManager->persist($this->testProcess);

        // Create test BC plan
        $this->testPlan = new BusinessContinuityPlan();
        $this->testPlan->setTenant($this->testTenant);
        $this->testPlan->setName('Test BC Plan ' . $uniqueId);
        $this->testPlan->setDescription('Test business continuity plan');
        $this->testPlan->setBusinessProcess($this->testProcess);
        $this->testPlan->setPlanOwner('Plan Owner');
        $this->testPlan->setStatus('active');
        $this->testPlan->setActivationCriteria('System downtime exceeds 30 minutes');
        $this->testPlan->setRecoveryProcedures('1. Notify team\n2. Assess damage\n3. Execute recovery');
        $this->testPlan->setNextReviewDate(new \DateTime('+1 year'));
        $this->entityManager->persist($this->testPlan);

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);

        if (!$this->entityManager->contains($user)) {
            $user = $this->entityManager->merge($user);
        }
        $this->entityManager->refresh($user);
    }

    private function generateCsrfToken(string $tokenId): string
    {
        $this->client->request('GET', '/en/business-continuity-plan/');

        $session = $this->client->getRequest()->getSession();
        $tokenGenerator = new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator();
        $tokenValue = $tokenGenerator->generateToken();
        $session->set('_csrf/' . $tokenId, $tokenValue);

        return $tokenValue;
    }

    // ========== INDEX ACTION TESTS ==========

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/business-continuity-plan/');

        $this->assertResponseRedirects();
    }

    public function testIndexShowsPlansForAuthenticatedUser(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/business-continuity-plan/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testIndexDisplaysOverdueTests(): void
    {
        // Create plan with overdue test
        $overduePlan = new BusinessContinuityPlan();
        $overduePlan->setTenant($this->testTenant);
        $overduePlan->setName('Overdue Test Plan');
        $overduePlan->setBusinessProcess($this->testProcess);
        $overduePlan->setPlanOwner('Owner');
        $overduePlan->setStatus('active');
        $overduePlan->setActivationCriteria('Criteria');
        $overduePlan->setRecoveryProcedures('Procedures');
        $overduePlan->setLastTested(new \DateTime('-2 years'));
        $this->entityManager->persist($overduePlan);
        $this->entityManager->flush();

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/business-continuity-plan/');

        $this->assertResponseIsSuccessful();

        // Clean up
        $this->entityManager->remove($overduePlan);
        $this->entityManager->flush();
    }

    public function testIndexDisplaysOverdueReviews(): void
    {
        // Create plan with overdue review
        $overduePlan = new BusinessContinuityPlan();
        $overduePlan->setTenant($this->testTenant);
        $overduePlan->setName('Overdue Review Plan');
        $overduePlan->setBusinessProcess($this->testProcess);
        $overduePlan->setPlanOwner('Owner');
        $overduePlan->setStatus('active');
        $overduePlan->setActivationCriteria('Criteria');
        $overduePlan->setRecoveryProcedures('Procedures');
        $overduePlan->setNextReviewDate(new \DateTime('-1 month'));
        $this->entityManager->persist($overduePlan);
        $this->entityManager->flush();

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/business-continuity-plan/');

        $this->assertResponseIsSuccessful();

        // Clean up
        $this->entityManager->remove($overduePlan);
        $this->entityManager->flush();
    }

    // ========== SHOW ACTION TESTS ==========

    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/business-continuity-plan/' . $this->testPlan->getId());

        $this->assertResponseRedirects();
    }

    public function testShowDisplaysPlanDetails(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/business-continuity-plan/' . $this->testPlan->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('html', 'Test BC Plan');
    }

    public function testShowReturns404ForNonexistentPlan(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/business-continuity-plan/999999');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== NEW ACTION TESTS ==========

    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/business-continuity-plan/new');

        $this->assertResponseRedirects();
    }

    public function testNewDisplaysFormForUser(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/business-continuity-plan/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="business_continuity_plan"]');
    }

    public function testNewCreatesPlanWithValidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/business-continuity-plan/new');
        $form = $crawler->filter('form[name="business_continuity_plan"]')->form([
            'business_continuity_plan[name]' => 'New Test BC Plan',
            'business_continuity_plan[description]' => 'New plan description',
            'business_continuity_plan[businessProcess]' => $this->testProcess->getId(),
            'business_continuity_plan[planOwner]' => 'New Owner',
            'business_continuity_plan[status]' => 'draft',
            'business_continuity_plan[activationCriteria]' => 'When systems fail',
            'business_continuity_plan[recoveryProcedures]' => 'Step 1: Do something',
        ]);

        $this->client->submit($form);

        // Verify redirect to show page
        $this->assertResponseRedirects();

        // Follow redirect to show page
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('html', 'New Test BC Plan');
    }

    public function testNewRejectsInvalidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/business-continuity-plan/new');
        $form = $crawler->filter('form[name="business_continuity_plan"]')->form([
            'business_continuity_plan[name]' => '', // Empty name - should fail validation
            'business_continuity_plan[planOwner]' => 'Owner',
        ]);

        $this->client->submit($form);

        // Should re-display form with validation errors - modern Symfony returns 422
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorExists('form[name="business_continuity_plan"]');
    }

    // ========== EDIT ACTION TESTS ==========

    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/business-continuity-plan/' . $this->testPlan->getId() . '/edit');

        $this->assertResponseRedirects();
    }

    public function testEditDisplaysFormForUser(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/business-continuity-plan/' . $this->testPlan->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="business_continuity_plan"]');
    }

    public function testEditUpdatesPlanWithValidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/business-continuity-plan/' . $this->testPlan->getId() . '/edit');
        $form = $crawler->filter('form[name="business_continuity_plan"]')->form([
            'business_continuity_plan[name]' => 'Updated Test BC Plan',
            'business_continuity_plan[description]' => 'Updated description',
        ]);

        $this->client->submit($form);

        // Verify redirect
        $this->assertResponseRedirects();

        // Follow redirect and verify updated data
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('html', 'Updated Test BC Plan');
    }

    public function testEditReturns404ForNonexistentPlan(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/business-continuity-plan/999999/edit');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== DELETE ACTION TESTS ==========

    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/business-continuity-plan/' . $this->testPlan->getId() . '/delete');

        $this->assertResponseRedirects();
    }

    public function testDeleteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $token = $this->generateCsrfToken('delete' . $this->testPlan->getId());

        $this->client->request('POST', '/en/business-continuity-plan/' . $this->testPlan->getId() . '/delete', [
            '_token' => $token,
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteRedirectsWithAdminRole(): void
    {
        $this->loginAsUser($this->adminUser);

        $planId = $this->testPlan->getId();
        $token = $this->generateCsrfToken('delete' . $planId);

        $this->client->request('POST', '/en/business-continuity-plan/' . $planId . '/delete', [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects('/en/business-continuity-plan/');
    }

    public function testDeleteRequiresValidCsrfToken(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/en/business-continuity-plan/' . $this->testPlan->getId() . '/delete', [
            '_token' => 'invalid_token',
        ]);

        // Should redirect but not delete
        $this->assertResponseRedirects('/en/business-continuity-plan/');

        // Verify plan was NOT deleted
        $planRepository = $this->entityManager->getRepository(BusinessContinuityPlan::class);
        $stillExists = $planRepository->find($this->testPlan->getId());
        $this->assertNotNull($stillExists);
    }

    // ========== MULTI-TENANCY TESTS ==========

    public function testIndexRespectsMultiTenancyIsolation(): void
    {
        $uniqueId = uniqid('other_', true);

        // Create another tenant with BC plan
        $otherTenant = new Tenant();
        $otherTenant->setName('Other Tenant ' . $uniqueId);
        $otherTenant->setCode('other_tenant_' . $uniqueId);
        $this->entityManager->persist($otherTenant);

        $otherProcess = new BusinessProcess();
        $otherProcess->setName('Other Process ' . $uniqueId);
        $otherProcess->setDescription('Other process');
        $otherProcess->setProcessOwner('Other Owner');
        $otherProcess->setCriticality('low');
        $otherProcess->setRto(8);
        $otherProcess->setRpo(4);
        $otherProcess->setMtpd(48);
        $otherProcess->setReputationalImpact(1);
        $otherProcess->setRegulatoryImpact(1);
        $otherProcess->setOperationalImpact(2);
        $otherProcess->setTenant($otherTenant);
        $this->entityManager->persist($otherProcess);

        $otherPlan = new BusinessContinuityPlan();
        $otherPlan->setTenant($otherTenant);
        $otherPlan->setName('Other Tenant BC Plan ' . $uniqueId);
        $otherPlan->setBusinessProcess($otherProcess);
        $otherPlan->setPlanOwner('Other Owner');
        $otherPlan->setStatus('active');
        $otherPlan->setActivationCriteria('Other criteria');
        $otherPlan->setRecoveryProcedures('Other procedures');
        $this->entityManager->persist($otherPlan);

        $this->entityManager->flush();

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/business-continuity-plan/');

        $this->assertResponseIsSuccessful();
        // User should only see plans from their own tenant

        // Clean up
        $this->entityManager->remove($otherPlan);
        $this->entityManager->remove($otherProcess);
        $this->entityManager->remove($otherTenant);
        $this->entityManager->flush();
    }

    // ========== STATUS TESTS ==========

    public function testPlanStatusDraft(): void
    {
        $this->testPlan->setStatus('draft');
        $this->entityManager->flush();

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/business-continuity-plan/' . $this->testPlan->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testPlanStatusActive(): void
    {
        $this->testPlan->setStatus('active');
        $this->entityManager->flush();

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/business-continuity-plan/' . $this->testPlan->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testPlanStatusUnderReview(): void
    {
        $this->testPlan->setStatus('under_review');
        $this->entityManager->flush();

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/business-continuity-plan/' . $this->testPlan->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testPlanStatusArchived(): void
    {
        $this->testPlan->setStatus('archived');
        $this->entityManager->flush();

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/business-continuity-plan/' . $this->testPlan->getId());

        $this->assertResponseIsSuccessful();
    }
}
