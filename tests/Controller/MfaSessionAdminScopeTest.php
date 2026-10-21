<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\MfaToken;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security hotfix (admin scope): a tenant admin must not administer MFA
 * tokens or terminate sessions of users outside its tenant tree, nor of a
 * ROLE_SUPER_ADMIN. SUPER_ADMIN stays global.
 *
 * Fixture tree:  A (with adminA, userA, superA)      B (with userB)
 */
final class MfaSessionAdminScopeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uid;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $adminA;
    private User $superA;
    private User $userA;
    private User $userB;
    private MfaToken $tokenA;
    private MfaToken $tokenB;
    private MfaToken $tokenSuper;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = substr(md5(uniqid('', true)), 0, 10);

        $this->tenantA = $this->tenant('A');
        $this->tenantB = $this->tenant('B');

        $this->adminA = $this->user('mfa_admin_a', ['ROLE_ADMIN'], $this->tenantA);
        $this->superA = $this->user('mfa_super_a', ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'], $this->tenantA);
        $this->userA = $this->user('mfa_user_a', ['ROLE_USER'], $this->tenantA);
        $this->userB = $this->user('mfa_user_b', ['ROLE_USER'], $this->tenantB);

        $this->tokenA = $this->token($this->userA, 'DeviceA');
        $this->tokenB = $this->token($this->userB, 'DeviceB');
        $this->tokenSuper = $this->token($this->superA, 'DeviceSuper');

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->createQuery('DELETE FROM ' . AuditLog::class . ' a WHERE a.userName LIKE :p')
            ->setParameter('p', '%' . $this->uid . '%')
            ->execute();
        $userRepo = $this->em->getRepository(User::class);
        foreach ($userRepo->createQueryBuilder('u')->where('u.email LIKE :p')
            ->setParameter('p', '%' . $this->uid . '%')->getQuery()->getResult() as $user) {
            foreach ($this->em->getRepository(MfaToken::class)->findBy(['user' => $user]) as $t) {
                $this->em->remove($t);
            }
            foreach ($this->em->getRepository(UserSession::class)->findBy(['user' => $user]) as $s) {
                $this->em->remove($s);
            }
            $this->em->remove($user);
        }
        $this->em->flush();

        foreach ([$this->tenantA, $this->tenantB] as $tenant) {
            if (($managed = $this->em->find(Tenant::class, $tenant->getId())) !== null) {
                $this->em->remove($managed);
                $this->em->flush();
            }
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------ MFA

    #[Test]
    public function tenantAdminCannotSetupTotpForForeignTenantUser(): void
    {
        $this->client->loginUser($this->adminA);
        $this->client->request('GET', '/en/admin/mfa/user/' . $this->userB->getId() . '/setup-totp');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function tenantAdminCannotSetupTotpForSuperAdminInOwnTenant(): void
    {
        $this->client->loginUser($this->adminA);
        $this->client->request('GET', '/en/admin/mfa/user/' . $this->superA->getId() . '/setup-totp');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function tenantAdminCanSetupTotpForOwnTenantUser(): void
    {
        $this->client->loginUser($this->adminA);
        $this->client->request('GET', '/en/admin/mfa/user/' . $this->userA->getId() . '/setup-totp');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function tenantAdminCannotViewForeignOrSuperAdminToken(): void
    {
        $this->client->loginUser($this->adminA);

        $this->client->request('GET', '/en/admin/mfa/' . $this->tokenB->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request('GET', '/en/admin/mfa/' . $this->tokenSuper->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function tenantAdminCannotDisableForeignTenantToken(): void
    {
        $this->client->loginUser($this->adminA);
        $id = $this->tokenB->getId();
        $this->client->request('POST', '/en/admin/mfa/' . $id . '/disable', [
            '_token' => $this->csrf('disable_' . $id),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->em->clear();
        self::assertTrue($this->em->find(MfaToken::class, $id)->isActive());
    }

    #[Test]
    public function tenantAdminCannotDeleteOrRegenerateForeignTenantToken(): void
    {
        $this->client->loginUser($this->adminA);
        $id = $this->tokenB->getId();

        $this->client->request('POST', '/en/admin/mfa/' . $id . '/delete', [
            '_token' => $this->csrf('delete_' . $id),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request('POST', '/en/admin/mfa/' . $id . '/regenerate-backup-codes', [
            '_token' => $this->csrf('regenerate_backup_' . $id),
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request('POST', '/en/admin/mfa/' . $id . '/verify', ['code' => '123456']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->em->clear();
        self::assertNotNull($this->em->find(MfaToken::class, $id));
    }

    #[Test]
    public function tenantAdminCanDisableOwnTenantToken(): void
    {
        $this->client->loginUser($this->adminA);
        $id = $this->tokenA->getId();
        $crawler = $this->client->request('GET', '/en/admin/mfa/' . $id);
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('form[action$="/admin/mfa/' . $id . '/disable"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/en/admin/mfa/' . $id . '/disable', ['_token' => $token]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertFalse($this->em->find(MfaToken::class, $id)->isActive());
    }

    #[Test]
    public function indexExcludesForeignTenantAndSuperAdminTokens(): void
    {
        $this->client->loginUser($this->adminA);
        $this->client->request('GET', '/en/admin/mfa');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->userA->getEmail(), $html);
        self::assertStringNotContainsString($this->userB->getEmail(), $html);
        self::assertStringNotContainsString($this->superA->getEmail(), $html);
    }

    #[Test]
    public function superAdminSeesAndManagesAllTokens(): void
    {
        $this->client->loginUser($this->superA);
        $this->client->request('GET', '/en/admin/mfa');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->userA->getEmail(), $html);
        self::assertStringContainsString($this->userB->getEmail(), $html);

        $this->client->request('GET', '/en/admin/mfa/' . $this->tokenB->getId());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/en/admin/mfa/user/' . $this->userB->getId() . '/setup-totp');
        self::assertResponseIsSuccessful();
    }

    // ------------------------------------------------------------- Sessions

    #[Test]
    public function tenantAdminCannotTerminateForeignTenantUserSessions(): void
    {
        $session = $this->session($this->userB);
        $this->client->loginUser($this->adminA);
        $email = $this->userB->getEmail();
        $this->client->request('POST', '/en/admin/sessions/terminate/' . $email, [
            '_token' => $this->csrf('terminate_session_' . $email),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->em->clear();
        self::assertTrue($this->em->find(UserSession::class, $session->getId())->isActive());
    }

    #[Test]
    public function tenantAdminCannotTerminateSingleForeignTenantSession(): void
    {
        $session = $this->session($this->userB);
        $this->client->loginUser($this->adminA);
        $sid = $session->getSessionId();
        $this->client->request('POST', '/en/admin/sessions/terminate-session/' . $sid, [
            '_token' => $this->csrf('terminate_single_' . $sid),
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->em->clear();
        self::assertTrue($this->em->find(UserSession::class, $session->getId())->isActive());
    }

    #[Test]
    public function tenantAdminCannotViewForeignUserActivity(): void
    {
        $this->client->loginUser($this->adminA);
        $this->client->request('GET', '/en/admin/sessions/' . $this->userB->getEmail() . '/show');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function sessionIndexExcludesForeignTenantSessions(): void
    {
        $this->session($this->userA);
        $this->session($this->userB);
        $this->client->loginUser($this->adminA);
        $this->client->request('GET', '/en/admin/sessions');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->userA->getEmail(), $html);
        self::assertStringNotContainsString($this->userB->getEmail(), $html);
    }

    #[Test]
    public function tenantAdminCanTerminateOwnTenantUserSessions(): void
    {
        $session = $this->session($this->userA);
        $this->client->loginUser($this->adminA);
        $email = $this->userA->getEmail();
        $this->client->request('POST', '/en/admin/sessions/terminate/' . $email, [
            '_token' => $this->csrf('terminate_session_' . $email),
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertFalse($this->em->find(UserSession::class, $session->getId())->isActive());
    }

    #[Test]
    public function superAdminCanTerminateForeignTenantUserSessions(): void
    {
        $session = $this->session($this->userB);
        $this->client->loginUser($this->superA);
        $email = $this->userB->getEmail();
        $this->client->request('POST', '/en/admin/sessions/terminate/' . $email, [
            '_token' => $this->csrf('terminate_session_' . $email),
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertFalse($this->em->find(UserSession::class, $session->getId())->isActive());
    }

    #[Test]
    public function loginStatisticsAreScopedToTenantAdminsTree(): void
    {
        $this->auditLogin($this->userA, $this->tenantA);
        $this->auditLogin($this->userB, $this->tenantB);
        $this->em->flush();

        $this->client->loginUser($this->adminA);
        $this->client->request('GET', '/en/admin/sessions/statistics');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(1, $data['total_logins']);
        self::assertSame(1, $data['unique_users']);

        $this->client->loginUser($this->superA);
        $this->client->request('GET', '/en/admin/sessions/statistics');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertGreaterThanOrEqual(1, $data['unique_users']);
    }

    #[Test]
    public function tenantlessAdminGetsNoInstanceWideStatistics(): void
    {
        $this->auditLogin($this->userA, $this->tenantA);
        $this->auditLogin($this->userB, $this->tenantB);
        $this->session($this->userB);
        $tenantless = $this->user('mfa_admin_none', ['ROLE_ADMIN'], null);
        $this->em->flush();

        // The Doctrine tenant_filter is bypassed for tenantless actors, so
        // only the explicit scope keeps instance-wide counts away.
        $this->client->loginUser($tenantless);
        $this->client->request('GET', '/en/admin/sessions/statistics');
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(0, $data['total_logins']);
        self::assertSame(0, $data['unique_users']);

        $crawler = $this->client->request('GET', '/en/admin/sessions');
        self::assertResponseIsSuccessful();
        self::assertSame('0', trim($crawler->filter('p.display-6.text-primary')->first()->text()));
    }

    #[Test]
    public function sessionIndexStatisticsAreScopedToTenantAdminsTree(): void
    {
        $this->session($this->userA);
        $this->session($this->userB);
        /** @var UserSessionRepository $repo */
        $repo = $this->em->getRepository(UserSession::class);
        $scoped = $repo->getStatistics([$this->tenantA]);
        $global = $repo->getStatistics();
        self::assertSame(1, $scoped['total_active']);
        self::assertGreaterThan($scoped['total_active'], $global['total_active']);

        $this->client->loginUser($this->adminA);
        $crawler = $this->client->request('GET', '/en/admin/sessions');
        self::assertResponseIsSuccessful();
        self::assertSame('1', trim($crawler->filter('p.display-6.text-primary')->first()->text()));
    }

    #[Test]
    public function sessionListAndStatisticsShareOneQueryScope(): void
    {
        // #1059: the list was filtered in PHP after LIMIT (short pages) and
        // the statistics counted sessions the list hides (a SUPER_ADMIN of
        // the own tree cannot be administered by a tenant admin).
        $own = $this->session($this->userA);
        $own->setLastActivityAt(new \DateTimeImmutable('-10 minutes'));
        $super = $this->session($this->superA);
        $super->setLastActivityAt(new \DateTimeImmutable('-1 minute'));
        $this->em->flush();

        $this->client->loginUser($this->adminA);
        $crawler = $this->client->request('GET', '/en/admin/sessions?limit=1');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $this->userA->getEmail(), $html, 'LIMIT must apply after scoping');
        self::assertStringNotContainsString((string) $this->superA->getEmail(), $html);
        self::assertSame('1', trim($crawler->filter('p.display-6.text-primary')->first()->text()), 'statistics = listable sessions');
        self::assertSame('1', trim($crawler->filter('p.display-6.text-info')->first()->text()));

        // The instance operator still sees both (and tenantless users' sessions).
        $this->client->loginUser($this->superA);
        $this->client->request('GET', '/en/admin/sessions');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $this->userA->getEmail(), $html);
        self::assertStringContainsString((string) $this->superA->getEmail(), $html);
    }

    private function auditLogin(User $user, Tenant $tenant): void
    {
        $log = new AuditLog();
        $log->setAction('login_success');
        $log->setEntityType('User');
        $log->setEntityId($user->getId());
        $log->setUserName($user->getEmail());
        $log->setTenant($tenant);
        $this->em->persist($log);
    }

    // -------------------------------------------------------------- helpers

    /**
     * Seeds a session-stateful CSRF token into the logged-in client's session.
     */
    private function csrf(string $tokenId): string
    {
        $this->client->request('GET', '/en/admin/sessions/statistics');
        $session = $this->client->getRequest()->getSession();
        $value = bin2hex(random_bytes(16));
        $session->set('_csrf/' . $tokenId, $value);
        $session->save();

        return $value;
    }

    private function tenant(string $label): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('MfaScope ' . $label . ' ' . $this->uid);
        $tenant->setCode('mfascope_' . strtolower($label) . '_' . $this->uid);
        $tenant->setIsActive(true);
        $this->em->persist($tenant);

        return $tenant;
    }

    private function user(string $label, array $roles, ?Tenant $tenant): User
    {
        $user = new User();
        $user->setEmail($label . '_' . $this->uid . '@example.com');
        $user->setFirstName(ucfirst($label));
        $user->setLastName('Scope');
        $user->setRoles($roles);
        $user->setPassword('hashed_password');
        $user->setTenant($tenant);
        $user->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    private function token(User $user, string $device): MfaToken
    {
        $token = new MfaToken();
        $token->setUser($user);
        $token->setTokenType('totp');
        $token->setDeviceName($device);
        $token->setIsActive(true);
        $this->em->persist($token);

        return $token;
    }

    private function session(User $user): UserSession
    {
        $session = new UserSession();
        $session->setUser($user);
        $session->setSessionId('sess_' . bin2hex(random_bytes(12)));
        $session->setIsActive(true);
        $this->em->persist($session);
        $this->em->flush();

        return $session;
    }
}
