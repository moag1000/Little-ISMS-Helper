<?php

declare(strict_types=1);

namespace App\Tests\Controller\Bulk;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Smoke tests for Risk bulk-export and bulk-assign endpoints.
 * Verifies: method guard, auth guard, happy path (CSV), cross-tenant isolation.
 */
class RiskBulkActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $userRole = null;
    private ?User $managerRole = null;
    private ?User $otherUser = null;
    private ?Tenant $otherTenant = null;
    private ?Risk $risk = null;
    private ?Risk $otherRisk = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $lock = static::getContainer()->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lock)) {
            @file_put_contents($lock, date('c'));
        }

        $uid = uniqid('bulk_risk_', true);

        $this->tenant = (new Tenant())->setName('Tenant ' . $uid)->setCode('tr_' . substr($uid, -8));
        $this->em->persist($this->tenant);

        $this->userRole = $this->makeUser('user_' . $uid . '@x.test', ['ROLE_USER'], $this->tenant);
        $this->managerRole = $this->makeUser('mgr_' . $uid . '@x.test', ['ROLE_MANAGER'], $this->tenant);

        $this->otherTenant = (new Tenant())->setName('Other ' . $uid)->setCode('ot_' . substr($uid, -8));
        $this->em->persist($this->otherTenant);
        $this->otherUser = $this->makeUser('other_' . $uid . '@x.test', ['ROLE_USER'], $this->otherTenant);

        $this->risk = $this->makeRisk('Risk A ' . $uid, $this->tenant);
        $this->otherRisk = $this->makeRisk('Risk B ' . $uid, $this->otherTenant);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ([$this->risk, $this->otherRisk] as $entity) {
            if ($entity) {
                try {
                    $e = $this->em->find(Risk::class, $entity->getId());
                    if ($e) { $this->em->remove($e); }
                } catch (\Exception) {}
            }
        }
        foreach ([$this->userRole, $this->managerRole, $this->otherUser] as $u) {
            if ($u) {
                try {
                    $e = $this->em->find(User::class, $u->getId());
                    if ($e) { $this->em->remove($e); }
                } catch (\Exception) {}
            }
        }
        foreach ([$this->tenant, $this->otherTenant] as $t) {
            if ($t) {
                try {
                    $e = $this->em->find(Tenant::class, $t->getId());
                    if ($e) { $this->em->remove($e); }
                } catch (\Exception) {}
            }
        }
        try { $this->em->flush(); } catch (\Exception) {}
        parent::tearDown();
    }

    // ── bulk-export ──────────────────────────────────────────────────────────

    #[Test]
    public function exportRejectsGet(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('GET', '/en/risk/bulk-export');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function exportRequiresAuth(): void
    {
        $this->client->request('POST', '/en/risk/bulk-export', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [$this->risk->getId()], '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseRedirects();
    }

    #[Test]
    public function exportReturnsCsvForOwnRisk(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/risk/bulk-export', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [$this->risk->getId()], '_token' => $this->getBulkCsrfToken()]));

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('text/csv', $this->client->getResponse()->headers->get('Content-Type') ?? '');
    }

    #[Test]
    public function exportSkipsCrossTenantRisk(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/risk/bulk-export', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [$this->otherRisk->getId()], '_token' => $this->getBulkCsrfToken()]));

        // No exportable items → 404 JSON
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ── CSRF enforcement (audit C-1 / OWASP A01) ────────────────────────────

    #[Test]
    public function exportRejectsMissingCsrfToken(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/risk/bulk-export', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [$this->risk->getId()]]));
        // Missing _token → 403 Forbidden
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function exportRejectsInvalidCsrfToken(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/risk/bulk-export', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [$this->risk->getId()], '_token' => 'invalid-token']));
        // Invalid _token → 403 Forbidden
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function assignRejectsMissingCsrfToken(): void
    {
        $this->client->loginUser($this->managerRole);
        $this->client->request('POST', '/en/risk/bulk-assign', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [$this->risk->getId()], 'assignee_id' => $this->managerRole->getId()]));
        // Missing _token → 403 Forbidden
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ── bulk-assign ──────────────────────────────────────────────────────────

    #[Test]
    public function assignRejectsGet(): void
    {
        $this->client->loginUser($this->managerRole);
        $this->client->request('GET', '/en/risk/bulk-assign');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function assignRequiresManagerRole(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/risk/bulk-assign', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [$this->risk->getId()], 'assignee_id' => $this->userRole->getId(), '_token' => $this->getBulkCsrfToken()]));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function assignUpdatesRiskOwner(): void
    {
        $this->client->loginUser($this->managerRole);
        $this->client->request('POST', '/en/risk/bulk-assign', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [$this->risk->getId()], 'assignee_id' => $this->managerRole->getId(), '_token' => $this->getBulkCsrfToken()]));

        $this->assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok'] ?? false);
        $this->assertSame(1, $body['changed'] ?? 0);
    }

    #[Test]
    public function assignSkipsCrossTenantRisk(): void
    {
        $this->client->loginUser($this->managerRole);
        $this->client->request('POST', '/en/risk/bulk-assign', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [$this->otherRisk->getId()], 'assignee_id' => $this->managerRole->getId(), '_token' => $this->getBulkCsrfToken()]));

        $this->assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(0, $body['changed'] ?? -1);
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

    private function makeRisk(string $title, Tenant $tenant): Risk
    {
        $r = new Risk();
        $r->setTitle($title)->setCategory('security')->setProbability(2)->setImpact(2)
          ->setDescription('Test risk description')->setStatus(RiskStatus::Identified)->setTenant($tenant);
        $this->em->persist($r);
        return $r;
    }
}
