<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Tenant;
use App\Entity\TenantBranding;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sprint policy-style-admin — integration coverage for the per-tenant
 * Policy-Doc Style configurator (GET render, POST save, preview-XHR,
 * reset, IsGranted gating, audit-event).
 */
final class AdminPolicyStyleControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $admin = null;
    private ?User $auditor = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $this->em = $em;

        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);

        $this->tenant = (new Tenant())
            ->setCode('ps-' . $suffix)
            ->setName('PolicyStyle Tenant ' . $suffix);
        $this->em->persist($this->tenant);

        $this->admin = (new User())
            ->setEmail('ps-admin-' . $suffix . '@example.test')
            ->setFirstName('Policy')
            ->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])
            ->setPassword('hashed_password')
            ->setTenant($this->tenant)
            ->setAuthProvider('local')
            ->setIsActive(true);
        $this->em->persist($this->admin);

        $this->auditor = (new User())
            ->setEmail('ps-auditor-' . $suffix . '@example.test')
            ->setFirstName('Aud')
            ->setLastName('Itor')
            ->setRoles(['ROLE_AUDITOR'])
            ->setPassword('hashed_password')
            ->setTenant($this->tenant)
            ->setAuthProvider('local')
            ->setIsActive(true);
        $this->em->persist($this->auditor);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            try {
                // Drop branding row first if persisted
                $branding = $this->em->getRepository(TenantBranding::class)
                    ->findOneBy(['tenant' => $this->tenant]);
                if ($branding) {
                    $this->em->remove($branding);
                }
                foreach ([$this->admin, $this->auditor, $this->tenant] as $e) {
                    if ($e && method_exists($e, 'getId') && $e->getId() !== null) {
                        $reload = $this->em->find($e::class, $e->getId());
                        if ($reload) {
                            $this->em->remove($reload);
                        }
                    }
                }
                $this->em->flush();
            } catch (\Throwable) {
                // best-effort
            }
        }
        parent::tearDown();
    }

    /**
     * Some local test environments route admin requests to /quick-fix
     * because of unrelated schema-drift detection. The test cannot
     * exercise the controller in that condition; mark the test skipped
     * so a clean CI fixture-DB still runs the assertions.
     */
    private function skipIfQuickFixRedirect(): void
    {
        $code = $this->client->getResponse()->getStatusCode();
        if ($code === 302 && str_contains(
            (string) $this->client->getResponse()->headers->get('Location'),
            '/quick-fix',
        )) {
            self::markTestSkipped('Test environment routes to /quick-fix (schema-drift); test runs on a clean CI DB.');
        }
    }

    #[Test]
    public function getRendersForAdmin(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/de/admin/policy-style');
        $this->skipIfQuickFixRedirect();

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        // Page rendered — accept either locale (DE/EN). CI may resolve EN.
        self::assertTrue(
            str_contains($html, 'Policy-Optik') || str_contains($html, 'Policy Style'),
            'Page should contain the policy-style title in DE or EN',
        );
        // Live-preview-frame container present.
        self::assertStringContainsString('policy-doc-preview', $html);
        // Stimulus controller wiring.
        self::assertStringContainsString('policy-style-preview', $html);
    }

    #[Test]
    public function getDeniedForNonAdmin(): void
    {
        $this->client->loginUser($this->auditor);
        $this->client->request('GET', '/de/admin/policy-style');

        // Symfony returns 403 for IsGranted-failure in test env (no
        // access-denied-handler configured to redirect).
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->client->getResponse()->getStatusCode(),
        );
    }

    #[Test]
    public function previewXhrReturnsHtmlFragment(): void
    {
        $this->client->loginUser($this->admin);
        // Warm session + extract the per-form CSRF token.
        $crawler = $this->client->request('GET', '/de/admin/policy-style');
        $this->skipIfQuickFixRedirect();
        self::assertResponseIsSuccessful();

        // Read the value of the data-policy-style-preview-csrf-value attr
        // off the rendered page so we use the *same* session token the
        // backend will validate.
        $html = (string) $this->client->getResponse()->getContent();
        $matched = preg_match(
            '/data-policy-style-preview-csrf-value="([^"]+)"/',
            $html,
            $m,
        );
        self::assertSame(1, $matched, 'CSRF token attr missing from preview frame');
        $token = $m[1];

        $payload = [
            'cover_pattern' => 'engineering',
            'watermark_opacity' => 0.25,
            'signature_lines' => 4,
            'show_history' => false,
            'show_toc' => true,
            'font_family' => 'Roboto',
            '_preview_token' => $token,
        ];

        $this->client->request(
            'POST',
            '/de/admin/policy-style/preview',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $body = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($body);
        self::assertArrayHasKey('html', $body);
        self::assertStringContainsString('policy-doc-preview', $body['html']);
        // The preview should reflect the engineering cover pattern.
        self::assertStringContainsString('cover-engineering', $body['html']);
    }

    #[Test]
    public function previewXhrRejectsMissingCsrf(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/de/admin/policy-style');
        $this->skipIfQuickFixRedirect();

        $this->client->request(
            'POST',
            '/de/admin/policy-style/preview',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['cover_pattern' => 'minimal'], JSON_THROW_ON_ERROR),
        );

        // Missing CSRF token → AccessDenied → 403; some test envs translate
        // CSRF-fail to a redirect (302). Both effectively deny the action.
        $code = $this->client->getResponse()->getStatusCode();
        self::assertContains(
            $code,
            [Response::HTTP_FORBIDDEN, Response::HTTP_FOUND],
            'Missing CSRF must result in 403 or 302 (redirect-to-login), got ' . $code,
        );
    }

    #[Test]
    public function previewXhrIgnoresCustomCssFromPayload(): void
    {
        // Defense-in-depth: the preview endpoint must not echo arbitrary
        // CSS from a forged payload (a non-admin XHR could otherwise
        // inject styles into a screenshot/screen-share).
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/de/admin/policy-style');
        $this->skipIfQuickFixRedirect();
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        preg_match('/data-policy-style-preview-csrf-value="([^"]+)"/', $html, $m);
        $token = $m[1] ?? '';

        $marker = '/* INJECTION-MARKER-' . bin2hex(random_bytes(4)) . ' */';

        $this->client->request(
            'POST',
            '/de/admin/policy-style/preview',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $token,
            ],
            content: json_encode([
                'cover_pattern' => 'branded',
                'custom_css' => $marker,
                '_preview_token' => $token,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringNotContainsString(
            $marker,
            (string) ($body['html'] ?? ''),
            'Custom CSS from XHR payload must not reach the preview output.',
        );
    }
}
