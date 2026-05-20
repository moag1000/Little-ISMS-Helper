<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Comment;
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
 * Functional tests for CommentController.
 *
 * Audit V4 LB-9 (2026-05-10): tenant-isolation regression coverage.
 *
 * Verifies:
 * - Posts on non-existent entities return 404 (no rogue Comment row).
 * - Cross-tenant posts are blocked (404) — TenantFilter masks foreign rows.
 * - Same-tenant posts succeed and persist with the active tenant.
 */
class CommentControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    private ?Tenant $tenantA = null;
    private ?Tenant $tenantB = null;
    private ?User $userA = null;
    private ?Risk $riskA = null;
    private ?Risk $riskB = null;

    /** Track Comment rows we created so tearDown stays scoped. */
    private array $createdCommentIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $lockFile = $container->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lockFile)) {
            @file_put_contents($lockFile, date('c'));
        }

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        // Manually scoped cleanup — the test DB is shared across the suite,
        // so we MUST NOT truncate.
        try {
            $commentRepo = $this->entityManager->getRepository(Comment::class);
            foreach ($this->createdCommentIds as $id) {
                $row = $commentRepo->find($id);
                if ($row) {
                    $this->entityManager->remove($row);
                }
            }

            // Drop any stray comments by entity_type+entity_id from this test
            foreach ([$this->riskA, $this->riskB] as $risk) {
                if (!$risk instanceof Risk || $risk->getId() === null) {
                    continue;
                }
                $rows = $commentRepo->findBy(['entityType' => 'Risk', 'entityId' => $risk->getId()]);
                foreach ($rows as $row) {
                    $this->entityManager->remove($row);
                }
            }

            foreach ([$this->riskA, $this->riskB] as $risk) {
                if ($risk instanceof Risk && $risk->getId() !== null) {
                    $managed = $this->entityManager->find(Risk::class, $risk->getId());
                    if ($managed) {
                        $this->entityManager->remove($managed);
                    }
                }
            }

            if ($this->userA instanceof User && $this->userA->getId() !== null) {
                $managed = $this->entityManager->find(User::class, $this->userA->getId());
                if ($managed) {
                    $this->entityManager->remove($managed);
                }
            }

            foreach ([$this->tenantA, $this->tenantB] as $tenant) {
                if ($tenant instanceof Tenant && $tenant->getId() !== null) {
                    $managed = $this->entityManager->find(Tenant::class, $tenant->getId());
                    if ($managed) {
                        $this->entityManager->remove($managed);
                    }
                }
            }

            $this->entityManager->flush();
        } catch (\Throwable) {
            // Best-effort cleanup — don't mask the real test failure.
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uid = uniqid('lb9_', true);

        $this->tenantA = new Tenant();
        $this->tenantA->setName('Tenant A ' . $uid);
        $this->tenantA->setCode('tenant_a_' . $uid);
        $this->entityManager->persist($this->tenantA);

        $this->tenantB = new Tenant();
        $this->tenantB->setName('Tenant B ' . $uid);
        $this->tenantB->setCode('tenant_b_' . $uid);
        $this->entityManager->persist($this->tenantB);

        $this->userA = new User();
        $this->userA->setEmail('user_a_' . $uid . '@example.com');
        $this->userA->setFirstName('User');
        $this->userA->setLastName('A');
        $this->userA->setRoles(['ROLE_USER']);
        $this->userA->setPassword('hashed_password');
        $this->userA->setTenant($this->tenantA);
        $this->userA->setIsActive(true);
        $this->entityManager->persist($this->userA);

        $this->riskA = $this->buildRisk('Risk in tenant A ' . $uid, $this->tenantA);
        $this->entityManager->persist($this->riskA);

        $this->riskB = $this->buildRisk('Risk in tenant B ' . $uid, $this->tenantB);
        $this->entityManager->persist($this->riskB);

        $this->entityManager->flush();
    }

    private function buildRisk(string $title, Tenant $tenant): Risk
    {
        $risk = new Risk();
        $risk->setTenant($tenant);
        $risk->setTitle($title);
        $risk->setCategory('operational');
        $risk->setDescription('Cross-tenant comment regression fixture.');
        $risk->setProbability(2);
        $risk->setImpact(2);
        $risk->setStatus(RiskStatus::Identified);
        $risk->setCreatedAt(new \DateTimeImmutable());
        return $risk;
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
        if (!$this->entityManager->contains($user)) {
            $user = $this->entityManager->merge($user);
        }
        $this->entityManager->refresh($user);
    }

    /**
     * CSRF-Rule from CLAUDE.md: bootstrap session via GET, then write the
     * token in the SAME session and save+close before the POST request.
     */
    private function generateIsmsCommentToken(): string
    {
        // GET on a tenant-scoped page primes the session cookie.
        $this->client->request('GET', '/en/risk');
        $session = $this->client->getRequest()->getSession();

        $generator = new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator();
        $token = $generator->generateToken();
        $session->set('_csrf/isms-comment', $token);
        $session->save();

        return $token;
    }

    private function countCommentsFor(string $entityType, ?int $entityId): int
    {
        if ($entityId === null) {
            return 0;
        }
        return (int) $this->entityManager->getRepository(Comment::class)
            ->count(['entityType' => $entityType, 'entityId' => $entityId]);
    }

    // ========== V4 LB-9 — Cross-Tenant + Existence Checks ==========

    #[Test]
    public function testSubmitOnNonExistentEntityReturns404(): void
    {
        $this->loginAsUser($this->userA);
        $token = $this->generateIsmsCommentToken();

        $before = $this->countCommentsFor('Risk', 999999);

        $this->client->request('POST', '/en/comment/Risk/999999', [
            '_token' => $token,
            'body' => 'should never persist',
        ]);

        $this->assertSame(
            Response::HTTP_NOT_FOUND,
            $this->client->getResponse()->getStatusCode(),
            'Posting on a non-existent entity must yield 404.'
        );

        $this->assertSame(
            $before,
            $this->countCommentsFor('Risk', 999999),
            'No Comment row may exist for a non-existent target.'
        );
    }

    #[Test]
    public function testSubmitOnCrossTenantEntityIsBlocked(): void
    {
        $this->loginAsUser($this->userA);
        $token = $this->generateIsmsCommentToken();

        $foreignId = $this->riskB->getId();
        $this->assertNotNull($foreignId);

        $before = $this->countCommentsFor('Risk', $foreignId);

        $this->client->request('POST', '/en/comment/Risk/' . $foreignId, [
            '_token' => $token,
            'body' => 'cross-tenant attempt',
        ]);

        $status = $this->client->getResponse()->getStatusCode();
        $this->assertContains(
            $status,
            [Response::HTTP_NOT_FOUND, Response::HTTP_FORBIDDEN],
            'Cross-tenant comment must be denied (404 from filter, or 403 from defense-in-depth check).'
        );

        $this->assertSame(
            $before,
            $this->countCommentsFor('Risk', $foreignId),
            'No Comment row may be persisted against a cross-tenant target.'
        );
    }

    #[Test]
    public function testSubmitOnUnknownEntityTypeReturns404(): void
    {
        $this->loginAsUser($this->userA);
        $token = $this->generateIsmsCommentToken();

        // Not in the whitelist → must short-circuit to 404 before any DB load.
        $this->client->request('POST', '/en/comment/Tenant/1', [
            '_token' => $token,
            'body' => 'forbidden type',
        ]);

        $this->assertSame(
            Response::HTTP_NOT_FOUND,
            $this->client->getResponse()->getStatusCode(),
            'Non-whitelisted entity types must yield 404.'
        );
    }

    #[Test]
    public function testSubmitOnOwnTenantEntityPersists(): void
    {
        $this->loginAsUser($this->userA);
        $token = $this->generateIsmsCommentToken();

        $ownId = $this->riskA->getId();
        $this->assertNotNull($ownId);

        $before = $this->countCommentsFor('Risk', $ownId);

        $this->client->request('POST', '/en/comment/Risk/' . $ownId, [
            '_token' => $token,
            'body' => 'own-tenant comment LB-9',
        ]);

        // Controller redirects on success (referer or '/').
        $this->assertResponseRedirects();

        $this->entityManager->clear();
        $after = $this->countCommentsFor('Risk', $ownId);
        $this->assertSame(
            $before + 1,
            $after,
            'Own-tenant comment must persist exactly one Comment row.'
        );

        // Track the new row for tearDown cleanup.
        $newest = $this->entityManager->getRepository(Comment::class)->findOneBy(
            ['entityType' => 'Risk', 'entityId' => $ownId],
            ['id' => 'DESC']
        );
        if ($newest) {
            $this->createdCommentIds[] = $newest->getId();
            $this->assertSame(
                $this->tenantA->getId(),
                $newest->getTenant()?->getId(),
                'Persisted comment must carry the active user-tenant.'
            );
        }
    }
}
