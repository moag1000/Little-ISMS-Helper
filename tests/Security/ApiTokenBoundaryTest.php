<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ApiToken;
use App\Entity\Role;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Security\Token\ApiTokenRequest;
use App\Service\ApiTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * S1 (wave 5): an API token is only a token. It counts on /api and /mcp and
 * nowhere else, never opens a browser session, and a deactivated person gets
 * in nowhere — neither with a token nor with a session nor with a new login.
 * Before the fix the token worked on every page (a read-only token could save
 * web forms), each token request left a session cookie that worked without
 * the token, and no login path checked whether the account was active.
 */
final class ApiTokenBoundaryTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $user;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $id = uniqid('tok_', true);
        $this->tenant = (new Tenant())->setName('Token Org ' . $id)->setCode('tok_' . str_replace('.', '', $id));
        $this->em->persist($this->tenant);
        $this->user = (new User())
            ->setEmail($id . '@example.test')
            ->setFirstName('Toni')
            ->setLastName('Token')
            ->setRoles(['ROLE_ISB'])
            ->setPassword('hashed')
            ->setTenant($this->tenant)
            ->setIsActive(true);
        $this->em->persist($this->user);
        $this->em->flush();

        $this->token = static::getContainer()->get(ApiTokenManager::class)->mint($this->user, 'Boundary');
    }

    #[Test]
    public function aTokenWorksOnTheApi(): void
    {
        $this->bearer('GET', '/api');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function aTokenOpensNoPageOfTheWebUi(): void
    {
        foreach (['/de/dashboard', '/en/risk', '/de/admin/users', '/de/profile/api-tokens'] as $path) {
            $this->bearer('GET', $path);
            self::assertResponseStatusCodeSame(401, $path);
        }
    }

    #[Test]
    public function aTokenRequestLeavesNoSessionCookie(): void
    {
        $this->bearer('GET', '/api');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'A token request must not set a cookie.');
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));

        // Without the token the same client is anonymous again.
        $this->client->request('GET', '/de/dashboard');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function aSessionCookieSentAlongWithATokenIsIgnored(): void
    {
        $other = (new User())->setEmail(uniqid('adm_', true) . '@example.test')->setFirstName('A')->setLastName('Dmin')
            ->setRoles(['ROLE_ADMIN'])->setPassword('hashed')->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($other);
        $this->em->flush();
        $this->client->loginUser($other);

        // The admin's session does not lift the read-only token: still 405 / 401.
        $this->bearer('POST', '/api/risks', '{}');
        self::assertResponseStatusCodeSame(405);
        $this->bearer('GET', '/de/admin/users');
        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function aTokenOfADeactivatedPersonIsRefused(): void
    {
        // Deactivate behind the ORM's back, so the token is still unrevoked:
        // the UserChecker alone has to refuse it.
        $this->em->getConnection()->executeStatement('UPDATE users SET is_active = 0 WHERE id = ?', [$this->user->getId()]);
        $this->em->clear(); // the first request runs on this kernel: no stale User in the identity map

        $this->bearer('GET', '/api');
        self::assertResponseStatusCodeSame(401);

        $this->bearer('POST', '/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}');
        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function deactivatingAPersonRevokesAllTokens(): void
    {
        $second = static::getContainer()->get(ApiTokenManager::class)->mint($this->user, 'Second', canWrite: true);

        $user = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $user);
        $user->setIsActive(false);
        $this->em->flush();
        $this->em->clear();

        $tokens = static::getContainer()->get(ApiTokenRepository::class)->findBy(['user' => $this->user->getId()]);
        self::assertCount(2, $tokens);
        foreach ($tokens as $token) {
            self::assertTrue($token->isRevoked(), $token->getLabel());
        }

        // Switching the account back on does not bring the tokens back.
        $user = $this->em->find(User::class, $this->user->getId());
        $user?->setIsActive(true);
        $this->em->flush();
        $this->client->request('GET', '/api', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $second, 'HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * S14: a role change revokes the tokens (stored role or custom role, any
     * path) — a token created for reading must not silently gain new rights.
     */
    #[Test]
    public function aRoleChangeRevokesAllTokens(): void
    {
        $user = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $user);
        $user->setRoles(['ROLE_ISB', 'ROLE_DPO']);
        $this->em->flush();
        $this->em->clear();
        $this->bearer('GET', '/api');
        self::assertResponseStatusCodeSame(401);

        // A request reboots the kernel: use its entity manager from here on.
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $user);
        $fresh = static::getContainer()->get(ApiTokenManager::class)->mint($user, 'After role change');
        $role = (new Role())->setName('Prüfteam ' . uniqid())->setDescription('S14');
        $this->em->persist($role);
        $user->addCustomRole($role);
        $this->em->flush();
        $this->em->clear();
        $this->client->request('GET', '/api', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $fresh, 'HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseStatusCodeSame(401);

        // Saving the account without a role change keeps the token.
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $user);
        $kept = static::getContainer()->get(ApiTokenManager::class)->mint($user, 'Unchanged');
        $user->setRoles(array_reverse($user->getStoredRoles()))->setFirstName('Tona');
        $this->em->flush();
        $this->client->request('GET', '/api', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $kept, 'HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();
    }

    /** S14: tokens are minted only after a real login, never from a remember-me session. */
    #[Test]
    public function tokenPagesNeedAFullLogin(): void
    {
        foreach (['index', 'create'] as $action) {
            $granted = array_map(
                static fn (\ReflectionAttribute $a): mixed => $a->newInstance()->attribute,
                (new \ReflectionMethod(\App\Controller\ApiTokenController::class, $action))->getAttributes(\Symfony\Component\Security\Http\Attribute\IsGranted::class),
            );
            self::assertContains('IS_AUTHENTICATED_FULLY', $granted, $action);
        }
    }

    #[Test]
    public function anOpenSessionEndsWhenThePersonIsDeactivated(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/de/dashboard');
        self::assertStringNotContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));

        $this->em->getConnection()->executeStatement('UPDATE users SET is_active = 0 WHERE id = ?', [$this->user->getId()]);

        $this->client->request('GET', '/de/dashboard');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));

        // Logged out, not just bounced once: switching back on needs a new login.
        $this->em->getConnection()->executeStatement('UPDATE users SET is_active = 1 WHERE id = ?', [$this->user->getId()]);
        $this->client->request('GET', '/de/dashboard');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function aDeactivatedPersonCannotLogInWithAPassword(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = $this->em->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $user);
        $user->setPassword($hasher->hashPassword($user, 'Correct-Horse-9'));
        $user->setIsActive(false);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/en/login');
        $csrf = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/en/login', [
            '_username' => $this->user->getEmail(),
            '_password' => 'Correct-Horse-9',
            '_csrf_token' => $csrf,
        ]);

        $this->client->request('GET', '/en/dashboard');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function aTokenOfAnotherOrganisationIsRefused(): void
    {
        $other = (new Tenant())->setName('Other ' . uniqid())->setCode('oth_' . bin2hex(random_bytes(4)));
        $this->em->persist($other);
        $user = $this->em->find(User::class, $this->user->getId());
        $user?->setTenant($other); // moved to another organisation after the token was made
        $this->em->flush();

        $this->bearer('GET', '/api');
        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function aReviewerGetsNoWritingToken(): void
    {
        $auditor = (new User())->setEmail(uniqid('aud_', true) . '@example.test')->setFirstName('Au')->setLastName('Ditor')
            ->setRoles(['ROLE_AUDITOR'])->setPassword('hashed')->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($auditor);
        $this->em->flush();

        $this->client->loginUser($auditor);
        $crawler = $this->client->request('GET', '/de/profile/api-tokens');
        self::assertResponseIsSuccessful();
        $csrf = (string) $crawler->filter('form[action$="/profile/api-tokens/create"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/de/profile/api-tokens/create', ['label' => 'Pruefer', 'can_write' => '1', '_token' => $csrf]);

        $tokens = static::getContainer()->get(ApiTokenRepository::class)->findBy(['user' => $auditor->getId()]);
        self::assertCount(1, $tokens);
        self::assertInstanceOf(ApiToken::class, $tokens[0]);
        self::assertFalse($tokens[0]->canWrite());
    }

    /** Both firewalls check the account status — a new firewall without it would reopen the gap. */
    #[Test]
    public function everyFirewallThatAuthenticatesChecksTheAccount(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/packages/security.yaml');
        foreach ($config['security']['firewalls'] as $name => $firewall) {
            if (($firewall['security'] ?? true) === false) {
                continue;
            }
            self::assertSame('App\Security\UserChecker', $firewall['user_checker'] ?? null, $name);
        }
        self::assertTrue($config['security']['firewalls']['api_token']['stateless'] ?? false);
        self::assertNotContains(
            'App\Security\ApiTokenAuthenticator',
            $config['security']['firewalls']['main']['custom_authenticators'] ?? [],
            'API tokens belong to the stateless api_token firewall only.',
        );
    }

    /**
     * T2: a matcher widened to `str_contains($path, '/api/')` stayed green and opened the
     * session endpoints below /de/api/… (quick-create, glossary, corporate tree) to tokens. Every
     * route of the router is checked: the token paths carry API Platform operations, the API docs
     * and the assistant endpoint only; and a token POST to the app's own /…/api/ endpoints is refused.
     */
    #[Test]
    public function aTokenIsAcceptedOnlyOnApiPlatformAndMcpRoutes(): void
    {
        $router = static::getContainer()->get('router');
        $wrong = [];
        $appApiPosts = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $path = (string) preg_replace_callback(
                '/\{(\w+)[^}]*\}/',
                static fn (array $m): string => $m[1] === '_locale' ? 'de' : ($m[1] === '_format' ? 'jsonld' : '1'),
                $route->getPath(),
            );
            $isApi = $route->getDefault('_api_resource_class') !== null || $route->getDefault('_api_operation_name') !== null
                || str_starts_with((string) $name, 'api_doc') || str_starts_with((string) $name, 'api_entrypoint')
                || str_starts_with((string) $name, 'api_genid') || str_starts_with((string) $name, 'api_jsonld_context')
                || str_starts_with((string) $name, 'api_validation_errors')
                || str_starts_with((string) $route->getDefault('_controller'), 'App\Controller\McpController')
                // Glossary definitions (config/glossary.yaml): the same text for everybody, GET only, no record data.
                || $name === 'app_api_glossary';
            if (ApiTokenRequest::acceptsTokenOn($path) && !$isApi) {
                $wrong[] = $name . ' ' . $path;
            }
            if (!$isApi && str_contains($path, '/api/') && array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->getMethods()) !== []) {
                $appApiPosts[$name] = $path;
            }
        }

        self::assertSame([], $wrong, 'These session endpoints would accept an API token.');
        self::assertNotEmpty($appApiPosts, 'the app has its own POST endpoints below …/api/');
        foreach ($appApiPosts as $name => $path) {
            $this->bearer('POST', $path, '{}');
            // 401: not a token path; 405: bearer writes are refused before routing (ReadOnlyApiSubscriber)
            self::assertContains($this->client->getResponse()->getStatusCode(), [401, 405], $name . ' ' . $path);
        }
    }

    private function bearer(string $method, string $path, ?string $body = null): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => 'application/ld+json',
        ], content: $body);
    }
}
