<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Risk;
use App\Entity\RiskTreatmentPlan;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\Attributes\Test;

class RiskTreatmentPlanControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?Risk $testRisk = null;
    private ?RiskTreatmentPlan $testPlan = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if ($this->testPlan) {
            try {
                $plan = $this->entityManager->find(RiskTreatmentPlan::class, $this->testPlan->getId());
                if ($plan) {
                    $this->entityManager->remove($plan);
                }
            } catch (\Exception $e) {}
        }

        if ($this->testRisk) {
            try {
                $risk = $this->entityManager->find(Risk::class, $this->testRisk->getId());
                if ($risk) {
                    $this->entityManager->remove($risk);
                }
            } catch (\Exception $e) {}
        }

        if ($this->testUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->testUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {}
        }

        if ($this->adminUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->adminUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {}
        }

        if ($this->testTenant) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($tenant) {
                    $this->entityManager->remove($tenant);
                }
            } catch (\Exception $e) {}
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {}

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('test_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('Test Tenant ' . $uniqueId);
        $this->testTenant->setCode('test_tenant_' . $uniqueId);
        $this->entityManager->persist($this->testTenant);

        $this->testUser = new User();
        $this->testUser->setEmail('testuser_' . $uniqueId . '@example.com');
        $this->testUser->setFirstName('Test');
        $this->testUser->setLastName('User');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        $this->adminUser = new User();
        $this->adminUser->setEmail('admin_' . $uniqueId . '@example.com');
        $this->adminUser->setFirstName('Admin');
        $this->adminUser->setLastName('User');
        $this->adminUser->setRoles(['ROLE_ADMIN']);
        $this->adminUser->setPassword('hashed_password');
        $this->adminUser->setTenant($this->testTenant);
        $this->adminUser->setIsActive(true);
        $this->entityManager->persist($this->adminUser);

        // Create a Risk first (required by RiskTreatmentPlan)
        $this->testRisk = new Risk();
        $this->testRisk->setTenant($this->testTenant);
        $this->testRisk->setTitle('Test Risk ' . $uniqueId);
        $this->testRisk->setDescription('Test risk description');
        $this->testRisk->setCategory('operational');
        $this->testRisk->setProbability(3);
        $this->testRisk->setImpact(3);
        $this->testRisk->setResidualProbability(2);
        $this->testRisk->setResidualImpact(2);
        $this->testRisk->setTreatmentStrategy(\App\Enum\TreatmentStrategy::Mitigate);
        $this->testRisk->setStatus(\App\Enum\RiskStatus::Open);
        $this->testRisk->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($this->testRisk);

        $this->testPlan = new RiskTreatmentPlan();
        $this->testPlan->setTenant($this->testTenant);
        $this->testPlan->setRisk($this->testRisk);
        $this->testPlan->setTitle('Test Risk Treatment Plan ' . $uniqueId);
        $this->testPlan->setDescription('Test description');
        $this->testPlan->setStatus('planned');
        $this->testPlan->setPriority('medium');
        $this->testPlan->setStartDate(new \DateTime());
        $this->testPlan->setTargetCompletionDate(new \DateTime('+30 days'));
        $this->entityManager->persist($this->testPlan);

        $this->entityManager->flush();
    }

    #[Test]
    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/risk-treatment-plan');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testIndexDisplaysForUser(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/risk-treatment-plan');
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/risk-treatment-plan/new');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testNewDisplaysFormForUser(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/risk-treatment-plan/new');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    #[Test]
    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/risk-treatment-plan/' . $this->testPlan->getId());
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testShowDisplaysForUser(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/risk-treatment-plan/' . $this->testPlan->getId());
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/risk-treatment-plan/' . $this->testPlan->getId() . '/edit');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testEditDisplaysFormForUser(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/risk-treatment-plan/' . $this->testPlan->getId() . '/edit');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== IN-PAGE FORM-MODAL (Turbo Frame) TESTS ==========

    #[Test]
    public function testShowInFrameRendersDetailModalPartial(): void
    {
        $this->client->loginUser($this->testUser);

        $this->client->request(
            'GET',
            '/en/risk-treatment-plan/' . $this->testPlan->getId(),
            [], [], ['HTTP_TURBO_FRAME' => 'fa-form-modal'],
        );

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-frame id="fa-form-modal"', $html);
        self::assertStringNotContainsString('<html', $html);
    }

    #[Test]
    public function testEditInFrameRendersFormModalPartial(): void
    {
        $this->client->loginUser($this->testUser);

        $this->client->request(
            'GET',
            '/en/risk-treatment-plan/' . $this->testPlan->getId() . '/edit',
            [], [], ['HTTP_TURBO_FRAME' => 'fa-form-modal'],
        );

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-frame id="fa-form-modal"', $html);
        self::assertStringContainsString('fa-form-layout--modal', $html);
        self::assertStringContainsString('action="/en/risk-treatment-plan/' . $this->testPlan->getId() . '/edit"', $html);
        self::assertStringNotContainsString('<html', $html);
    }

    #[Test]
    public function testEditWithoutFrameRendersFullPage(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/risk-treatment-plan/' . $this->testPlan->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('<html', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function testEditInFrameInvalidReturns422ModalPartial(): void
    {
        // The POST path is frame-aware: an invalid in-frame submit re-renders the
        // slim form-modal partial with 422 (errors inline), not the full page.
        $this->client->loginUser($this->testUser);

        $crawler = $this->client->request(
            'GET',
            '/en/risk-treatment-plan/' . $this->testPlan->getId() . '/edit',
            [], [], ['HTTP_TURBO_FRAME' => 'fa-form-modal'],
        );
        $form = $crawler->filter('form')->first()->form();
        $values = $form->getPhpValues();
        // Force invalid at the form layer (not the DB): an out-of-list value for
        // the required `priority` ChoiceType fails choice validation, so the form
        // is invalid and the managed entity keeps its valid value — the in-frame
        // branch re-renders the 422 partial and tearDown can still remove the row.
        $values['risk_treatment_plan']['priority'] = '__invalid__';

        $this->client->request(
            'POST',
            $form->getUri(),
            $values,
            [],
            ['HTTP_TURBO_FRAME' => 'fa-form-modal'],
        );

        $this->assertResponseStatusCodeSame(422);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-frame id="fa-form-modal"', $html);
        self::assertStringNotContainsString('<html', $html);
    }

    #[Test]
    public function testDeleteRequiresAdminRole(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('POST', '/en/risk-treatment-plan/' . $this->testPlan->getId() . '/delete');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testDeleteRequiresPost(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/en/risk-treatment-plan/' . $this->testPlan->getId() . '/delete');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }
}
