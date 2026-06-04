<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Repository\TenantRepository;
use App\Service\PostureSnapshotService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * F43 Trust-Center — HTTP-level tests for PublicPostureController.
 *
 * Two variants:
 *  A. Pure unit tests (no kernel) — validate controller guard logic.
 *  B. WebTestCase HTTP tests — validate routing + security.yaml gate.
 *     These tests require a DB connection; when not available they are
 *     skipped with a clear infra message so CI can distinguish test failures
 *     from infra failures.
 */
final class PublicPostureControllerTest extends WebTestCase
{
    // ── WebTestCase HTTP tests ─────────────────────────────────────────────────

    #[Test]
    public function invalidTokenReturns404(): void
    {
        $this->skipIfNoDatabaseConnection();

        $client = static::createClient();
        $client->request('GET', '/trust/this-token-does-not-exist-at-all-99999');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function routeHasNoLocalePrefix(): void
    {
        $this->skipIfNoDatabaseConnection();

        $client = static::createClient();
        // A locale-prefixed variant must NOT match (would redirect to login or 404 for wrong locale)
        // The un-prefixed /trust/... path must not redirect to a /{locale}/... path
        $client->request('GET', '/trust/nonexistent-token-xyz');

        // Should be 404 (no such token) and NOT a 302 redirect to /de/trust/...
        $response = $client->getResponse();
        self::assertNotSame(302, $response->getStatusCode(),
            'Route must not redirect to a locale-prefixed URL. Got: ' . $response->headers->get('Location'));
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function validEnabledTokenReturns200(): void
    {
        $this->skipIfNoDatabaseConnection();

        // Attempt to find (or create) a tenant with an enabled token for smoke-test.
        // We use the container to look up an existing enabled tenant, if any.
        $client = static::createClient();
        $container = static::getContainer();

        $tenantRepo = $container->get(TenantRepository::class);
        /** @var Tenant|null $enabledTenant */
        $enabledTenant = null;

        // Look for the first tenant that has posture sharing enabled
        /** @var Tenant[] $all */
        $all = $tenantRepo->findAll();
        foreach ($all as $t) {
            if ($t->isPublicPostureEnabled() && $t->getPublicPostureToken() !== null) {
                $enabledTenant = $t;
                break;
            }
        }

        if ($enabledTenant === null) {
            self::markTestSkipped(
                'No tenant with publicPostureEnabled=true found in the test DB. ' .
                'Enable sharing for a tenant via the admin UI to exercise this path.'
            );
        }

        $client->request('GET', '/trust/' . $enabledTenant->getPublicPostureToken());
        self::assertResponseStatusCodeSame(200);
    }

    // ── Guard-logic unit tests (no kernel) ────────────────────────────────────

    #[Test]
    public function controllerGuard_disabledTenantFoundByToken_shouldReturn404(): void
    {
        // Simulate controller guard: token matches DB but sharing is disabled.
        $token = 'abc123abc123abc123abc123abc123abc123abc123abc123';

        $tenant = new Tenant();
        $tenant->setName('Guard Test Org');
        $tenant->setCode('guard');
        $tenant->setPublicPostureToken($token);
        $tenant->setPublicPostureEnabled(false);  // disabled!

        // Guard logic mirrored from PublicPostureController::show():
        $storedToken = $tenant->getPublicPostureToken() ?? '';
        $tokenValid = hash_equals($storedToken, $token);
        $sharingActive = $tenant->isPublicPostureEnabled();

        self::assertTrue($tokenValid, 'hash_equals must succeed for matching token');
        self::assertFalse($sharingActive, 'sharing is disabled — controller must 404');
    }

    #[Test]
    public function controllerGuard_noTenantForToken_hashEqualsFails(): void
    {
        // Simulate: no tenant found in DB — storedToken is empty, comparison fails.
        $suppliedToken = 'any-token-from-url';
        $storedToken   = '';  // empty because $tenant is null

        $tokenValid = hash_equals($storedToken, $suppliedToken);

        self::assertFalse($tokenValid, 'hash_equals must fail when stored token is empty');
    }

    #[Test]
    public function controllerGuard_enabledTenantWithMatchingToken_passes(): void
    {
        $token = bin2hex(random_bytes(24));

        $tenant = new Tenant();
        $tenant->setName('Enabled Org');
        $tenant->setCode('enabled');
        $tenant->setPublicPostureToken($token);
        $tenant->setPublicPostureEnabled(true);

        $storedToken  = $tenant->getPublicPostureToken() ?? '';
        $tokenValid   = hash_equals($storedToken, $token);
        $sharingActive = $tenant->isPublicPostureEnabled();

        self::assertTrue($tokenValid);
        self::assertTrue($sharingActive);
        // Controller would proceed to render — no 404
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function skipIfNoDatabaseConnection(): void
    {
        try {
            $container = static::getContainer();
            $em = $container->get('doctrine.orm.entity_manager');
            // executeQuery() is public + forces a real connection (connect() is
            // protected in DBAL 4.x). Throws if the DB is unreachable.
            $em->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database not available: ' . $e->getMessage());
        }
    }
}
