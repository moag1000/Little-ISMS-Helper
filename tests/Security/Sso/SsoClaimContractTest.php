<?php

declare(strict_types=1);

namespace App\Tests\Security\Sso;

use App\Entity\IdentityProvider;
use App\Entity\User;
use App\Http\UserUrlHttpClient;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Security\AzureOAuthAuthenticator;
use App\Security\AzureSamlAuthenticator;
use App\Security\GenericOidcAuthenticator;
use App\Security\SamlAuthFactory;
use App\Service\Sso\ClaimToRoleResolver;
use App\Service\Sso\OidcAuthenticationFlow;
use App\Service\Sso\OidcDiscoveryService;
use App\Service\Sso\SsoAccountLinkPolicy;
use App\Service\Sso\SsoClaimPolicy;
use App\Service\Sso\SsoEventLogger;
use App\Service\Sso\SsoSecretEncryption;
use App\Tests\Support\SsoTestKit;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use OneLogin\Saml2\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Yaml\Yaml;
use TheNetworg\OAuth2\Client\Provider\Azure;
use TheNetworg\OAuth2\Client\Provider\AzureResourceOwner;
use TheNetworg\OAuth2\Client\Token\AccessToken;

/**
 * S9 SSO claim contract: what every SSO authenticator of the web firewall has
 * to prove before it uses the IdP's claims ({@see SsoClaimPolicy}). A new
 * authenticator in security.yaml without an entry in {@see CONTRACTS} fails
 * here — its claim checks have to be decided and tested, not assumed.
 */
final class SsoClaimContractTest extends TestCase
{
    private const string CLIENT_ID = 'client-1';
    private const string ISSUER = 'https://idp.example.org';
    private const string NONCE = 'nonce-123';

    /**
     * Authenticator → the contract tests below that pin its claim checks.
     */
    private const array CONTRACTS = [
        AzureOAuthAuthenticator::class => [
            'azureOAuthRefusesAForeignOrMissingDirectory',
            'azureOAuthAcceptsTheConfiguredAndListedDirectories',
            'azureOAuthLinksOnlyAVerifiedEmail',
        ],
        AzureSamlAuthenticator::class => [
            'samlRefusesUnsolicitedAndUnknownResponses',
            'samlRefusesAReplayedAssertion',
            'samlRefusesAForeignDirectory',
            'samlUrlsComeFromDefaultUriNotTheHostHeader',
        ],
        GenericOidcAuthenticator::class => [
            'idTokenNeedsIssuerExpiryAudienceAndNonce',
            'userInfoNeverChangesTheSubjectOrASignedClaim',
            'oidcLinksAnExistingAccountOnlyWithAVerifiedEmail',
            'oidcCallbackCarriesTheNonce',
        ],
    ];

    #[Test]
    public function everySsoAuthenticatorOfTheWebFirewallHasAContract(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/packages/security.yaml');
        $authenticators = $config['security']['firewalls']['main']['custom_authenticators'] ?? [];
        self::assertNotEmpty($authenticators);

        foreach ($authenticators as $class) {
            self::assertArrayHasKey($class, self::CONTRACTS, $class . ' takes SSO claims without a claim contract (S9)');
            foreach (self::CONTRACTS[$class] as $method) {
                self::assertTrue(method_exists($this, $method), $class . ': contract test ' . $method . ' missing');
            }
        }
    }

    // ── Azure OAuth (env) ────────────────────────────────────────────────

    #[Test]
    public function azureOAuthRefusesAForeignOrMissingDirectory(): void
    {
        foreach (['99999999-0000-0000-0000-000000000000', null, ''] as $tid) {
            try {
                $this->azureOAuthLogin(['tid' => $tid], configuredTenant: SsoTestKit::AZURE_TENANT);
                self::fail('directory ' . var_export($tid, true) . ' must be refused');
            } catch (CustomUserMessageAuthenticationException $e) {
                self::assertSame(SsoClaimPolicy::AZURE_TENANT_REFUSED_MESSAGE, $e->getMessageKey());
            }
        }
        // A multi-tenant registration ("common") without an allow list admits no directory.
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->azureOAuthLogin(['tid' => SsoTestKit::AZURE_TENANT], configuredTenant: 'common');
    }

    #[Test]
    public function azureOAuthAcceptsTheConfiguredAndListedDirectories(): void
    {
        self::assertInstanceOf(User::class, $this->azureOAuthLogin(['tid' => SsoTestKit::AZURE_TENANT], configuredTenant: SsoTestKit::AZURE_TENANT));
        self::assertInstanceOf(User::class, $this->azureOAuthLogin(
            ['tid' => 'AAAAAAAA-0000-0000-0000-000000000000'],
            configuredTenant: 'organizations',
            allowList: SsoTestKit::AZURE_TENANT . ', aaaaaaaa-0000-0000-0000-000000000000',
        ));
    }

    #[Test]
    public function azureOAuthLinksOnlyAVerifiedEmail(): void
    {
        $existing = (new User())->setEmail('jane@example.com')->setRoles(['ROLE_USER']);
        try {
            $this->azureOAuthLogin(['tid' => SsoTestKit::AZURE_TENANT], SsoTestKit::AZURE_TENANT, byEmail: $existing, allowLinking: true);
            self::fail('unverified e-mail linked an account');
        } catch (CustomUserMessageAuthenticationException) {
        }
        self::assertNull($existing->getAzureObjectId());

        // Linking stays off unless the operator opts in.
        try {
            $this->azureOAuthLogin(['tid' => SsoTestKit::AZURE_TENANT, 'xms_edov' => true], SsoTestKit::AZURE_TENANT, byEmail: $existing, allowLinking: false);
            self::fail('linking must be off by default');
        } catch (CustomUserMessageAuthenticationException) {
        }

        $linked = $this->azureOAuthLogin(['tid' => SsoTestKit::AZURE_TENANT, 'xms_edov' => true], SsoTestKit::AZURE_TENANT, byEmail: $existing, allowLinking: true);
        self::assertSame($existing, $linked);
        self::assertFalse((new \ReflectionParameter([AzureOAuthAuthenticator::class, '__construct'], 'allowEmailLinking'))->getDefaultValue());
    }

    // ── Azure SAML (env) ─────────────────────────────────────────────────

    #[Test]
    public function samlRefusesUnsolicitedAndUnknownResponses(): void
    {
        $guard = SsoTestKit::samlGuard();
        foreach ([
            'unsolicited (IdP-initiated)' => SsoTestKit::acsRequest($guard, withInResponseTo: false),
            'unknown request' => SsoTestKit::acsRequest($guard, 'ONELOGIN_never_sent', remember: false),
            // Login CSRF (S14): the attacker's own response posted into another browser.
            'other browser' => SsoTestKit::acsRequest($guard, 'ONELOGIN_attacker', sameBrowser: false),
            'no response' => new Request(request: []),
        ] as $case => $request) {
            try {
                $this->samlLogin($guard, $request);
                self::fail($case . ' must be refused');
            } catch (AuthenticationException) {
                self::addToAssertionCount(1);
            }
        }

        // The request id is used up by the first answer.
        $request = SsoTestKit::acsRequest($guard, 'ONELOGIN_once');
        $this->samlLogin($guard, $request, assertionId: '_a1');
        $this->expectException(AuthenticationException::class);
        $this->samlLogin($guard, $request, assertionId: '_a2');
    }

    #[Test]
    public function samlRefusesAReplayedAssertion(): void
    {
        $guard = SsoTestKit::samlGuard();
        $this->samlLogin($guard, SsoTestKit::acsRequest($guard, 'ONELOGIN_r1'), assertionId: '_same');

        // Even answering a fresh request, the same assertion is not accepted twice.
        $this->expectExceptionMessage('already used');
        $this->samlLogin($guard, SsoTestKit::acsRequest($guard, 'ONELOGIN_r2'), assertionId: '_same');
    }

    #[Test]
    public function samlRefusesAForeignDirectory(): void
    {
        $guard = SsoTestKit::samlGuard();
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->samlLogin($guard, SsoTestKit::acsRequest($guard), tenantId: '99999999-0000-0000-0000-000000000000', configuredTenant: SsoTestKit::AZURE_TENANT);
    }

    #[Test]
    public function samlUrlsComeFromDefaultUriNotTheHostHeader(): void
    {
        $factory = fn (string $env, ?string $uri): SamlAuthFactory => new SamlAuthFactory(
            new UrlGenerator(new RouteCollection(), new RequestContext(host: 'attacker.example')),
            $env,
            '',
            '',
            defaultUri: $uri,
        );
        self::assertSame('https://isms.example.org', $factory('prod', 'https://isms.example.org/')->baseUrl());
        self::assertSame('https://isms.example.org:8443', $factory('prod', 'https://ISMS.example.org:8443/sub')->baseUrl());
        self::assertFalse((new \ReflectionMethod(SamlAuthFactory::class, 'createAuth'))->getNumberOfParameters() > 0, 'createAuth() must not take the request');

        foreach ([null, '', 'http://localhost', 'https://yourdomain.com'] as $unset) {
            try {
                $factory('prod', $unset)->baseUrl();
                self::fail('DEFAULT_URI ' . var_export($unset, true) . ' must not be used in prod');
            } catch (\App\Exception\BusinessRule\BusinessRuleException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ── Generic OIDC (IdentityProvider records) ──────────────────────────

    /**
     * @return iterable<string, array{array<string, mixed>, ?string, ?string}>
     */
    public static function brokenIdTokens(): iterable
    {
        $ok = self::validPayload();
        yield 'no issuer configured' => [$ok, null, self::NONCE];
        yield 'other issuer' => [['iss' => 'https://evil.example'] + $ok, self::ISSUER, self::NONCE];
        yield 'no exp' => [array_diff_key($ok, ['exp' => 1]), self::ISSUER, self::NONCE];
        yield 'expired' => [['exp' => time() - 3600] + $ok, self::ISSUER, self::NONCE];
        yield 'issued in the future' => [['iat' => time() + 3600] + $ok, self::ISSUER, self::NONCE];
        yield 'other audience' => [['aud' => 'other-client'] + $ok, self::ISSUER, self::NONCE];
        yield 'azp of another client' => [['aud' => [self::CLIENT_ID, 'x'], 'azp' => 'x'] + $ok, self::ISSUER, self::NONCE];
        yield 'no nonce in token' => [array_diff_key($ok, ['nonce' => 1]), self::ISSUER, self::NONCE];
        yield 'other nonce' => [['nonce' => 'replayed'] + $ok, self::ISSUER, self::NONCE];
        yield 'no nonce in session' => [$ok, self::ISSUER, null];
        yield 'no subject' => [array_diff_key($ok, ['sub' => 1]), self::ISSUER, self::NONCE];
    }

    #[Test]
    #[DataProvider('brokenIdTokens')]
    public function idTokenNeedsIssuerExpiryAudienceAndNonce(array $payload, ?string $issuer, ?string $nonce): void
    {
        SsoClaimPolicy::assertIdToken(self::validPayload(), self::ISSUER, self::CLIENT_ID, self::NONCE);

        $this->expectException(AuthenticationException::class);
        SsoClaimPolicy::assertIdToken($payload, $issuer, self::CLIENT_ID, $nonce);
    }

    #[Test]
    public function userInfoNeverChangesTheSubjectOrASignedClaim(): void
    {
        $id = ['sub' => 'user-1', 'email' => 'jane@example.com', 'email_verified' => false];
        $merged = SsoClaimPolicy::mergeUserInfo($id, ['sub' => 'user-1', 'email' => 'boss@example.com', 'email_verified' => true, 'given_name' => 'Jane']);
        self::assertSame('jane@example.com', $merged['email']);
        self::assertFalse($merged['email_verified']);
        self::assertSame('Jane', $merged['given_name']);

        $this->expectException(AuthenticationException::class);
        SsoClaimPolicy::mergeUserInfo($id, ['sub' => 'someone-else']);
    }

    #[Test]
    public function oidcLinksAnExistingAccountOnlyWithAVerifiedEmail(): void
    {
        $provider = (new IdentityProvider())->setSlug('idp')->setAllowEmailLinking(true)
            ->setAttributeMap(['email' => 'email']);
        $claims = ['sub' => 'user-1', 'email' => 'jane@example.com'];

        foreach ([[], ['email_verified' => false], ['email_verified' => 'false']] as $extra) {
            $existing = (new User())->setEmail('jane@example.com')->setRoles(['ROLE_USER']);
            try {
                $this->resolveOidc($provider, $claims + $extra, $existing);
                self::fail('unverified e-mail ' . json_encode($extra) . ' linked an account');
            } catch (CustomUserMessageAuthenticationException $e) {
                self::assertSame(SsoAccountLinkPolicy::EMAIL_LINKING_DISABLED_MESSAGE, $e->getMessageKey());
            }
            self::assertNull($existing->getSsoExternalId());
        }

        $existing = (new User())->setEmail('jane@example.com')->setRoles(['ROLE_USER']);
        self::assertSame($existing, $this->resolveOidc($provider, $claims + ['email_verified' => true], $existing));
        self::assertSame('user-1', $existing->getSsoExternalId());
    }

    /** S14: a verified `email` does not vouch for another mapped claim (here `upn`). */
    #[Test]
    public function oidcVerifiedFlagDoesNotVouchForAnotherMappedClaim(): void
    {
        $provider = (new IdentityProvider())->setSlug('idp')->setAllowEmailLinking(true)
            ->setAttributeMap(['upn' => 'email']);
        $existing = (new User())->setEmail('boss@example.com')->setRoles(['ROLE_USER']);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        try {
            $this->resolveOidc($provider, ['sub' => 'user-2', 'email' => 'jane@example.com', 'upn' => 'boss@example.com', 'email_verified' => true], $existing);
        } finally {
            self::assertNull($existing->getSsoExternalId());
        }
    }

    #[Test]
    public function oidcCallbackCarriesTheNonce(): void
    {
        $params = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(OidcAuthenticationFlow::class, 'handleCallback'))->getParameters(),
        );
        self::assertContains('nonce', $params);
        $ctor = new \ReflectionMethod(OidcAuthenticationFlow::class, '__construct');
        foreach ($ctor->getParameters() as $p) {
            if ($p->getName() === 'userUrlClient') {
                self::assertFalse($p->allowsNull(), 'token/UserInfo calls must always use the SSRF-guarded client');
            }
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function validPayload(): array
    {
        return [
            'iss' => self::ISSUER,
            'sub' => 'user-1',
            'aud' => self::CLIENT_ID,
            'exp' => time() + 300,
            'iat' => time(),
            'nonce' => self::NONCE,
        ];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function azureOAuthLogin(array $claims, ?string $configuredTenant, ?string $allowList = null, ?User $byEmail = null, bool $allowLinking = false): User
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findByAzureObjectId')->willReturn(null);
        $users->method('findOneBy')->willReturn($byEmail);
        $token = new AccessToken(['access_token' => 'token-123'], $this->createStub(Azure::class));
        $client = $this->createStub(OAuth2ClientInterface::class);
        $client->method('getAccessToken')->willReturn($token);
        $client->method('fetchUserFromToken')->willReturn(new AzureResourceOwner(
            $claims + ['oid' => 'object-1', 'email' => 'jane@example.com'],
            $token,
        ));
        $registry = $this->createStub(ClientRegistry::class);
        $registry->method('getClient')->willReturn($client);

        $authenticator = new AzureOAuthAuthenticator(
            $registry,
            $this->createStub(EntityManagerInterface::class),
            $users,
            $this->createStub(RouterInterface::class),
            $this->createStub(TenantRepository::class),
            $allowLinking,
            null,
            $configuredTenant,
            $allowList,
        );
        $user = $authenticator->authenticate(new Request())->getBadge(UserBadge::class)->getUser();
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function samlLogin(
        \App\Security\SamlMessageGuard $guard,
        Request $request,
        string $assertionId = '_assertion',
        ?string $tenantId = null,
        ?string $configuredTenant = null,
    ): User {
        $auth = $this->createStub(Auth::class);
        $auth->method('isAuthenticated')->willReturn(true);
        $auth->method('getNameId')->willReturn('jane@example.com');
        $auth->method('getLastAssertionId')->willReturn($assertionId);
        $auth->method('getLastAssertionNotOnOrAfter')->willReturn(time() + 300);
        $attributes = ['http://schemas.microsoft.com/identity/claims/objectidentifier' => ['object-1']];
        if ($tenantId !== null) {
            $attributes['http://schemas.microsoft.com/identity/claims/tenantid'] = [$tenantId];
        }
        $auth->method('getAttributes')->willReturn($attributes);

        $users = $this->createStub(UserRepository::class);
        $users->method('findByAzureObjectId')->willReturn(null);
        $users->method('findOneBy')->willReturn(null);
        $authenticator = new AzureSamlAuthenticator(
            $this->createStub(EntityManagerInterface::class),
            $users,
            $this->createStub(RouterInterface::class),
            $this->createStub(TenantRepository::class),
            SsoTestKit::samlFactory(),
            $guard,
            azureTenantId: $configuredTenant,
        );
        SsoTestKit::injectAuth($authenticator, $auth);

        $user = $authenticator->authenticate($request)->getBadge(UserBadge::class)->getUser();
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function resolveOidc(IdentityProvider $provider, array $claims, User $byEmail): User
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?User => isset($criteria['email']) ? $byEmail : null,
        );
        $resolver = $this->createStub(ClaimToRoleResolver::class);
        $resolver->method('resolve')->willReturn(new \App\Service\Sso\ClaimToRoleResolverResult('ROLE_USER', false, 'none'));
        $flow = new OidcAuthenticationFlow(
            $this->createStub(OidcDiscoveryService::class),
            new SsoSecretEncryption('test-kernel-secret'),
            $this->createStub(EntityManagerInterface::class),
            $users,
            new NullLogger(),
            $resolver,
            $this->createStub(SsoEventLogger::class),
            new UserUrlHttpClient(new MockHttpClient()),
        );
        $result = (new \ReflectionMethod(OidcAuthenticationFlow::class, 'resolveUserFromClaims'))->invoke($flow, $provider, $claims);
        self::assertInstanceOf(User::class, $result);

        return $result;
    }
}
