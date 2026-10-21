<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\IdentityProvider;
use App\Entity\IdentityProviderRoleMapping;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Sso\OidcAuthenticationFlow;
use App\Service\Sso\SsoAccountLinkPolicy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * Security hotfix (C1): a tenant admin controls the issuer/JWKS of a
 * tenant-owned IdentityProvider and therefore every claim. Such a provider
 * must not log in as / link to accounts outside its tenant tree or
 * privileged accounts, and must never hand out a privileged role.
 * Global providers (SUPER_ADMIN-configured) keep working unchanged.
 *
 * The login paths run the real container service against the real test DB;
 * only the network part (token exchange + signature check) is skipped by
 * invoking the claim-resolution step directly.
 */
final class SsoAccountTakeoverTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uid;
    private Tenant $tenantA;
    private Tenant $childA;
    private Tenant $tenantB;
    private User $adminA;
    private User $userChildA;
    private User $userB;
    private User $super;
    private IdentityProvider $tenantProvider;
    private IdentityProvider $globalProvider;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = substr(md5(uniqid('', true)), 0, 10);

        $this->tenantA = $this->tenant('a');
        $this->childA = $this->tenant('childa');
        $this->childA->setParent($this->tenantA);
        $this->tenantB = $this->tenant('b');

        $this->adminA = $this->user('admina', ['ROLE_ADMIN'], $this->tenantA);
        $this->userChildA = $this->user('userchilda', ['ROLE_USER'], $this->childA);
        $this->userB = $this->user('userb', ['ROLE_USER'], $this->tenantB);
        $this->super = $this->user('super', ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'], $this->tenantA);

        $this->tenantProvider = $this->provider('tenant', $this->tenantA);
        $this->globalProvider = $this->provider('global', null);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach (['admina', 'userchilda', 'userb', 'super', 'jit', 'operator'] as $label) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $this->email($label)]);
            if ($user !== null) {
                $this->em->remove($user);
            }
        }
        $this->em->flush();
        foreach ([$this->tenantProvider->getId(), $this->globalProvider->getId()] as $id) {
            if ($id !== null && ($provider = $this->em->find(IdentityProvider::class, $id)) !== null) {
                foreach ($provider->getRoleMappings() as $mapping) {
                    $this->em->remove($mapping);
                }
                $this->em->remove($provider);
            }
        }
        $this->em->flush();
        $tenantIds = array_filter([$this->childA->getId(), $this->tenantA->getId(), $this->tenantB->getId()]);
        $this->em->createQuery('DELETE FROM ' . AuditLog::class . ' a WHERE a.tenant IN (:ids)')
            ->setParameter('ids', $tenantIds)
            ->execute();
        $this->em->clear();
        foreach ($tenantIds as $id) {
            if (($tenant = $this->em->find(Tenant::class, $id)) !== null) {
                $this->em->remove($tenant);
                $this->em->flush();
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function tenantProviderCannotLinkByEmailToUserOutsideItsTree(): void
    {
        $this->expectLoginRejected($this->tenantProvider, $this->email('userb'), 'sub-b');

        $userB = $this->reload($this->userB);
        self::assertNull($userB->getSsoProvider(), 'foreign account must not be linked');
        self::assertNull($userB->getSsoExternalId());
    }

    #[Test]
    public function tenantProviderCannotLinkByEmailToPrivilegedUserOfOwnTenant(): void
    {
        $this->expectLoginRejected($this->tenantProvider, $this->email('super'), 'sub-super');

        self::assertNull($this->reload($this->super)->getSsoProvider());
    }

    #[Test]
    public function tenantProviderCannotUseExistingBindingToPrivilegedUser(): void
    {
        // Binding created before the hotfix (e.g. by the takeover itself).
        $this->super->setSsoProvider($this->tenantProvider)->setSsoExternalId('sub-old');
        $this->em->flush();

        $this->expectLoginRejected($this->tenantProvider, 'whatever_' . $this->uid . '@example.com', 'sub-old');
    }

    #[Test]
    public function tenantProviderLinksUserOfChildTenantButIgnoresPrivilegedMappedRole(): void
    {
        $this->mapping($this->tenantProvider, 'ROLE_SUPER_ADMIN');

        $user = $this->resolve($this->tenantProvider, $this->email('userchilda'), 'sub-child', ['groups' => ['admins']]);

        self::assertInstanceOf(User::class, $user);
        self::assertSame($this->userChildA->getId(), $user->getId());
        $reloaded = $this->reload($this->userChildA);
        self::assertSame($this->tenantProvider->getId(), $reloaded->getSsoProvider()?->getId());
        self::assertNotContains('ROLE_SUPER_ADMIN', $reloaded->getRoles());
        self::assertSame(['ROLE_USER'], $reloaded->getStoredRoles());
    }

    #[Test]
    public function tenantProviderJitNeverCreatesPrivilegedUser(): void
    {
        $this->tenantProvider->setAutoApprove(true)->setDefaultFallbackRole('ROLE_SUPER_ADMIN');
        $this->mapping($this->tenantProvider, 'ROLE_GROUP_CISO');
        $this->em->flush();

        $user = $this->resolve($this->tenantProvider, $this->email('jit'), 'sub-jit', ['groups' => ['admins']]);

        self::assertInstanceOf(User::class, $user);
        self::assertSame([], array_intersect(
            ['ROLE_SUPER_ADMIN', 'ROLE_GROUP_CISO', 'ROLE_KONZERN_AUDITOR'],
            $this->reload($user)->getRoles(),
        ));
    }

    #[Test]
    public function globalProviderStillLinksPrivilegedUserAndAppliesMappedRole(): void
    {
        $this->mapping($this->globalProvider, 'ROLE_SUPER_ADMIN');

        $user = $this->resolve($this->globalProvider, $this->email('userb'), 'sub-global', ['groups' => ['admins']]);

        self::assertInstanceOf(User::class, $user);
        $reloaded = $this->reload($this->userB);
        self::assertSame($this->globalProvider->getId(), $reloaded->getSsoProvider()?->getId());
        self::assertContains('ROLE_SUPER_ADMIN', $reloaded->getRoles());

        $super = $this->resolve($this->globalProvider, $this->email('super'), 'sub-super-global', []);
        self::assertInstanceOf(User::class, $super);
        self::assertSame($this->super->getId(), $super->getId());
    }

    #[Test]
    public function tenantAdminCannotSavePrivilegedRoleMapping(): void
    {
        $existing = $this->mapping($this->tenantProvider, 'ROLE_MANAGER');
        $this->client->loginUser($this->adminA);

        $this->client->request('POST', $this->mappingsUrl($this->tenantProvider), [
            '_token' => $this->csrf(),
            'role_mappings' => [
                ['claimKey' => 'groups', 'claimValueExpression' => 'admins', 'assignedRole' => 'ROLE_SUPER_ADMIN', 'priority' => '1', 'isActive' => '1'],
            ],
        ]);

        self::assertResponseRedirects();
        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertNotEmpty($session->getFlashBag()->peek('error'));

        $this->em->clear();
        $mappings = $this->em->getRepository(IdentityProviderRoleMapping::class)
            ->findBy(['identityProvider' => $this->tenantProvider->getId()]);
        self::assertCount(1, $mappings, 'rejected save must leave existing mappings untouched');
        self::assertSame($existing->getId(), $mappings[0]->getId());
        self::assertSame('ROLE_MANAGER', $mappings[0]->getAssignedRole());
    }

    #[Test]
    public function tenantAdminCanStillSaveOrdinaryRoleMapping(): void
    {
        $this->client->loginUser($this->adminA);

        $this->client->request('POST', $this->mappingsUrl($this->tenantProvider), [
            '_token' => $this->csrf(),
            'role_mappings' => [
                ['claimKey' => 'groups', 'claimValueExpression' => 'mgr', 'assignedRole' => 'ROLE_MANAGER', 'priority' => '1', 'isActive' => '1'],
            ],
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $mappings = $this->em->getRepository(IdentityProviderRoleMapping::class)
            ->findBy(['identityProvider' => $this->tenantProvider->getId()]);
        self::assertCount(1, $mappings);
        self::assertSame('ROLE_MANAGER', $mappings[0]->getAssignedRole());
    }

    #[Test]
    public function superAdminCanStillSavePrivilegedRoleMappingOnGlobalProvider(): void
    {
        // Instance operator without tenant (the tenant filter hides global
        // providers from a tenant-bound session).
        $operator = $this->user('operator', ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'], null);
        $this->em->flush();
        $this->client->loginUser($operator);

        $this->client->request('POST', $this->mappingsUrl($this->globalProvider), [
            '_token' => $this->csrf(),
            'role_mappings' => [
                ['claimKey' => 'groups', 'claimValueExpression' => 'ops', 'assignedRole' => 'ROLE_SUPER_ADMIN', 'priority' => '1', 'isActive' => '1'],
            ],
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        $mappings = $this->em->getRepository(IdentityProviderRoleMapping::class)
            ->findBy(['identityProvider' => $this->globalProvider->getId()]);
        self::assertCount(1, $mappings);
        self::assertSame('ROLE_SUPER_ADMIN', $mappings[0]->getAssignedRole());
    }

    #[Test]
    public function providerPagesWarnAboutIgnoredPrivilegedRolesOfTenantProvider(): void
    {
        // Legacy / operator-created privileged mapping on a tenant-owned provider.
        $this->mapping($this->tenantProvider, 'ROLE_GROUP_CISO');
        $this->tenantProvider->setDefaultFallbackRole('ROLE_SUPER_ADMIN');
        $this->em->flush();
        $this->client->loginUser($this->adminA);

        foreach (['', '/edit'] as $suffix) {
            $crawler = $this->client->request('GET', '/en/admin/sso/' . $this->tenantProvider->getId() . $suffix);
            self::assertResponseIsSuccessful();
            $alert = $crawler->filter('.fa-alert--warning');
            self::assertGreaterThan(0, $alert->count(), 'warning missing on ' . ($suffix ?: 'show'));
            self::assertStringContainsString('ROLE_GROUP_CISO', $alert->text());
            self::assertStringContainsString('ROLE_SUPER_ADMIN', $alert->text());
        }
    }

    #[Test]
    public function ignoredPrivilegedRolesIsEmptyForGlobalProviderAndOrdinaryRoles(): void
    {
        $privileged = $this->mapping($this->globalProvider, 'ROLE_SUPER_ADMIN');
        $ordinary = $this->mapping($this->tenantProvider, 'ROLE_MANAGER');

        self::assertSame([], SsoAccountLinkPolicy::ignoredPrivilegedRoles($this->globalProvider, [$privileged]));
        self::assertSame([], SsoAccountLinkPolicy::ignoredPrivilegedRoles($this->tenantProvider, [$ordinary]));
        self::assertSame(
            ['ROLE_SUPER_ADMIN'],
            SsoAccountLinkPolicy::ignoredPrivilegedRoles($this->tenantProvider, [$privileged]),
        );
        $privileged->setIsActive(false);
        self::assertSame([], SsoAccountLinkPolicy::ignoredPrivilegedRoles($this->tenantProvider, [$privileged]));
    }

    #[Test]
    public function providerWithoutEmailLinkingRefusesLoginToExistingUnlinkedAccount(): void
    {
        foreach ([$this->tenantProvider, $this->globalProvider] as $provider) {
            $provider->setAllowEmailLinking(false);
        }
        $this->em->flush();

        foreach ([$this->tenantProvider, $this->globalProvider] as $provider) {
            try {
                $this->resolve($provider, $this->email('userchilda'), 'sub-nolink', []);
                self::fail('Login must be refused when link by e-mail is disabled.');
            } catch (CustomUserMessageAuthenticationException $e) {
                self::assertSame(SsoAccountLinkPolicy::EMAIL_LINKING_DISABLED_MESSAGE, $e->getMessageKey());
            }
        }
        $reloaded = $this->reload($this->userChildA);
        self::assertNull($reloaded->getSsoProvider(), 'account must not be linked');
        self::assertNull($reloaded->getSsoExternalId());
    }

    #[Test]
    public function providerWithoutEmailLinkingKeepsExistingBindingAndJit(): void
    {
        $this->tenantProvider->setAllowEmailLinking(false)->setAutoApprove(true);
        $this->userChildA->setSsoProvider($this->tenantProvider)->setSsoExternalId('sub-bound');
        $this->em->flush();

        $bound = $this->resolve($this->tenantProvider, $this->email('userchilda'), 'sub-bound', []);
        self::assertInstanceOf(User::class, $bound);
        self::assertSame($this->userChildA->getId(), $bound->getId());

        $jit = $this->resolve($this->tenantProvider, $this->email('jit'), 'sub-jit-nolink', []);
        self::assertInstanceOf(User::class, $jit, 'new e-mail addresses are still provisioned');
    }

    #[Test]
    public function accountBoundToAnotherProviderIsNotReboundByEmail(): void
    {
        // #1059 review: linking by e-mail only takes unlinked accounts; an
        // existing binding to another provider is never moved silently.
        $this->userChildA->setSsoProvider($this->globalProvider)->setSsoExternalId('sub-global');
        $this->em->flush();

        try {
            $this->resolve($this->tenantProvider, $this->email('userchilda'), 'sub-other', []);
            self::fail('re-binding to another provider must be refused');
        } catch (CustomUserMessageAuthenticationException $e) {
            self::assertSame(SsoAccountLinkPolicy::EMAIL_LINKING_DISABLED_MESSAGE, $e->getMessageKey());
        }
        self::assertSame($this->globalProvider->getId(), $this->reload($this->userChildA)->getSsoProvider()?->getId());
    }

    #[Test]
    public function newProviderDoesNotLinkByEmailByDefault(): void
    {
        self::assertFalse((new IdentityProvider())->isAllowEmailLinking());
    }

    // -------------------------------------------------------------- helpers

    private function expectLoginRejected(IdentityProvider $provider, string $email, string $sub): void
    {
        try {
            $this->resolve($provider, $email, $sub, []);
            self::fail('Login must be rejected.');
        } catch (AuthenticationException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param array<string,mixed> $extraClaims
     */
    private function resolve(IdentityProvider $provider, string $email, string $sub, array $extraClaims): mixed
    {
        $flow = static::getContainer()->get(OidcAuthenticationFlow::class);
        $provider = $this->em->find(IdentityProvider::class, $provider->getId()) ?? $provider;
        $method = new \ReflectionMethod(OidcAuthenticationFlow::class, 'resolveUserFromClaims');

        return $method->invoke($flow, $provider, ['email' => $email, 'sub' => $sub, ...$extraClaims]);
    }

    private function csrf(): string
    {
        $this->client->request('GET', '/en/admin/sso');
        $session = $this->client->getRequest()->getSession();
        $value = bin2hex(random_bytes(16));
        $session->set('_csrf/sso_role_mappings', $value);
        $session->save();

        return $value;
    }

    private function mappingsUrl(IdentityProvider $provider): string
    {
        return '/en/admin/sso/' . $provider->getId() . '/role-mappings';
    }

    private function mapping(IdentityProvider $provider, string $role): IdentityProviderRoleMapping
    {
        $mapping = (new IdentityProviderRoleMapping())
            ->setIdentityProvider($provider)
            ->setTenant($provider->getTenant())
            ->setClaimKey('groups')
            ->setClaimValueExpression('admins')
            ->setAssignedRole($role)
            ->setPriority(1)
            ->setIsActive(true);
        $this->em->persist($mapping);
        $this->em->flush();

        return $mapping;
    }

    private function provider(string $label, ?Tenant $tenant): IdentityProvider
    {
        $provider = (new IdentityProvider())
            ->setSlug('tk-' . $label[0] . $this->uid)
            ->setName('Takeover ' . $label)
            ->setClientId('client-' . $label)
            ->setTenant($tenant)
            ->setJitProvisioning(true)
            ->setAutoApprove(false)
            // Providers that predate the option keep linking by e-mail
            // (migration default); the opt-out is tested separately.
            ->setAllowEmailLinking(true)
            ->setAttributeMap(['email' => 'email']);
        $this->em->persist($provider);

        return $provider;
    }

    private function tenant(string $label): Tenant
    {
        $tenant = (new Tenant())->setName('SsoTakeover ' . $label . ' ' . $this->uid)->setCode('ssotk_' . $label . '_' . $this->uid);
        $this->em->persist($tenant);

        return $tenant;
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $label, array $roles, ?Tenant $tenant): User
    {
        $user = (new User())
            ->setEmail($this->email($label))
            ->setFirstName(ucfirst($label))
            ->setLastName('Takeover')
            ->setRoles($roles)
            ->setPassword('hashed_password')
            ->setTenant($tenant)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }

    private function email(string $label): string
    {
        return $label . '_' . $this->uid . '@example.com';
    }

    private function reload(User $user): User
    {
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }
}
