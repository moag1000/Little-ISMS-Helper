<?php

declare(strict_types=1);

namespace App\Tests\Controller\Bulk;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Smoke tests for ThreatIntelligence bulk-export endpoint.
 *
 * Note: ThreatIntelligence entity has a pre-existing schema issue where
 * the `references` property maps to a SQL reserved word column name in
 * MariaDB. Therefore these tests avoid persisting ThreatIntelligence
 * entities and instead test endpoint-level guards (auth, method, empty-ids).
 *
 * @see https://dev.mysql.com/doc/refman/8.0/en/reserved-words.html
 */
class ThreatIntelligenceBulkActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $userRole = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $lock = static::getContainer()->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lock)) { @file_put_contents($lock, date('c')); }

        $uid = uniqid('bulk_ti_', true);
        $this->tenant = (new Tenant())->setName('Tenant ' . $uid)->setCode('thi_' . substr($uid, -7));
        $this->em->persist($this->tenant);
        $this->userRole = $this->makeUser('user_' . $uid . '@x.test', $this->tenant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->userRole) { try { $x = $this->em->find(User::class, $this->userRole->getId()); if ($x) $this->em->remove($x); } catch (\Exception) {} }
        if ($this->tenant) { try { $x = $this->em->find(Tenant::class, $this->tenant->getId()); if ($x) $this->em->remove($x); } catch (\Exception) {} }
        try { $this->em->flush(); } catch (\Exception) {}
        parent::tearDown();
    }

    #[Test]
    public function exportRejectsGet(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('GET', '/en/threat-intelligence/bulk-export');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function exportRequiresAuth(): void
    {
        $this->client->request('POST', '/en/threat-intelligence/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [99999], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseRedirects();
    }

    #[Test]
    public function exportReturns400ForEmptyIds(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/threat-intelligence/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    #[Test]
    public function exportReturns404ForNonExistentIds(): void
    {
        $this->client->loginUser($this->userRole);
        // Non-existent ID — endpoint should return 404 since no exportable items found
        $this->client->request('POST', '/en/threat-intelligence/bulk-export', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [99999998], '_token' => $this->getBulkCsrfToken()]));
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
}
