<?php

declare(strict_types=1);

namespace App\Tests\Controller\Bulk;

use App\Entity\Patch;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Smoke tests for Patch bulk-export endpoint.
 */
class PatchBulkActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $userRole = null;
    private ?Tenant $otherTenant = null;
    private ?Patch $patch = null;
    private ?Patch $otherPatch = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $lock = static::getContainer()->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lock)) { @file_put_contents($lock, date('c')); }

        $uid = uniqid('bulk_ptch_', true);
        $this->tenant = (new Tenant())->setName('Tenant ' . $uid)->setCode('tp_' . substr($uid, -8));
        $this->em->persist($this->tenant);
        $this->userRole = $this->makeUser('user_' . $uid . '@x.test', $this->tenant);

        $this->otherTenant = (new Tenant())->setName('OtherT ' . $uid)->setCode('op_' . substr($uid, -8));
        $this->em->persist($this->otherTenant);

        $this->patch = $this->makePatch('Patch A ' . $uid, $this->tenant);
        $this->otherPatch = $this->makePatch('Patch B ' . $uid, $this->otherTenant);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ([$this->patch, $this->otherPatch] as $e) {
            if ($e) { try { $x = $this->em->find(Patch::class, $e->getId()); if ($x) $this->em->remove($x); } catch (\Exception) {} }
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
        $this->client->request('GET', '/en/patch/bulk-export');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function exportRequiresAuth(): void
    {
        $this->client->request('POST', '/en/patch/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->patch->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseRedirects();
    }

    #[Test]
    public function exportReturnsCsv(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/patch/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->patch->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('text/csv', $this->client->getResponse()->headers->get('Content-Type') ?? '');
    }

    #[Test]
    public function exportSkipsCrossTenant(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/patch/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->otherPatch->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }


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
        if (!$this->client->getRequest()) { $this->client->request('GET', '/'); }
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

    private function makePatch(string $title, Tenant $tenant): Patch
    {
        $p = new Patch();
        $p->setTitle($title)->setPatchId('KB' . uniqid())
          ->setDescription('Test patch description')
          ->setProduct('Test Product')
          ->setStatus('available')->setVendor('Vendor')->setTenant($tenant);
        $this->em->persist($p);
        return $p;
    }
}
