<?php

declare(strict_types=1);

namespace App\Tests\Controller\Bulk;

use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Smoke tests for BCM-module bulk-export endpoints:
 * BusinessContinuityPlan, BCExercise.
 *
 * The bcm module is mocked active for all tests.
 */
class BcmModuleBulkActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $userRole = null;
    private ?Tenant $otherTenant = null;
    private ?BusinessContinuityPlan $plan = null;
    private ?BusinessContinuityPlan $otherPlan = null;
    private ?BCExercise $exercise = null;
    private ?BCExercise $otherExercise = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $lock = $container->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lock)) { @file_put_contents($lock, date('c')); }

        // Mock all modules active (including bcm)
        $moduleService = $this->createMock(ModuleConfigurationService::class);
        $moduleService->method('isModuleActive')->willReturn(true);
        $container->set(ModuleConfigurationService::class, $moduleService);

        $uid = uniqid('bulk_bcm_', true);
        $this->tenant = (new Tenant())->setName('Tenant ' . $uid)->setCode('tbcm_' . substr($uid, -7));
        $this->em->persist($this->tenant);
        $this->userRole = $this->makeUser('user_' . $uid . '@x.test', $this->tenant);

        $this->otherTenant = (new Tenant())->setName('OtherT ' . $uid)->setCode('obcm_' . substr($uid, -7));
        $this->em->persist($this->otherTenant);

        $this->plan = $this->makePlan('Plan A ' . $uid, $this->tenant);
        $this->otherPlan = $this->makePlan('Plan B ' . $uid, $this->otherTenant);

        $this->exercise = $this->makeExercise('Exercise A ' . $uid, $this->tenant);
        $this->otherExercise = $this->makeExercise('Exercise B ' . $uid, $this->otherTenant);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ([BCExercise::class => [$this->exercise, $this->otherExercise],
                  BusinessContinuityPlan::class => [$this->plan, $this->otherPlan]] as $class => $entities) {
            foreach ($entities as $e) {
                if ($e) { try { $x = $this->em->find($class, $e->getId()); if ($x) $this->em->remove($x); } catch (\Exception) {} }
            }
        }
        if ($this->userRole) { try { $x = $this->em->find(User::class, $this->userRole->getId()); if ($x) $this->em->remove($x); } catch (\Exception) {} }
        foreach ([$this->tenant, $this->otherTenant] as $t) {
            if ($t) { try { $x = $this->em->find(Tenant::class, $t->getId()); if ($x) $this->em->remove($x); } catch (\Exception) {} }
        }
        try { $this->em->flush(); } catch (\Exception) {}
        parent::tearDown();
    }

    // ── BusinessContinuityPlan ───────────────────────────────────────────────

    #[Test]
    public function bcPlanExportRejectsGet(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('GET', '/en/business-continuity-plan/bulk-export');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function bcPlanExportRequiresAuth(): void
    {
        $this->client->request('POST', '/en/business-continuity-plan/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->plan->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseRedirects();
    }

    #[Test]
    public function bcPlanExportReturnsCsv(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/business-continuity-plan/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->plan->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('text/csv', $this->client->getResponse()->headers->get('Content-Type') ?? '');
    }

    #[Test]
    public function bcPlanExportSkipsCrossTenant(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/business-continuity-plan/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->otherPlan->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ── BCExercise ───────────────────────────────────────────────────────────

    #[Test]
    public function bcExerciseExportRejectsGet(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('GET', '/en/bc-exercise/bulk-export');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function bcExerciseExportRequiresAuth(): void
    {
        $this->client->request('POST', '/en/bc-exercise/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->exercise->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseRedirects();
    }

    #[Test]
    public function bcExerciseExportReturnsCsv(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/bc-exercise/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->exercise->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('text/csv', $this->client->getResponse()->headers->get('Content-Type') ?? '');
    }

    #[Test]
    public function bcExerciseExportSkipsCrossTenant(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/bc-exercise/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->otherExercise->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ── helpers ──────────────────────────────────────────────────────────────


    /**
     * Generates a valid CSRF token for bulk-action endpoints by writing it
     * directly to the session (audit C-1 — OWASP A01).
     */
    private function getBulkCsrfToken(): string
    {
        // Symfony's session-based CSRF stores tokens under the key '_csrf/<tokenId>'
        $tokenValue = bin2hex(random_bytes(16));
        $container  = static::getContainer();
        /** @var \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrfManager */
        $csrfManager = $container->get('security.csrf.token_manager');
        // Refresh the token for the 'bulk_action' ID so isCsrfTokenValid passes.
        // Warm the session via a GET request so CSRF storage can persist tokens.
        $this->client->request('GET', '/');
        $token = $csrfManager->getToken('bulk_action');
        return $token->getValue();
    }

    private function makeUser(string $email, Tenant $tenant): User
    {
        $u = new User();
        $u->setEmail($email)->setFirstName('T')->setLastName('U')
          ->setRoles(['ROLE_USER'])->setPassword('hashed')->setTenant($tenant)->setIsActive(true);
        $this->em->persist($u);
        return $u;
    }

    private function makePlan(string $name, Tenant $tenant): BusinessContinuityPlan
    {
        $bp = new BusinessProcess();
        $bp->setName('BP ' . $name)->setProcessOwner('Owner')->setCriticality('high')
           ->setRto(4)->setRpo(2)->setMtpd(24)
           ->setReputationalImpact(3)->setRegulatoryImpact(3)->setOperationalImpact(3)
           ->setCreatedAt(new \DateTimeImmutable())->setTenant($tenant);
        $this->em->persist($bp);

        $p = new BusinessContinuityPlan();
        $p->setName($name)->setActivationCriteria('Major disruption')
          ->setRecoveryProcedures('1. Assess damage 2. Activate team 3. Restore services')
          ->setBusinessProcess($bp)->setStatus('active')->setTenant($tenant);
        $this->em->persist($p);
        return $p;
    }

    private function makeExercise(string $name, Tenant $tenant): BCExercise
    {
        $e = new BCExercise();
        $e->setName($name)->setExerciseType('tabletop')
          ->setScope('Full BC plan scope including IT systems and personnel')
          ->setObjectives('Test recovery procedures and team response')
          ->setExerciseDate(new \DateTime('+30 days'))->setTenant($tenant);
        $this->em->persist($e);
        return $e;
    }
}
