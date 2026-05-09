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
 * Functional tests for {@see \App\Controller\KonzernRollupController}.
 *
 * Covers role gating, Konzern auto-detection (root with subsidiaries),
 * and graceful empty-state rendering for non-Konzern tenants.
 *
 * Phase 4-C / Sprint W7-B.
 */
final class KonzernRollupControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $konzernRoot = null;
    private ?Tenant $tochter = null;
    private ?Tenant $standalone = null;
    private ?User $groupCisoUser = null;
    private ?User $standaloneUser = null;
    private ?User $unprivilegedUser = null;

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

        // Tear down users first (FK), then tenants.
        foreach ([$this->groupCisoUser, $this->standaloneUser, $this->unprivilegedUser] as $u) {
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

        // Tochter tenants must be removed before their Konzern parent
        // because of the FK on tenant.parent_id.
        foreach ([$this->tochter, $this->standalone, $this->konzernRoot] as $t) {
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
        $uniqueId = uniqid('kr_', true);

        $this->konzernRoot = new Tenant();
        $this->konzernRoot->setName('Roll-Up Holding ' . $uniqueId);
        $this->konzernRoot->setCode('kr_root_' . substr($uniqueId, 0, 12));
        $this->konzernRoot->setIsCorporateParent(true);
        $this->entityManager->persist($this->konzernRoot);

        $this->tochter = new Tenant();
        $this->tochter->setName('Roll-Up Tochter ' . $uniqueId);
        $this->tochter->setCode('kr_sub_' . substr($uniqueId, 0, 13));
        // addSubsidiary() also wires the inverse setParent(), and is the
        // only path that puts the tochter into the in-memory collection
        // so getSubsidiaries()->count() works inside the same request
        // without re-loading from the DB.
        $this->konzernRoot->addSubsidiary($this->tochter);
        $this->entityManager->persist($this->tochter);

        $this->standalone = new Tenant();
        $this->standalone->setName('Standalone ' . $uniqueId);
        $this->standalone->setCode('kr_st_' . substr($uniqueId, 0, 14));
        $this->entityManager->persist($this->standalone);

        $this->groupCisoUser = new User();
        $this->groupCisoUser->setEmail('gciso_' . $uniqueId . '@example.test');
        $this->groupCisoUser->setFirstName('Group');
        $this->groupCisoUser->setLastName('CISO');
        $this->groupCisoUser->setRoles(['ROLE_USER', 'ROLE_GROUP_CISO']);
        $this->groupCisoUser->setPassword('hashed_password');
        $this->groupCisoUser->setTenant($this->konzernRoot);
        $this->groupCisoUser->setIsActive(true);
        $this->entityManager->persist($this->groupCisoUser);

        $this->standaloneUser = new User();
        $this->standaloneUser->setEmail('stnd_' . $uniqueId . '@example.test');
        $this->standaloneUser->setFirstName('Stand');
        $this->standaloneUser->setLastName('Alone');
        $this->standaloneUser->setRoles(['ROLE_USER', 'ROLE_GROUP_CISO']);
        $this->standaloneUser->setPassword('hashed_password');
        $this->standaloneUser->setTenant($this->standalone);
        $this->standaloneUser->setIsActive(true);
        $this->entityManager->persist($this->standaloneUser);

        $this->unprivilegedUser = new User();
        $this->unprivilegedUser->setEmail('uplain_' . $uniqueId . '@example.test');
        $this->unprivilegedUser->setFirstName('Plain');
        $this->unprivilegedUser->setLastName('User');
        $this->unprivilegedUser->setRoles(['ROLE_USER']);
        $this->unprivilegedUser->setPassword('hashed_password');
        $this->unprivilegedUser->setTenant($this->konzernRoot);
        $this->unprivilegedUser->setIsActive(true);
        $this->entityManager->persist($this->unprivilegedUser);

        $this->entityManager->flush();
    }

    // ========== Tests ==========

    #[Test]
    public function testRequiresKonzernRole(): void
    {
        // ROLE_USER without ROLE_GROUP_CISO must be denied.
        $this->client->loginUser($this->unprivilegedUser);
        $this->client->request('GET', '/en/policy-wizard/konzern-rollup');
        // 403 (voter denied) — note the controller may also redirect on
        // missing tenant but the unprivileged user has one, so 403 is
        // the expected outcome. Accept 403 or 302 (login bounce in some
        // firewall configs).
        $this->assertContains(
            $this->client->getResponse()->getStatusCode(),
            [Response::HTTP_FORBIDDEN, Response::HTTP_FOUND],
            'Unprivileged user should be denied or bounced',
        );
    }

    #[Test]
    public function testRendersForKonzernRoot(): void
    {
        $this->client->loginUser($this->groupCisoUser);
        $this->client->request('GET', '/en/policy-wizard/konzern-rollup');

        $this->assertResponseIsSuccessful();
        // The dashboard page renders the page-header with the title key.
        $this->assertSelectorTextContains('h1, .fa-page-header__title', 'Roll-Up');
    }

    #[Test]
    public function testRedirectsForNonKonzernTenant(): void
    {
        // Standalone tenant has no subsidiaries → controller renders the
        // empty-state ("not_a_konzern") instead of a hard 403, but the
        // voter is never invoked because the controller short-circuits.
        $this->client->loginUser($this->standaloneUser);
        $this->client->request('GET', '/en/policy-wizard/konzern-rollup');

        $this->assertResponseIsSuccessful();
        // The empty-state body carries the `policy_wizard.konzern_rollup.empty.not_konzern`
        // string. Accept either the German or English wording so the test
        // is locale-agnostic.
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertTrue(
            str_contains($body, 'not a holding entity')
            || str_contains($body, 'keine Holding')
            || str_contains($body, 'No subsidiaries')
            || str_contains($body, 'no subsidiaries'),
            'empty-state for non-Konzern tenant should render explanatory text',
        );
    }
}
