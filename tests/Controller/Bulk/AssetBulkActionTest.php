<?php

declare(strict_types=1);

namespace App\Tests\Controller\Bulk;

use App\Entity\Asset;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Smoke tests for Asset bulk-export and bulk-assign endpoints.
 */
class AssetBulkActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $userRole = null;
    private ?User $managerRole = null;
    private ?Tenant $otherTenant = null;
    private ?User $otherUser = null;
    private ?Asset $asset = null;
    private ?Asset $otherAsset = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $lock = static::getContainer()->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lock)) { @file_put_contents($lock, date('c')); }

        $uid = uniqid('bulk_asset_', true);

        $this->tenant = (new Tenant())->setName('Tenant ' . $uid)->setCode('ta_' . substr($uid, -8));
        $this->em->persist($this->tenant);
        $this->userRole = $this->makeUser('user_' . $uid . '@x.test', ['ROLE_USER'], $this->tenant);
        $this->managerRole = $this->makeUser('mgr_' . $uid . '@x.test', ['ROLE_MANAGER'], $this->tenant);

        $this->otherTenant = (new Tenant())->setName('OtherT ' . $uid)->setCode('oa_' . substr($uid, -8));
        $this->em->persist($this->otherTenant);
        $this->otherUser = $this->makeUser('other_' . $uid . '@x.test', ['ROLE_USER'], $this->otherTenant);

        $this->asset = $this->makeAsset('Asset A ' . $uid, $this->tenant);
        $this->otherAsset = $this->makeAsset('Asset B ' . $uid, $this->otherTenant);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ([$this->asset, $this->otherAsset] as $e) {
            if ($e) { try { $x = $this->em->find(Asset::class, $e->getId()); if ($x) $this->em->remove($x); } catch (\Exception) {} }
        }
        foreach ([$this->userRole, $this->managerRole, $this->otherUser] as $u) {
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
        $this->client->request('GET', '/en/asset/bulk-export');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function exportRequiresAuth(): void
    {
        $this->client->request('POST', '/en/asset/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->asset->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseRedirects();
    }

    #[Test]
    public function exportReturnsCsvForOwnAsset(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/asset/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->asset->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('text/csv', $this->client->getResponse()->headers->get('Content-Type') ?? '');
    }

    #[Test]
    public function exportSkipsCrossTenantAsset(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/asset/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->otherAsset->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ── CSRF enforcement (audit C-1 / OWASP A01) ────────────────────────────

    #[Test]
    public function exportRejectsMissingCsrfToken(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/asset/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->asset->getId()]]));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function assignRejectsMissingCsrfToken(): void
    {
        $this->client->loginUser($this->managerRole);
        $this->client->request('POST', '/en/asset/bulk-assign', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->asset->getId()], 'assignee_id' => $this->managerRole->getId()]));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function assignRejectsGet(): void
    {
        $this->client->loginUser($this->managerRole);
        $this->client->request('GET', '/en/asset/bulk-assign');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function assignRequiresManagerRole(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/asset/bulk-assign', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->asset->getId()], 'assignee_id' => $this->userRole->getId(), '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function assignUpdatesOwnerUser(): void
    {
        $this->client->loginUser($this->managerRole);
        $this->client->request('POST', '/en/asset/bulk-assign', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->asset->getId()], 'assignee_id' => $this->managerRole->getId(), '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok'] ?? false);
        $this->assertSame(1, $body['changed'] ?? 0);
    }

    #[Test]
    public function assignSkipsCrossTenantAsset(): void
    {
        $this->client->loginUser($this->managerRole);
        $this->client->request('POST', '/en/asset/bulk-assign', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [$this->otherAsset->getId()], 'assignee_id' => $this->managerRole->getId(), '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(0, $body['changed'] ?? -1);
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
        $this->client->request('GET', '/');
        $token = $csrfManager->getToken('bulk_action');
        return $token->getValue();
    }

    private function makeUser(string $email, array $roles, Tenant $tenant): User
    {
        $u = new User();
        $u->setEmail($email)->setFirstName('T')->setLastName('U')
          ->setRoles($roles)->setPassword('hashed')->setTenant($tenant)->setIsActive(true);
        $this->em->persist($u);
        return $u;
    }

    private function makeAsset(string $name, Tenant $tenant): Asset
    {
        $a = new Asset();
        $a->setName($name)->setAssetType('hardware')->setOwner('Owner')
          ->setTenant($tenant)->setConfidentialityValue(2)->setIntegrityValue(2)
          ->setAvailabilityValue(2)->setStatus('active')->setDataClassification('internal');
        $this->em->persist($a);
        return $a;
    }
}
