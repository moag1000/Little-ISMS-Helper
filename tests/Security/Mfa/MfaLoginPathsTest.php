<?php

declare(strict_types=1);

namespace App\Tests\Security\Mfa;

use App\Entity\MfaToken;
use App\Entity\User;
use App\EventSubscriber\MfaEnforcerSubscriber;
use App\Security\Mfa\MfaLoginListener;
use App\Security\Mfa\MfaSessionState;
use App\Security\SetupAccessChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Yaml\Yaml;

/**
 * S8 permanent test: for every authenticator of the web firewall, a person with a second factor
 * reaches no page without it, and "stay logged in" waits for it. A new authenticator in
 * security.yaml fails {@see self::everyAuthenticatorOfTheWebFirewallIsDecided()} until it is
 * listed here with its decision.
 */
final class MfaLoginPathsTest extends TestCase
{
    /**
     * Authenticator of the main firewall → token it creates. 'cookie' = remember-me, which counts
     * as both factors (issued only after the second factor, signature over the active devices).
     */
    private const array DECIDED = [
        'form_login' => 'password',
        'remember_me' => 'cookie',
        'App\Security\AzureOAuthAuthenticator' => 'sso',
        'App\Security\AzureSamlAuthenticator' => 'sso',
        'App\Security\GenericOidcAuthenticator' => 'sso',
    ];

    /** Symfony's built-in authenticator keys of a firewall. */
    private const array BUILT_IN = ['form_login', 'json_login', 'http_basic', 'login_link', 'remember_me',
        'x509', 'remote_user', 'access_token'];

    #[Test]
    public function everyAuthenticatorOfTheWebFirewallIsDecided(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 3) . '/config/packages/security.yaml');
        $main = $config['security']['firewalls'][MfaLoginListener::FIREWALL];

        $configured = [];
        foreach (self::BUILT_IN as $key) {
            if (array_key_exists($key, $main)) {
                $configured[] = $key;
            }
        }
        foreach ($main['custom_authenticators'] ?? [] as $class) {
            $configured[] = $class;
        }
        sort($configured);
        $decided = array_keys(self::DECIDED);
        sort($decided);

        self::assertSame($decided, $configured, 'A login path of the web firewall without an MFA decision.');
    }

    /** @return iterable<string, array{string}> */
    public static function authenticators(): iterable
    {
        foreach (self::DECIDED as $name => $kind) {
            yield $name => [$kind];
        }
    }

    #[Test]
    #[DataProvider('authenticators')]
    public function aPersonWithSecondFactorReachesNoPageWithoutIt(string $kind): void
    {
        $user = self::user(1, mfa: true);
        $session = self::session();
        $event = $this->login($user, $kind, $session, remember: true);

        $page = $this->enforce($user, $session, '/de/dashboard', $kind === 'cookie' ? self::cookieToken($user) : null);

        if ($kind === 'cookie') {
            self::assertNull($page->getResponse(), 'The cookie exists only after the second factor.');

            return;
        }
        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
        self::assertSame('/de/mfa-challenge', $event->getResponse()->getTargetUrl());
        self::assertSame('/de/target', $session->get('_security.main.target_path'), 'The login\'s own target follows the code.');
        self::assertFalse(self::badge($event)->isEnabled(), 'No remember-me cookie with the first factor.');
        self::assertInstanceOf(RedirectResponse::class, $page->getResponse());
        self::assertSame('/de/mfa-challenge', $page->getResponse()->getTargetUrl());

        // The code is given: the page opens and the remembered wish is handed over once.
        $state = new MfaSessionState();
        self::assertTrue($state->markVerified($session, $user));
        self::assertNull($this->enforce($user, $session, '/de/dashboard')->getResponse());
    }

    #[Test]
    public function aPersonWithoutSecondFactorIsVerifiedAtOnce(): void
    {
        $user = self::user(2, mfa: false);
        $session = self::session();
        $event = $this->login($user, 'password', $session, remember: true);

        self::assertSame('/de/target', $event->getResponse()?->headers->get('Location'));
        self::assertTrue(self::badge($event)->isEnabled());
        self::assertNull($this->enforce($user, $session, '/de/dashboard')->getResponse());
    }

    #[Test]
    public function aSecondLoginDoesNotInheritTheFirstOnesVerification(): void
    {
        $session = self::session();
        $first = self::user(3, mfa: false);
        $second = self::user(4, mfa: true);
        $this->login($first, 'password', $session);
        self::assertNull($this->enforce($first, $session, '/de/dashboard')->getResponse());

        $this->login($second, 'password', $session);
        self::assertNotNull($this->enforce($second, $session, '/de/dashboard')->getResponse());

        // Even a state of another person without any login event in between counts as none.
        $session->set(MfaSessionState::KEY, ['user' => 3, 'verified' => true, 'remember' => false]);
        self::assertNotNull($this->enforce($second, $session, '/de/dashboard')->getResponse());
    }

    #[Test]
    public function aSessionWithoutStateFailsClosed(): void
    {
        $session = self::session();
        $session->set('_security.mfa_verified', true); // flag of the old scheme
        self::assertNotNull($this->enforce(self::user(5, mfa: true), $session, '/de/dashboard')->getResponse());
    }

    #[Test]
    public function setupIsOpenOnlyBeforeTheInstallationIsSetUp(): void
    {
        $user = self::user(6, mfa: true);
        $session = self::session();
        $this->login($user, 'password', $session);

        self::assertNotNull($this->enforce($user, $session, '/de/setup/step3-restore-backup', setupComplete: true)->getResponse());
        self::assertNull($this->enforce($user, $session, '/de/setup', setupComplete: false)->getResponse());
        self::assertNull($this->enforce($user, $session, '/de/mfa-challenge', setupComplete: true)->getResponse());
    }

    #[Test]
    public function impersonationKeepsTheAdminsVerification(): void
    {
        $admin = self::user(7, mfa: true);
        $target = self::user(8, mfa: true);
        $session = self::session();
        $this->login($admin, 'password', $session);
        (new MfaSessionState())->markVerified($session, $admin);

        $original = new UsernamePasswordToken($admin, 'main', ['ROLE_SUPER_ADMIN']);
        $switched = new SwitchUserToken($target, 'main', ['ROLE_USER'], $original);
        self::assertSame($admin, MfaSessionState::subjectOf($switched));
        self::assertNull($this->enforce($target, $session, '/de/dashboard', $switched)->getResponse());
    }

    #[Test]
    public function theApiTokenFirewallIsNotTouched(): void
    {
        $user = self::user(9, mfa: true);
        $session = self::session();
        $passport = new SelfValidatingPassport(new UserBadge('u9', static fn () => $user));
        $event = new LoginSuccessEvent($this->createStub(AuthenticatorInterface::class), $passport,
            new PostAuthenticationToken($user, 'api_token', []), self::request($session), null, 'api_token');
        $this->listener()->onLoginSuccess($event);

        self::assertNull($session->get(MfaSessionState::KEY));
        self::assertNull($this->enforce($user, $session, '/api/risks', firewall: 'api_token')->getResponse());
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function login(User $user, string $kind, SessionInterface $session, bool $remember = false): LoginSuccessEvent
    {
        $badges = [];
        if ($kind !== 'cookie') {
            $badge = new RememberMeBadge();
            if ($remember) {
                $badge->enable();
            }
            $badges[] = $badge;
        }
        $passport = new SelfValidatingPassport(new UserBadge((string) $user->getEmail(), static fn () => $user), $badges);
        $token = $kind === 'cookie' ? self::cookieToken($user) : new PostAuthenticationToken($user, 'main', ['ROLE_USER']);

        $event = new LoginSuccessEvent($this->createStub(AuthenticatorInterface::class), $passport, $token,
            self::request($session), new RedirectResponse('/de/target'), 'main');
        $this->listener()->onLoginSuccess($event);

        return $event;
    }

    private function enforce(
        User $user,
        SessionInterface $session,
        string $path,
        ?TokenInterface $token = null,
        bool $setupComplete = true,
        string $firewall = 'main',
    ): RequestEvent {
        $token ??= new PostAuthenticationToken($user, 'main', ['ROLE_USER']);
        $security = $this->createStub(Security::class);
        $security->method('getToken')->willReturn($token);
        $security->method('getUser')->willReturn($token->getUser());
        $security->method('getFirewallConfig')->willReturn(new FirewallConfig($firewall, 'security.user_checker'));
        $setup = $this->createStub(SetupAccessChecker::class);
        $setup->method('isSetupComplete')->willReturn($setupComplete);

        $request = Request::create($path);
        $request->setSession($session);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new MfaEnforcerSubscriber($security, $this->urls(), new MfaSessionState(), $setup))->onKernelRequest($event);

        return $event;
    }

    private function listener(): MfaLoginListener
    {
        return new MfaLoginListener(new MfaSessionState(), $this->urls());
    }

    private function urls(): UrlGeneratorInterface
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []): string => '/' . ($params['_locale'] ?? 'de') . '/mfa-challenge',
        );

        return $urls;
    }

    private static function badge(LoginSuccessEvent $event): RememberMeBadge
    {
        $badge = $event->getPassport()->getBadge(RememberMeBadge::class);
        self::assertInstanceOf(RememberMeBadge::class, $badge);

        return $badge;
    }

    private static function cookieToken(User $user): RememberMeToken
    {
        return new RememberMeToken($user, 'main');
    }

    private static function request(SessionInterface $session): Request
    {
        $request = Request::create('/de/login', 'POST');
        $request->setSession($session);

        return $request;
    }

    private static function session(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('_locale', 'de');

        return $session;
    }

    private static function user(int $id, bool $mfa): User
    {
        $user = (new User())->setEmail("u{$id}@example.test")->setRoles(['ROLE_USER']);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        if ($mfa) {
            $user->addMfaToken(new MfaToken());
        }

        return $user;
    }
}
