<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Asset;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

/**
 * The CSP allows inline scripts only with the per-request nonce: script-src
 * has no 'unsafe-inline' (nor data:), and every executable inline <script>
 * of a rendered page — including the importmap — carries that nonce.
 * Turbo fetches reuse the nonce of the document they were issued from.
 */
final class ContentSecurityPolicyTest extends WebTestCase
{
    private KernelBrowser $client;
    private ?EntityManagerInterface $entityManager = null;
    private ?Tenant $tenant = null;
    private ?User $user = null;
    private ?Asset $asset = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager !== null && $this->entityManager->isOpen()) {
            foreach ([$this->asset, $this->user, $this->tenant] as $entity) {
                if ($entity !== null && $entity->getId() !== null) {
                    $managed = $this->entityManager->find($entity::class, $entity->getId());
                    if ($managed !== null) {
                        $this->entityManager->remove($managed);
                    }
                }
            }
            $this->entityManager->flush();
        }
        parent::tearDown();
    }

    #[Test]
    public function scriptSrcHasNoUnsafeInlineAndLoginPageScriptsCarryTheNonce(): void
    {
        $this->client->request('GET', '/de/login');
        self::assertResponseIsSuccessful();

        $nonce = $this->assertStrictScriptSrc();
        $this->assertInlineScriptsCarry($nonce);
    }

    #[Test]
    public function appLayoutInlineScriptsCarryTheNonce(): void
    {
        $this->client->loginUser($this->createUser());
        $this->client->request('GET', '/en/dashboard');
        self::assertResponseIsSuccessful();

        $nonce = $this->assertStrictScriptSrc();
        $this->assertInlineScriptsCarry($nonce);
    }

    #[Test]
    public function qrLabelPagesLoadTheLocalAuroraIconsInsteadOfACdn(): void
    {
        // Rendered directly: the controllers need GD for the QR image, which is
        // irrelevant for the stylesheet check.
        $this->createUser();
        $this->asset = new Asset();
        $this->asset->setName('CSP label asset');
        $this->asset->setAssetType('hardware');
        $this->asset->setOwner('Owner');
        $this->asset->setTenant($this->tenant);
        $this->asset->setConfidentialityValue(2);
        $this->asset->setIntegrityValue(2);
        $this->asset->setAvailabilityValue(2);
        $this->asset->setStatus('active');
        $this->entityManager?->persist($this->asset);
        $this->entityManager?->flush();

        $container = static::getContainer();
        $request = Request::create('/en/asset/' . $this->asset->getId() . '/qr-label');
        $request->setLocale('en');
        $container->get(RequestStack::class)->push($request);
        $container->get(RouterInterface::class)->getContext()->setParameter('_locale', 'en');
        $twig = $container->get(Environment::class);
        $label = ['asset' => $this->asset, 'qrCode' => 'data:image/png;base64,iVBORw0KGgo=', 'url' => 'https://example.test/a'];

        $pages = [
            'qr_label' => $twig->render('asset/qr_label.html.twig', ['label' => $label, 'printDate' => new \DateTimeImmutable()]),
            'qr_labels_sheet' => $twig->render('asset/qr_labels_sheet.html.twig', [
                'labels' => [$label], 'printDate' => new \DateTimeImmutable(), 'assetsPerPage' => 10,
            ]),
        ];
        foreach ($pages as $name => $html) {
            $crawler = new Crawler($html);
            self::assertStringNotContainsString('cdn.jsdelivr.net', $html, $name);
            self::assertGreaterThan(0, $crawler->filter('i.fa-icon')->count(), $name);
            $hrefs = $crawler->filter('link[rel="stylesheet"]')->each(static fn (Crawler $l) => (string) $l->attr('href'));
            self::assertNotEmpty(
                array_filter($hrefs, static fn (string $h) => str_contains($h, 'fairy-aurora-icons') && str_starts_with($h, '/')),
                $name . ': fa-icon needs the locally served Aurora icon stylesheet, got ' . implode(', ', $hrefs),
            );
        }
    }

    #[Test]
    public function eachFullPageLoadGetsAFreshNonce(): void
    {
        $this->client->request('GET', '/de/login');
        $first = $this->assertStrictScriptSrc();
        $this->client->request('GET', '/de/login');

        self::assertNotSame($first, $this->assertStrictScriptSrc());
    }

    #[Test]
    public function turboFetchReusesTheDocumentNonce(): void
    {
        $documentNonce = 'Abcdefghijklmnopqrstuvwx';
        $this->client->request('GET', '/de/login', server: [
            'HTTP_X_TURBO_REQUEST_ID' => 'f1b4c7e2-0000-4000-8000-000000000001',
            'HTTP_X_CSP_NONCE' => $documentNonce,
        ]);
        self::assertResponseIsSuccessful();

        self::assertSame($documentNonce, $this->assertStrictScriptSrc());
        $this->assertInlineScriptsCarry($documentNonce);
    }

    #[Test]
    public function nonceHeaderIsIgnoredOutsideTurboOrWhenMalformed(): void
    {
        $this->client->request('GET', '/de/login', server: ['HTTP_X_CSP_NONCE' => 'Abcdefghijklmnopqrstuvwx']);
        self::assertNotSame('Abcdefghijklmnopqrstuvwx', $this->assertStrictScriptSrc());

        $this->client->request('GET', '/de/login', server: [
            'HTTP_X_TURBO_REQUEST_ID' => 'f1b4c7e2-0000-4000-8000-000000000002',
            'HTTP_X_CSP_NONCE' => "x'; script-src *",
        ]);
        $nonce = $this->assertStrictScriptSrc();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/', $nonce);
    }

    /**
     * @return string the nonce from the script-src directive
     */
    private function assertStrictScriptSrc(): string
    {
        $csp = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');
        // Everything is served locally: a CDN host would let any package on it bypass the policy.
        self::assertStringNotContainsString('cdn.jsdelivr.net', $csp);
        self::assertMatchesRegularExpression('/(?:^|;)\s*script-src ([^;]+)/', $csp, 'CSP header with script-src expected');
        preg_match('/(?:^|;)\s*script-src ([^;]+)/', $csp, $m);
        $scriptSrc = $m[1];

        self::assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        self::assertStringNotContainsString("'unsafe-eval'", $scriptSrc);
        self::assertStringNotContainsString('data:', $scriptSrc);
        self::assertSame(1, preg_match("/'nonce-([A-Za-z0-9+\\/_=-]+)'/", $scriptSrc, $nonce), $scriptSrc);

        return $nonce[1];
    }

    private function assertInlineScriptsCarry(string $nonce): void
    {
        $crawler = $this->client->getCrawler();
        self::assertSame($nonce, $crawler->filter('meta[name="csp-nonce"]')->attr('content'));

        $inline = 0;
        foreach ($crawler->filter('script') as $script) {
            \assert($script instanceof \DOMElement);
            $type = strtolower(trim($script->getAttribute('type')));
            if ($script->hasAttribute('src') || !\in_array($type, ['', 'text/javascript', 'module', 'importmap'], true)) {
                continue;
            }
            ++$inline;
            self::assertSame(
                $nonce,
                $script->getAttribute('nonce'),
                'inline script without the CSP nonce: ' . substr(trim($script->textContent), 0, 80),
            );
        }
        self::assertGreaterThan(0, $inline, 'page is expected to contain inline scripts (theme init, importmap)');
    }

    private function createUser(): User
    {
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $id = uniqid('csp_', true);

        $this->tenant = (new Tenant())->setName('CSP ' . $id)->setCode('csp_' . str_replace('.', '', $id));
        $this->entityManager->persist($this->tenant);

        $this->user = new User();
        $this->user->setEmail($id . '@example.com');
        $this->user->setFirstName('Csp');
        $this->user->setLastName('Test');
        $this->user->setRoles(['ROLE_ADMIN']);
        $this->user->setPassword('hashed_password');
        $this->user->setTenant($this->tenant);
        $this->user->setIsActive(true);
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();

        return $this->user;
    }
}
