<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\IdentityProvider;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\GenericOidcAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * #1059 I-5: slugs are unique per organisation only. Organisation B
 * registering organisation A's slug must never steer A's users (or anyone)
 * to B's identity provider: start URLs carry the provider id, the legacy
 * slug-only URL is served only when the slug is unambiguous, the callback
 * uses the provider chosen at start, and a tenant provider cannot take a
 * global provider's slug.
 */
final class SsoSlugCollisionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uid;
    private string $slug;
    private Tenant $tenantA;
    private Tenant $tenantB;
    private IdentityProvider $providerA;
    private IdentityProvider $providerB;
    private User $userA;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = substr(md5(uniqid('', true)), 0, 10);
        $this->slug = 'entra-' . $this->uid;

        $this->tenantA = (new Tenant())->setName('Slug A ' . $this->uid)->setCode('sluga_' . $this->uid);
        $this->tenantB = (new Tenant())->setName('Slug B ' . $this->uid)->setCode('slugb_' . $this->uid);
        $this->em->persist($this->tenantA);
        $this->em->persist($this->tenantB);
        // B registered A's slug first (created first → lowest id).
        $this->providerB = $this->provider($this->tenantB, 'https://idp-b.example/authorize');
        $this->providerA = $this->provider($this->tenantA, 'https://idp-a.example/authorize');
        $this->userA = (new User())
            ->setEmail('slug_a_' . $this->uid . '@example.com')
            ->setFirstName('Slug')->setLastName('A')
            ->setRoles(['ROLE_USER'])->setPassword('hashed_password')
            ->setTenant($this->tenantA)->setIsActive(true)
            ->setSsoProvider($this->providerA)->setSsoExternalId('sub-a');
        $this->em->persist($this->userA);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM users WHERE email = ?', [$this->userA->getEmail()]);
        $conn->executeStatement('DELETE FROM identity_provider WHERE slug LIKE ?', ['%' . $this->uid]);
        $conn->executeStatement('DELETE FROM tenant WHERE code IN (?, ?)', ['sluga_' . $this->uid, 'slugb_' . $this->uid]);
        parent::tearDown();
    }

    #[Test]
    public function ambiguousLegacySlugIsRefusedWithoutReachingAnyIdp(): void
    {
        $this->client->request('GET', '/sso/' . $this->slug . '/start');

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('idp-b.example', $location);
        self::assertStringNotContainsString('idp-a.example', $location);
    }

    #[Test]
    public function providerIdSelectsExactlyThatProvider(): void
    {
        $this->client->request('GET', '/sso/' . $this->slug . '/start?provider=' . $this->providerA->getId());
        self::assertStringStartsWith(
            'https://idp-a.example/authorize',
            (string) $this->client->getResponse()->headers->get('Location'),
        );

        // An id whose slug does not match the path is refused.
        $this->client->request('GET', '/sso/other-' . $this->uid . '/start?provider=' . $this->providerB->getId());
        self::assertStringNotContainsString(
            'idp-b.example',
            (string) $this->client->getResponse()->headers->get('Location'),
        );
    }

    #[Test]
    public function unambiguousLegacySlugStillWorks(): void
    {
        $this->providerB->setEnabled(false);
        $this->em->flush();

        $this->client->request('GET', '/sso/' . $this->slug . '/start');
        self::assertStringStartsWith(
            'https://idp-a.example/authorize',
            (string) $this->client->getResponse()->headers->get('Location'),
        );
    }

    #[Test]
    public function enforcedLoginRedirectCarriesTheProviderId(): void
    {
        $this->tenantA->setSsoEnforced(true);
        $this->em->flush();

        try {
            $this->client->request('POST', '/en/login', ['_username' => $this->userA->getEmail(), '_password' => 'x']);
            $location = (string) $this->client->getResponse()->headers->get('Location');
            self::assertStringContainsString('/sso/' . $this->slug . '/start', $location);
            self::assertStringContainsString('provider=' . $this->providerA->getId(), $location);

            $this->client->request('GET', $location);
            self::assertStringStartsWith(
                'https://idp-a.example/authorize',
                (string) $this->client->getResponse()->headers->get('Location'),
            );
        } finally {
            $this->em->clear();
            $this->em->find(Tenant::class, $this->tenantA->getId())?->setSsoEnforced(false);
            $this->em->flush();
        }
    }

    #[Test]
    public function tenantProviderCannotTakeAGlobalProvidersSlug(): void
    {
        $global = (new IdentityProvider())
            ->setSlug('global-' . $this->uid)->setName('Global ' . $this->uid)->setClientId('c')->setEnabled(true);
        $this->em->persist($global);
        $this->em->flush();
        $validator = static::getContainer()->get(ValidatorInterface::class);

        $squatter = (new IdentityProvider())
            ->setSlug('global-' . $this->uid)->setName('Squat ' . $this->uid)->setClientId('c')->setTenant($this->tenantB);
        $violations = $validator->validate($squatter);
        $paths = [];
        foreach ($violations as $violation) {
            $paths[] = $violation->getPropertyPath() . ':' . $violation->getMessageTemplate();
        }
        self::assertContains('slug:sso.slug_used_by_global_provider', $paths);

        $ok = (new IdentityProvider())
            ->setSlug('own-' . $this->uid)->setName('Own ' . $this->uid)->setClientId('c')->setTenant($this->tenantB);
        foreach ($validator->validate($ok) as $violation) {
            self::assertNotSame('sso.slug_used_by_global_provider', $violation->getMessageTemplate());
        }
    }

    #[Test]
    public function startStoresOnlySameOriginRelativeTargets(): void
    {
        // #1059 m-4: no open redirect through ?target=, and a new start never
        // inherits an older target.
        $base = '/sso/' . $this->slug . '/start?provider=' . $this->providerA->getId();
        $cases = [
            'https://evil.example/x' => null,
            '//evil.example/x' => null,
            '/\\evil.example/x' => null,
            'javascript:alert(1)' => null,
            '/en/dashboard?tab=1' => '/en/dashboard?tab=1',
        ];
        foreach ($cases as $target => $expected) {
            $this->client->request('GET', $base . '&target=' . rawurlencode($target));
            self::assertSame(
                $expected,
                $this->client->getRequest()->getSession()->get(GenericOidcAuthenticator::SESSION_TARGET_KEY),
                'target ' . $target,
            );
        }

        // Valid target stored above; a start without target clears it.
        $this->client->request('GET', $base);
        self::assertNull($this->client->getRequest()->getSession()->get(GenericOidcAuthenticator::SESSION_TARGET_KEY));
    }

    #[Test]
    public function loggedInUserReauthenticatesThroughGlobalButNotForeignProvider(): void
    {
        // #1059 m-6: the tenant filter hid global / parent providers from a
        // logged-in user; foreign organisations' providers stay refused.
        $global = (new IdentityProvider())
            ->setSlug('glob-' . $this->uid)->setName('Glob ' . $this->uid)->setClientId('c')->setEnabled(true)
            ->setAuthorizationEndpoint('https://idp-global.example/authorize')
            ->setTokenEndpoint('https://idp-global.example/token');
        $this->em->persist($global);
        $this->em->flush();
        $this->em->clear(); // the request must load the provider through the filtered EM

        $this->client->loginUser($this->userA);
        $this->client->request('GET', '/sso/glob-' . $this->uid . '/start?provider=' . $global->getId());
        self::assertStringStartsWith(
            'https://idp-global.example/authorize',
            (string) $this->client->getResponse()->headers->get('Location'),
        );

        $this->client->request('GET', '/sso/' . $this->slug . '/start?provider=' . $this->providerB->getId());
        self::assertStringNotContainsString(
            'idp-b.example',
            (string) $this->client->getResponse()->headers->get('Location'),
        );
    }

    private function provider(Tenant $tenant, string $authorizeUrl): IdentityProvider
    {
        $provider = (new IdentityProvider())
            ->setSlug($this->slug)
            ->setName('Slug ' . $tenant->getCode())
            ->setClientId('client-' . $tenant->getCode())
            ->setTenant($tenant)
            ->setEnabled(true)
            ->setAllowEmailLinking(true)
            ->setAuthorizationEndpoint($authorizeUrl)
            ->setTokenEndpoint(str_replace('/authorize', '/token', $authorizeUrl))
            ->setAttributeMap(['email' => 'email']);
        $this->em->persist($provider);
        $this->em->flush();

        return $provider;
    }
}
