<?php

declare(strict_types=1);

namespace App\Tests\Controller\Bulk;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Smoke tests for Statement of Applicability (SoA) bulk-export endpoint.
 * SoA bulk-export operates on Control entities via ControlRepository.
 */
class SoaBulkActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $userRole = null;
    private ?Tenant $otherTenant = null;
    private ?Control $control = null;
    private ?Control $otherControl = null;
    /** @var list<User> */
    private array $extraUsers = [];
    /** @var list<ComplianceFramework> */
    private array $frameworks = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $lock = static::getContainer()->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lock)) { @file_put_contents($lock, date('c')); }

        $uid = uniqid('bulk_soa_', true);
        $this->tenant = (new Tenant())->setName('Tenant ' . $uid)->setCode('tsoa_' . substr($uid, -7));
        $this->em->persist($this->tenant);
        $this->userRole = $this->makeUser('user_' . $uid . '@x.test', $this->tenant);

        $this->otherTenant = (new Tenant())->setName('OtherT ' . $uid)->setCode('osoa_' . substr($uid, -7));
        $this->em->persist($this->otherTenant);

        $this->control = $this->makeControl('A.1.1', 'Control A ' . $uid, $this->tenant);
        $this->otherControl = $this->makeControl('A.1.2', 'Control B ' . $uid, $this->otherTenant);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->frameworks as $f) {
            try {
                $conn = $this->em->getConnection();
                $conn->executeStatement('DELETE r FROM compliance_requirement r WHERE r.framework_id = ?', [$f->getId()]);
                $conn->executeStatement('DELETE FROM compliance_framework WHERE id = ?', [$f->getId()]);
            } catch (\Exception) {}
        }
        foreach ([$this->control, $this->otherControl] as $e) {
            if ($e) { try { $x = $this->em->find(Control::class, $e->getId()); if ($x) $this->em->remove($x); } catch (\Exception) {} }
        }
        foreach ([$this->userRole, ...$this->extraUsers] as $u) {
            if ($u) { try { $x = $this->em->find(User::class, $u->getId()); if ($x) $this->em->remove($x); } catch (\Exception) {} }
        }
        foreach ([$this->tenant, $this->otherTenant] as $t) {
            if ($t) { try { $x = $this->em->find(Tenant::class, $t->getId()); if ($x) $this->em->remove($x); } catch (\Exception) {} }
        }
        try { $this->em->flush(); } catch (\Exception) {}
        parent::tearDown();
    }

    #[Test]
    public function exportRejectsGet(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('GET', '/en/soa/bulk-export');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function exportRequiresAuth(): void
    {
        $this->client->request('POST', '/en/soa/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->control->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseRedirects();
    }

    #[Test]
    public function exportReturnsCsv(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/soa/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->control->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('text/csv', $this->client->getResponse()->headers->get('Content-Type') ?? '');
    }

    #[Test]
    public function exportSkipsCrossTenant(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/soa/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->otherControl->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }


    /**
     * bulkApplicability had only a CSRF check: any authenticated account —
     * a read-only holding auditor included — could change applicability.
     */
    #[Test]
    public function applicabilityIsDeniedForAHoldingAuditor(): void
    {
        $auditor = $this->makeUser('auditor_' . uniqid() . '@x.test', $this->tenant, ['ROLE_KONZERN_AUDITOR']);
        $this->em->flush();

        $this->client->loginUser($auditor);
        $this->postApplicability([$this->control->getId()]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertTrue($this->reload($this->control)->isApplicable());
    }

    #[Test]
    public function applicabilityIsDeniedForAPlainUser(): void
    {
        $this->client->loginUser($this->userRole);
        $this->postApplicability([$this->control->getId()]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function managerChangesApplicabilityOfOwnControlsOnly(): void
    {
        $manager = $this->makeUser('manager_' . uniqid() . '@x.test', $this->tenant, ['ROLE_MANAGER']);
        $this->em->flush();

        $this->client->loginUser($manager);
        $this->postApplicability([$this->control->getId(), $this->otherControl->getId()]);

        $this->assertResponseRedirects();
        $this->assertFalse($this->reload($this->control)->isApplicable());
        $this->assertTrue($this->reload($this->otherControl)->isApplicable(), 'foreign control untouched');
    }

    #[Test]
    public function applicabilityIsDeniedForAnAuditor(): void
    {
        $auditor = $this->makeUser('aud_' . uniqid() . '@x.test', $this->tenant, ['ROLE_AUDITOR']);
        $this->em->flush();

        $this->client->loginUser($auditor);
        $this->postApplicability([$this->control->getId()]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertTrue($this->reload($this->control)->isApplicable());
    }

    /**
     * app_soa_edit had no authorisation beyond the firewall: any account of
     * the organisation — auditors included — could edit any control.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function readOnlyRoles(): iterable
    {
        yield 'auditor' => [['ROLE_AUDITOR']];
        yield 'holding auditor' => [['ROLE_KONZERN_AUDITOR']];
        yield 'plain user' => [['ROLE_USER']];
    }

    /**
     * @param list<string> $roles
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('readOnlyRoles')]
    public function editIsDeniedForReadOnlyRoles(array $roles): void
    {
        $actor = $this->makeUser('ro_' . uniqid() . '@x.test', $this->tenant, $roles);
        $this->em->flush();

        $this->client->loginUser($actor);
        $this->client->request('GET', '/en/soa/' . $this->control->getId() . '/edit');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('POST', '/en/soa/' . $this->control->getId() . '/edit', ['control' => ['name' => 'hijacked']]);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertStringNotContainsString('hijacked', (string) $this->reload($this->control)->getName());
    }

    #[Test]
    public function managerMayOpenTheEditForm(): void
    {
        $manager = $this->makeUser('mgr_' . uniqid() . '@x.test', $this->tenant, ['ROLE_MANAGER']);
        $this->em->flush();

        $this->client->loginUser($manager);
        $this->client->request('GET', '/en/soa/' . $this->control->getId() . '/edit');

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function managerCannotEditAForeignControl(): void
    {
        $manager = $this->makeUser('mgr_' . uniqid() . '@x.test', $this->tenant, ['ROLE_MANAGER']);
        $this->em->flush();

        $this->client->loginUser($manager);
        $this->client->request('GET', '/en/soa/' . $this->otherControl->getId() . '/edit');

        $this->assertContains($this->client->getResponse()->getStatusCode(), [Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND]);
    }

    #[Test]
    public function showHidesTheEditLinkFromAnAuditor(): void
    {
        $auditor = $this->makeUser('aud_' . uniqid() . '@x.test', $this->tenant, ['ROLE_AUDITOR']);
        $this->em->flush();

        $this->client->loginUser($auditor);
        $this->client->request('GET', '/en/soa/' . $this->control->getId());

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString(
            '/soa/' . $this->control->getId() . '/edit',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /**
     * app_soa_accept_suggestion only checked CSRF: any account of the
     * organisation could link a requirement to a control.
     *
     * @param list<string> $roles
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('readOnlyRoles')]
    public function acceptSuggestionIsDeniedForReadOnlyRoles(array $roles): void
    {
        $requirement = $this->makeRequirement();
        $actor = $this->makeUser('ro_' . uniqid() . '@x.test', $this->tenant, $roles);
        $this->em->flush();

        $this->client->loginUser($actor);
        $this->postAcceptSuggestion($requirement);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertSame(0, $this->countMapping($requirement));
    }

    #[Test]
    public function managerAcceptsSuggestion(): void
    {
        $requirement = $this->makeRequirement();
        $manager = $this->makeUser('mgr_' . uniqid() . '@x.test', $this->tenant, ['ROLE_MANAGER']);
        $this->em->flush();

        $this->client->loginUser($manager);
        $this->postAcceptSuggestion($requirement);

        $this->assertResponseRedirects();
        $this->assertSame(1, $this->countMapping($requirement));
    }

    private function makeRequirement(): ComplianceRequirement
    {
        $uid = substr(uniqid('', true), -10);
        $framework = (new ComplianceFramework())
            ->setCode('SOAFW_' . $uid)->setName('FW ' . $uid)->setVersion('1')
            ->setApplicableIndustry('all')->setRegulatoryBody('test');
        $this->em->persist($framework);
        $requirement = (new ComplianceRequirement())
            ->setFramework($framework)->setRequirementId('R-' . $uid)->setTitle('Req ' . $uid)
            ->setDescription('Requirement description')->setPriority('medium');
        $this->em->persist($requirement);
        $this->frameworks[] = $framework;

        return $requirement;
    }

    private function postAcceptSuggestion(ComplianceRequirement $requirement): void
    {
        $tokenId = 'accept_suggestion_' . $this->control->getId() . '_' . $requirement->getId();
        $this->client->request('GET', '/');
        $session = $this->client->getRequest()->getSession();
        $token = (new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/' . $tokenId, $token);
        $session->save();

        $this->client->request(
            'POST',
            '/en/soa/' . $this->control->getId() . '/accept-suggestion/' . $requirement->getId(),
            ['_token' => $token, 'confidence' => '0.8'],
        );
    }

    private function countMapping(ComplianceRequirement $requirement): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM compliance_requirement_control WHERE compliance_requirement_id = ? AND control_id = ?',
            [$requirement->getId(), $this->control->getId()],
        );
    }

    private function reload(Control $control): Control
    {
        $this->em->clear();
        // The request left the tenant filter scoped to the actor's tenant.
        if ($this->em->getFilters()->isEnabled('tenant_filter')) {
            $this->em->getFilters()->disable('tenant_filter');
        }
        $fresh = $this->em->find(Control::class, $control->getId());
        $this->assertInstanceOf(Control::class, $fresh);

        return $fresh;
    }

    /**
     * @param list<int|null> $ids
     */
    private function postApplicability(array $ids): void
    {
        $this->client->request('GET', '/');
        $session = $this->client->getRequest()->getSession();
        $token = (new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/soa_bulk_applicability', $token);
        $session->save();

        $this->client->request('POST', '/en/soa/bulk/applicability', [
            'control_ids' => $ids,
            'applicable' => '0',
            'reason' => 'Not in scope',
            '_token' => $token,
        ]);
    }

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

    /**
     * @param list<string> $roles
     */
    private function makeUser(string $email, Tenant $tenant, array $roles = ['ROLE_USER']): User
    {
        $u = new User();
        $u->setEmail($email)->setFirstName('T')->setLastName('U')
          ->setRoles($roles)->setPassword('hashed')->setTenant($tenant)->setIsActive(true);
        $this->em->persist($u);
        if ($roles !== ['ROLE_USER']) {
            $this->extraUsers[] = $u;
        }
        return $u;
    }

    private function makeControl(string $controlId, string $name, Tenant $tenant): Control
    {
        $c = new Control();
        $c->setControlId($controlId . '_' . uniqid())->setName($name)
          ->setDescription('Test control description')
          ->setCategory('Organisational controls')
          ->setApplicable(true)
          ->setControlType('preventive')->setTenant($tenant);
        $this->em->persist($c);
        return $c;
    }
}
