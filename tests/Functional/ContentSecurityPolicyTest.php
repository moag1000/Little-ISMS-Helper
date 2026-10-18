<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager !== null && $this->entityManager->isOpen()) {
            foreach ([$this->user, $this->tenant] as $entity) {
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
