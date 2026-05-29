<?php

declare(strict_types=1);

namespace App\Tests\Controller\Bulk;

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
        foreach ([$this->control, $this->otherControl] as $e) {
            if ($e) { try { $x = $this->em->find(Control::class, $e->getId()); if ($x) $this->em->remove($x); } catch (\Exception) {} }
        }
        if ($this->userRole) { try { $x = $this->em->find(User::class, $this->userRole->getId()); if ($x) $this->em->remove($x); } catch (\Exception) {} }
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

    private function makeUser(string $email, Tenant $tenant): User
    {
        $u = new User();
        $u->setEmail($email)->setFirstName('T')->setLastName('U')
          ->setRoles(['ROLE_USER'])->setPassword('hashed')->setTenant($tenant)->setIsActive(true);
        $this->em->persist($u);
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
