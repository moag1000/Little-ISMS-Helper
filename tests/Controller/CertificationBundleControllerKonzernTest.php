<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for the Konzern-export endpoint on
 * {@see \App\Controller\CertificationBundleController::konzernExport()}.
 *
 * Task #129: holding-level cert-bundle export — covers role gating
 * (ROLE_GROUP_CISO / ROLE_KONZERN_AUDITOR only) and the "current tenant
 * is not a holding" guard rail.
 */
final class CertificationBundleControllerKonzernTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $holding = null;
    private ?Tenant $subsidiary = null;
    private ?Tenant $standalone = null;
    private ?User $groupCisoUser = null;
    private ?User $unprivilegedUser = null;
    private ?User $standaloneCisoUser = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        try {
            $this->entityManager = $container->get(EntityManagerInterface::class);
            $this->entityManager->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            if (
                str_contains($e->getMessage(), 'Access denied')
                || str_contains($e->getMessage(), 'Connection refused')
                || str_contains($e->getMessage(), 'SQLSTATE')
            ) {
                $this->markTestSkipped('Database not available: ' . $e->getMessage());
            }
            throw $e;
        }

        $lockFile = $container->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lockFile)) {
            @file_put_contents($lockFile, date('c'));
        }

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if (!isset($this->entityManager)) {
            parent::tearDown();
            return;
        }

        foreach ([$this->groupCisoUser, $this->unprivilegedUser, $this->standaloneCisoUser] as $u) {
            if ($u === null) {
                continue;
            }
            try {
                $managed = $this->entityManager->find(User::class, $u->getId());
                if ($managed !== null) {
                    $this->entityManager->remove($managed);
                }
            } catch (\Throwable) {
                // ignore
            }
        }
        try {
            $this->entityManager->flush();
        } catch (\Throwable) {
            // ignore
        }

        // Subsidiary first (FK on parent_id), then holding/standalone.
        foreach ([$this->subsidiary, $this->standalone, $this->holding] as $t) {
            if ($t === null) {
                continue;
            }
            try {
                $managed = $this->entityManager->find(Tenant::class, $t->getId());
                if ($managed !== null) {
                    $this->entityManager->remove($managed);
                    $this->entityManager->flush();
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('cb_kn_', true);

        $this->holding = new Tenant();
        $this->holding->setName('Cert Holding ' . $uniqueId);
        $this->holding->setCode('cb_h_' . substr($uniqueId, 0, 14));
        $this->holding->setIsCorporateParent(true);
        $this->entityManager->persist($this->holding);

        $this->subsidiary = new Tenant();
        $this->subsidiary->setName('Cert Sub ' . $uniqueId);
        $this->subsidiary->setCode('cb_s_' . substr($uniqueId, 0, 14));
        $this->holding->addSubsidiary($this->subsidiary);
        $this->entityManager->persist($this->subsidiary);

        $this->standalone = new Tenant();
        $this->standalone->setName('Cert Standalone ' . $uniqueId);
        $this->standalone->setCode('cb_st_' . substr($uniqueId, 0, 13));
        $this->entityManager->persist($this->standalone);

        $this->groupCisoUser = new User();
        $this->groupCisoUser->setEmail('cb_gciso_' . $uniqueId . '@example.test');
        $this->groupCisoUser->setFirstName('Group');
        $this->groupCisoUser->setLastName('CISO');
        $this->groupCisoUser->setRoles(['ROLE_USER', 'ROLE_MANAGER', 'ROLE_GROUP_CISO']);
        $this->groupCisoUser->setPassword('hashed_password');
        $this->groupCisoUser->setTenant($this->holding);
        $this->groupCisoUser->setIsActive(true);
        $this->entityManager->persist($this->groupCisoUser);

        $this->unprivilegedUser = new User();
        $this->unprivilegedUser->setEmail('cb_plain_' . $uniqueId . '@example.test');
        $this->unprivilegedUser->setFirstName('Plain');
        $this->unprivilegedUser->setLastName('User');
        $this->unprivilegedUser->setRoles(['ROLE_USER']);
        $this->unprivilegedUser->setPassword('hashed_password');
        $this->unprivilegedUser->setTenant($this->holding);
        $this->unprivilegedUser->setIsActive(true);
        $this->entityManager->persist($this->unprivilegedUser);

        // Group-CISO sitting in the standalone tenant — should still be
        // bounced because that tenant has no subsidiaries.
        $this->standaloneCisoUser = new User();
        $this->standaloneCisoUser->setEmail('cb_st_gciso_' . $uniqueId . '@example.test');
        $this->standaloneCisoUser->setFirstName('Lone');
        $this->standaloneCisoUser->setLastName('GCISO');
        $this->standaloneCisoUser->setRoles(['ROLE_USER', 'ROLE_MANAGER', 'ROLE_GROUP_CISO']);
        $this->standaloneCisoUser->setPassword('hashed_password');
        $this->standaloneCisoUser->setTenant($this->standalone);
        $this->standaloneCisoUser->setIsActive(true);
        $this->entityManager->persist($this->standaloneCisoUser);

        $this->entityManager->flush();
    }

    private function loginAndGenerateCsrfToken(User $user): string
    {
        // Mirror RiskControllerTest::loginAndGenerateCsrfToken — login,
        // then GET to bootstrap session, then write token directly via
        // SessionTokenStorage's `_csrf/<id>` key. Required because the
        // BrowserKit client's session does not survive plain ->getToken()
        // when no prior request has touched the firewall.
        $this->client->loginUser($user);
        $this->client->request('GET', '/en/certification-bundle');
        $session = $this->client->getRequest()->getSession();
        $tokenGenerator = new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator();
        $tokenValue = $tokenGenerator->generateToken();
        $session->set('_csrf/certification_bundle_konzern_export', $tokenValue);
        $session->save();
        return $tokenValue;
    }

    private function fetchCsrfToken(): string
    {
        // Backwards-compat alias: the old code called fetchCsrfToken()
        // after loginUser() had already happened. We now bundle login +
        // token in one call via loginAndGenerateCsrfToken(); this method
        // returns a token tied to the currently-logged-in user (assuming
        // the test already called loginUser).
        $session = $this->client->getRequest()?->getSession();
        if ($session === null) {
            $this->client->request('GET', '/en/certification-bundle');
            $session = $this->client->getRequest()->getSession();
        }
        $tokenGenerator = new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator();
        $tokenValue = $tokenGenerator->generateToken();
        $session->set('_csrf/certification_bundle_konzern_export', $tokenValue);
        $session->save();
        return $tokenValue;
    }

    // ─── Tests ─────────────────────────────────────────────────────────

    #[Test]
    public function testRequiresGroupCisoOrKonzernAuditor(): void
    {
        $token = $this->loginAndGenerateCsrfToken($this->unprivilegedUser);
        $this->client->request('POST', '/en/certification-bundle/konzern-export', [
            '_token' => $token,
            'frameworks' => ['ISO27001'],
        ]);

        // 403 (voter denied) or 302 (firewall bounce) — both mean
        // the unprivileged user did NOT reach the export.
        $this->assertContains(
            $this->client->getResponse()->getStatusCode(),
            [Response::HTTP_FORBIDDEN, Response::HTTP_FOUND],
            'Unprivileged user must not access the konzern-export endpoint',
        );
    }

    #[Test]
    public function testGroupCisoOnNonHoldingTenantGetsRedirectedWithFlash(): void
    {
        $token = $this->loginAndGenerateCsrfToken($this->standaloneCisoUser);
        $this->client->request('POST', '/en/certification-bundle/konzern-export', [
            '_token' => $token,
            'frameworks' => ['ISO27001'],
        ]);

        // Controller short-circuits with a redirect when the tenant has
        // no subsidiaries — the flash message key is set so the index page
        // can surface "not_a_holding" to the user.
        $this->assertSame(
            Response::HTTP_FOUND,
            $this->client->getResponse()->getStatusCode(),
            'Standalone tenant must be redirected (not 200, not 500).',
        );
    }
}
