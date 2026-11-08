<?php

declare(strict_types=1);

namespace App\Tests\Security\Mfa;

use App\Entity\MfaToken;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\MfaEncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * S8 (2026-11, NIS-2 Art. 21(2)(j), ISO 27001 A.8.5): a person with a second factor reaches no
 * page without it — whichever way the session came about. Each test is one way round the code
 * that worked before the fix:
 *
 * - "stay logged in" set its cookie with the password answer, a fresh browser opened a session
 *   with it and no MFA mark;
 * - a second login in the same session inherited the first person's "verified";
 * - the password re-authentication upgraded a remember-me session without the code;
 * - /setup stayed open while the code was outstanding;
 * - a session born without a login event (here: loginUser) had no mark and passed.
 */
final class MfaBypassRegressionTest extends WebTestCase
{
    private const string PASSWORD = 'Correct-Horse-9';
    private const string REMEMBER_COOKIE = 'REMEMBERME';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->tenant = (new Tenant())->setName('MFA ' . uniqid())->setCode('mfa_' . bin2hex(random_bytes(4)));
        $this->em->persist($this->tenant);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $statements = [];
        foreach ($this->userIds as $id) {
            $statements[] = ['DELETE FROM mfa_tokens WHERE user_id = ?', [$id]];
            $statements[] = ['DELETE FROM users WHERE id = ?', [$id]];
        }
        $statements[] = ['DELETE FROM tenant WHERE id = ?', [$this->tenant->getId()]];
        foreach ($statements as [$sql, $params]) {
            try {
                $connection->executeStatement($sql, $params);
            } catch (\Throwable) {
                // rows written by the login (audit trail, sessions) may hold on; the test DB is disposable
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function stayLoggedInWaitsForTheSecondFactor(): void
    {
        [$user, $secret] = $this->userWithTotp();

        $this->passwordLogin($user, remember: true);
        self::assertStringContainsString('/mfa-challenge', $this->location());
        self::assertNull($this->rememberCookie(), 'No remember-me cookie for the password alone.');

        // A fresh browser has nothing to log in with.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/de/dashboard');
        self::assertStringContainsString('/login', $this->location());

        // With the code the cookie follows.
        $this->passwordLogin($user, remember: true);
        $this->verify($secret);
        self::assertStringNotContainsString('/mfa-challenge', $this->location());
        self::assertNotNull($this->rememberCookie(), 'After the second factor "stay logged in" takes effect.');
    }

    #[Test]
    public function aSecondLoginInTheSameSessionAsksForItsOwnSecondFactor(): void
    {
        $plain = $this->user(['ROLE_USER']);
        [$victim] = $this->userWithTotp();

        $this->passwordLogin($plain);
        $this->client->request('GET', '/de/dashboard');
        self::assertStringNotContainsString('/mfa-challenge', $this->location());

        $this->passwordLogin($victim);
        $this->client->request('GET', '/de/dashboard');
        self::assertResponseRedirects();
        self::assertStringContainsString('/mfa-challenge', $this->location());
    }

    #[Test]
    public function passwordReauthenticationAsksForTheSecondFactor(): void
    {
        [$user, $secret] = $this->userWithTotp();
        $this->passwordLogin($user, remember: true);
        $this->verify($secret);
        $cookie = $this->rememberCookie();
        self::assertNotNull($cookie);

        // New browser session, logged in by the cookie (issued after the code).
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($cookie);
        $this->client->request('GET', '/de/dashboard');
        self::assertStringNotContainsString('/mfa-challenge', $this->location());
        self::assertStringNotContainsString('/login', $this->location());

        $this->client->request('POST', '/de/reauth/password', server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['password' => self::PASSWORD, 'return_to' => '/de/dashboard']));
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['mfa_required'] ?? false, 'The password alone does not finish a re-authentication.');
        self::assertStringContainsString('/mfa-challenge', (string) ($body['return_to'] ?? ''));

        $this->client->request('GET', '/de/dashboard');
        self::assertStringContainsString('/mfa-challenge', $this->location());
    }

    #[Test]
    public function aCookieFromBeforeTheEnrolmentNoLongerLogsIn(): void
    {
        $user = $this->user(['ROLE_USER']);
        $this->passwordLogin($user, remember: true);
        $cookie = $this->rememberCookie();
        self::assertNotNull($cookie, 'Without a second factor the cookie comes with the password.');

        $this->addTotp($user);

        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($cookie);
        $this->client->request('GET', '/de/dashboard');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', $this->location());
    }

    #[Test]
    public function setupWizardIsClosedWhileTheSecondFactorIsOutstanding(): void
    {
        [$admin] = $this->userWithTotp(['ROLE_SUPER_ADMIN']);
        $this->passwordLogin($admin);

        $this->client->request('GET', '/de/setup');
        self::assertResponseRedirects();
        self::assertStringContainsString('/mfa-challenge', $this->location());
    }

    #[Test]
    public function aSessionWithoutLoginEventFailsClosed(): void
    {
        [$user] = $this->userWithTotp();
        $this->client->loginUser($this->em->find(User::class, $user->getId()));

        $this->client->request('GET', '/de/dashboard');
        self::assertResponseRedirects();
        self::assertStringContainsString('/mfa-challenge', $this->location());
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @param list<string> $roles */
    private function user(array $roles): User
    {
        $user = (new User())->setEmail(uniqid('mfa_', true) . '@example.test')->setFirstName('Mfa')->setLastName('Test')
            ->setRoles($roles)->setTenant($this->tenant)->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->userIds[] = (int) $user->getId();

        return $user;
    }

    /**
     * @param list<string> $roles
     *
     * @return array{User, string}
     */
    private function userWithTotp(array $roles = ['ROLE_USER']): array
    {
        $user = $this->user($roles);

        return [$user, $this->addTotp($user)];
    }

    private function addTotp(User $user): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $managed = $em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $managed);
        $secret = TOTP::generate()->getSecret();
        $token = (new MfaToken())->setUser($managed)->setTokenType('totp')->setDeviceName('Phone')
            ->setSecret(static::getContainer()->get(MfaEncryptionService::class)->encrypt($secret))
            ->setIsActive(true);
        $managed->addMfaToken($token);
        $em->persist($token);
        $em->flush();

        return $secret;
    }

    private function passwordLogin(User $user, bool $remember = false): void
    {
        $crawler = $this->client->request('GET', '/de/login');
        $field = $crawler->filter('input[name="_csrf_token"]');
        $csrf = $field->count() > 0 ? (string) $field->attr('value') : 'csrf-token';
        $data = ['_username' => $user->getEmail(), '_password' => self::PASSWORD, '_csrf_token' => $csrf];
        if ($remember) {
            $data['_remember_me'] = '1';
        }
        $this->client->request('POST', '/de/login', $data, server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', $this->location(), 'The password login itself succeeds.');
    }

    private function verify(string $secret): void
    {
        $crawler = $this->client->request('GET', '/de/mfa-challenge');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/de/mfa-verify', [
            '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'),
            'token_id' => (string) $crawler->filter('select[name="token_id"] option')->first()->attr('value'),
            'code' => TOTP::createFromSecret($secret)->now(),
        ]);
        self::assertResponseRedirects();
    }

    private function rememberCookie(): ?Cookie
    {
        $cookie = $this->client->getCookieJar()->get(self::REMEMBER_COOKIE);

        return $cookie !== null && !$cookie->isExpired() && $cookie->getValue() !== '' ? $cookie : null;
    }

    private function location(): string
    {
        return (string) $this->client->getResponse()->headers->get('Location');
    }
}
