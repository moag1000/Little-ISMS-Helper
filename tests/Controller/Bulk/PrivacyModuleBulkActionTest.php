<?php

declare(strict_types=1);

namespace App\Tests\Controller\Bulk;

use App\Entity\Consent;
use App\Entity\DataBreach;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\DataSubjectRequest;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\DataBreachStatus;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Smoke tests for privacy-module bulk-export endpoints:
 * DataBreach, DPIA, ProcessingActivity, Consent, DataSubjectRequest.
 *
 * The privacy module is mocked active for all tests.
 */
class PrivacyModuleBulkActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $userRole = null;
    private ?User $auditorRole = null;
    private ?User $managerRole = null;
    private ?User $dpoRole = null;
    private ?Tenant $otherTenant = null;

    // Entities under test
    private ?DataBreach $breach = null;
    private ?DataBreach $otherBreach = null;
    private ?DataProtectionImpactAssessment $dpia = null;
    private ?DataProtectionImpactAssessment $otherDpia = null;
    private ?ProcessingActivity $pa = null;
    private ?ProcessingActivity $otherPa = null;
    private ?Consent $consent = null;
    private ?Consent $otherConsent = null;
    private ?DataSubjectRequest $dsr = null;
    private ?DataSubjectRequest $otherDsr = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $lock = $container->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lock)) { @file_put_contents($lock, date('c')); }

        // Mock all modules active
        $moduleService = $this->createMock(ModuleConfigurationService::class);
        $moduleService->method('isModuleActive')->willReturn(true);
        $container->set(ModuleConfigurationService::class, $moduleService);

        $uid = uniqid('bulk_priv_', true);
        $this->tenant = (new Tenant())->setName('Tenant ' . $uid)->setCode('tprv_' . substr($uid, -7));
        $this->em->persist($this->tenant);
        $this->userRole = $this->makeUser('user_' . $uid . '@x.test', ['ROLE_USER'], $this->tenant);
        $this->auditorRole = $this->makeUser('aud_' . $uid . '@x.test', ['ROLE_AUDITOR'], $this->tenant);
        $this->managerRole = $this->makeUser('mgr_' . $uid . '@x.test', ['ROLE_MANAGER'], $this->tenant);
        $this->dpoRole = $this->makeUser('dpo_' . $uid . '@x.test', ['ROLE_DPO', 'ROLE_USER'], $this->tenant);

        $this->otherTenant = (new Tenant())->setName('OtherT ' . $uid)->setCode('oprv_' . substr($uid, -7));
        $this->em->persist($this->otherTenant);

        // DataBreach
        $this->breach = $this->makeBreach('Breach A ' . $uid, $this->tenant);
        $this->otherBreach = $this->makeBreach('Breach B ' . $uid, $this->otherTenant);

        // DPIA
        $this->dpia = $this->makeDpia('DPIA A ' . $uid, $this->tenant);
        $this->otherDpia = $this->makeDpia('DPIA B ' . $uid, $this->otherTenant);

        // ProcessingActivity
        $this->pa = $this->makePa('PA A ' . $uid, $this->tenant);
        $this->otherPa = $this->makePa('PA B ' . $uid, $this->otherTenant);

        // Consent — requires ProcessingActivity
        $this->consent = $this->makeConsent('subject_a_' . $uid, $this->tenant, $this->pa);
        $this->otherConsent = $this->makeConsent('subject_b_' . $uid, $this->otherTenant, $this->otherPa);

        // DataSubjectRequest
        $this->dsr = $this->makeDsr('DSR A ' . $uid, $this->tenant);
        $this->otherDsr = $this->makeDsr('DSR B ' . $uid, $this->otherTenant);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $classes = [
            DataSubjectRequest::class => [$this->dsr, $this->otherDsr],
            Consent::class => [$this->consent, $this->otherConsent],
            DataBreach::class => [$this->breach, $this->otherBreach],
            DataProtectionImpactAssessment::class => [$this->dpia, $this->otherDpia],
            ProcessingActivity::class => [$this->pa, $this->otherPa],
        ];
        foreach ($classes as $class => $entities) {
            foreach ($entities as $e) {
                if ($e) { try { $x = $this->em->find($class, $e->getId()); if ($x) $this->em->remove($x); } catch (\Exception) {} }
            }
        }
        foreach ([$this->userRole, $this->auditorRole, $this->managerRole] as $u) {
            if ($u) { try { $x = $this->em->find(User::class, $u->getId()); if ($x) $this->em->remove($x); } catch (\Exception) {} }
        }
        foreach ([$this->tenant, $this->otherTenant] as $t) {
            if ($t) { try { $x = $this->em->find(Tenant::class, $t->getId()); if ($x) $this->em->remove($x); } catch (\Exception) {} }
        }
        try { $this->em->flush(); } catch (\Exception) {}
        parent::tearDown();
    }

    // ── DataBreach bulk-export (ROLE_AUDITOR) ────────────────────────────────

    #[Test]
    public function dataBreachExportRejectsGet(): void
    {
        $this->client->loginUser($this->auditorRole);
        $this->client->request('GET', '/en/data-breach/bulk-export');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function dataBreachExportRequiresAuth(): void
    {
        $this->client->request('POST', '/en/data-breach/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->breach->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseRedirects();
    }

    #[Test]
    public function dataBreachExportReturnsCsv(): void
    {
        $this->client->loginUser($this->auditorRole);
        $this->client->request('POST', '/en/data-breach/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->breach->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('text/csv', $this->client->getResponse()->headers->get('Content-Type') ?? '');
    }

    #[Test]
    public function dataBreachExportSkipsCrossTenant(): void
    {
        $this->client->loginUser($this->auditorRole);
        $this->client->request('POST', '/en/data-breach/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->otherBreach->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ── DPIA bulk-export ─────────────────────────────────────────────────────

    #[Test]
    public function dpiaExportRejectsGet(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('GET', '/en/dpia/bulk-export');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function dpiaExportReturnsCsv(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/dpia/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->dpia->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('text/csv', $this->client->getResponse()->headers->get('Content-Type') ?? '');
    }

    #[Test]
    public function dpiaExportSkipsCrossTenant(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/dpia/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->otherDpia->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ── ProcessingActivity bulk-export ───────────────────────────────────────

    #[Test]
    public function processingActivityExportRejectsGet(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('GET', '/en/processing-activity/bulk-export');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function processingActivityExportReturnsCsv(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/processing-activity/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->pa->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('text/csv', $this->client->getResponse()->headers->get('Content-Type') ?? '');
    }

    #[Test]
    public function processingActivityExportSkipsCrossTenant(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/processing-activity/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->otherPa->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ── ProcessingActivity bulk-status-change (CC-5) ─────────────────────────

    #[Test]
    public function processingActivityStatusChangeRejectsGet(): void
    {
        $this->client->loginUser($this->managerRole);
        $this->client->request('GET', '/en/processing-activity/bulk-status-change');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function processingActivityStatusChangeRequiresManagerRole(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/processing-activity/bulk-status-change', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->pa->getId()], 'newStatus' => 'in_review', '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function processingActivityStatusChangeReturnsJson(): void
    {
        $this->client->loginUser($this->managerRole);
        $this->client->request('POST', '/en/processing-activity/bulk-status-change', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->pa->getId()], 'newStatus' => 'in_review', '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('ok', $body);
    }

    // ── CSRF enforcement (audit C-1 / OWASP A01) ────────────────────────────

    #[Test]
    public function processingActivityExportRejectsMissingCsrfToken(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/processing-activity/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->pa->getId()]]));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function processingActivityStatusChangeRejectsMissingCsrfToken(): void
    {
        $this->client->loginUser($this->managerRole);
        $this->client->request('POST', '/en/processing-activity/bulk-status-change', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->pa->getId()], 'newStatus' => 'in_review']));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function dataBreachExportRejectsMissingCsrfToken(): void
    {
        $this->client->loginUser($this->auditorRole);
        $this->client->request('POST', '/en/data-breach/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->breach->getId()]]));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ── Consent bulk-export ──────────────────────────────────────────────────

    #[Test]
    public function consentExportRejectsGet(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('GET', '/en/consent/bulk-export');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function consentExportReturnsCsv(): void
    {
        $this->client->loginUser($this->dpoRole);
        $this->client->request('POST', '/en/consent/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->consent->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('text/csv', $this->client->getResponse()->headers->get('Content-Type') ?? '');
    }

    #[Test]
    public function consentExportSkipsCrossTenant(): void
    {
        $this->client->loginUser($this->dpoRole);
        $this->client->request('POST', '/en/consent/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->otherConsent->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ── DataSubjectRequest bulk-export ───────────────────────────────────────

    #[Test]
    public function dsrExportRejectsGet(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('GET', '/en/data-subject-request/bulk-export');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function dsrExportReturnsCsv(): void
    {
        $this->client->loginUser($this->dpoRole);
        $this->client->request('POST', '/en/data-subject-request/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->dsr->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('text/csv', $this->client->getResponse()->headers->get('Content-Type') ?? '');
    }

    #[Test]
    public function dsrExportSkipsCrossTenant(): void
    {
        $this->client->loginUser($this->dpoRole);
        $this->client->request('POST', '/en/data-subject-request/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->otherDsr->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ── helpers ──────────────────────────────────────────────────────────────


    /**
     * Generates a valid CSRF token for bulk-action endpoints by writing it
     * directly to the session (audit C-1 — OWASP A01).
     */
    private function getBulkCsrfToken(): string
    {
        // bulk_action is session-stateful CSRF: the controller validates the
        // body "_token" against the session. Warm the SAME session the caller
        // logged into (loginUser pinned its cookie) via a GET, set the token
        // under SessionTokenStorage's '_csrf/bulk_action' key, then save+close
        // so it reaches storage — a late set() after the response stays
        // in-memory only and the POST would open a fresh session without it.
        // (Do NOT mint a new session/cookie here: that would drop the login.)
        $this->client->request('GET', '/');
        $session = $this->client->getRequest()->getSession();
        $tokenValue = (new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/bulk_action', $tokenValue);
        $session->save();
        return $tokenValue;
    }

    private function makeUser(string $email, array $roles, Tenant $tenant): User
    {
        $u = new User();
        $u->setEmail($email)->setFirstName('T')->setLastName('U')
          ->setRoles($roles)->setPassword('hashed')->setTenant($tenant)->setIsActive(true);
        $this->em->persist($u);
        return $u;
    }

    private function makeBreach(string $title, Tenant $tenant): DataBreach
    {
        $b = new DataBreach();
        $b->setTitle($title)->setReferenceNumber('REF-' . uniqid())
          ->setStatus(DataBreachStatus::UnderAssessment)->setSeverity('high')
          ->setBreachNature('Unauthorized access')->setLikelyConsequences('Data exposure')
          ->setMeasuresTaken('Incident response activated')
          ->setDetectedAt(new \DateTimeImmutable())->setTenant($tenant);
        $this->em->persist($b);
        return $b;
    }

    private function makeDpia(string $title, Tenant $tenant): DataProtectionImpactAssessment
    {
        $d = new DataProtectionImpactAssessment();
        $d->setTitle($title)->setReferenceNumber('DPIA-' . uniqid())
          ->setRiskLevel('medium')
          ->setProcessingDescription('Test processing description')
          ->setProcessingPurposes('Testing purposes')
          ->setNecessityAssessment('Necessary for compliance testing')
          ->setProportionalityAssessment('Proportionate to testing needs')
          ->setLegalBasis('legitimate_interests')
          ->setTechnicalMeasures('Encryption, access control')
          ->setOrganizationalMeasures('Data minimisation, training')
          ->setTenant($tenant);
        $this->em->persist($d);
        return $d;
    }

    private function makePa(string $name, Tenant $tenant): ProcessingActivity
    {
        $p = new ProcessingActivity();
        $p->setName($name)->setPurposes(['testing'])->setDataSubjectCategories(['customers'])
          ->setPersonalDataCategories(['identification'])->setLegalBasis('consent')->setTenant($tenant);
        $this->em->persist($p);
        return $p;
    }

    private function makeConsent(string $identifier, Tenant $tenant, ProcessingActivity $pa): Consent
    {
        $now = new \DateTimeImmutable();
        $c = new Consent();
        $c->setDataSubjectIdentifier($identifier)->setIdentifierType('email')
          ->setStatus('active')
          ->setGrantedAt($now)
          ->setConsentMethod('checkbox')
          ->setConsentText('I agree to the processing of my personal data.')
          ->setDocumentedAt($now)
          ->setProcessingActivity($pa)->setTenant($tenant);
        $this->em->persist($c);
        return $c;
    }

    private function makeDsr(string $name, Tenant $tenant): DataSubjectRequest
    {
        $d = new DataSubjectRequest();
        $d->setRequestType('access')->setDataSubjectName($name)
          ->setDescription('Request for data access')
          ->setStatus('received')
          ->setReceivedAt(new \DateTimeImmutable())
          ->setTenant($tenant);
        $this->em->persist($d);
        return $d;
    }
}
