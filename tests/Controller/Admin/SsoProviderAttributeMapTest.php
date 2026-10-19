<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\IdentityProvider;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The claim-mapping builder must be clearable: a field that was submitted
 * empty wipes the map, a field absent from the request keeps it.
 */
final class SsoProviderAttributeMapTest extends WebTestCase
{
    private string $slug = '';
    private ?int $providerId = null;
    private ?KernelBrowser $browser = null;

    protected function tearDown(): void
    {
        if ($this->browser !== null && $this->providerId !== null) {
            $em = $this->browser->getContainer()->get(EntityManagerInterface::class);
            $em->clear();
            $provider = $em->find(IdentityProvider::class, $this->providerId);
            if ($provider !== null) {
                $em->remove($provider);
                $em->flush();
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function emptySubmittedBuilderClearsTheAttributeMap(): void
    {
        [$client, $id] = $this->boot(['mail' => 'email']);

        $client->request('POST', '/de/admin/sso/' . $id . '/edit', ['identity_provider' => $this->payload() + ['attributeMapJson' => '']]);

        self::assertResponseRedirects();
        self::assertSame([], $this->reload($client, $id)->getAttributeMap());
    }

    #[Test]
    public function absentBuilderFieldKeepsTheAttributeMap(): void
    {
        [$client, $id] = $this->boot(['mail' => 'email']);

        $client->request('POST', '/de/admin/sso/' . $id . '/edit', ['identity_provider' => $this->payload()]);

        self::assertResponseRedirects();
        self::assertSame(['mail' => 'email'], $this->reload($client, $id)->getAttributeMap());
    }

    #[Test]
    public function submittedBuilderReplacesTheAttributeMap(): void
    {
        [$client, $id] = $this->boot(['mail' => 'email']);

        $client->request('POST', '/de/admin/sso/' . $id . '/edit', ['identity_provider' => $this->payload()
            + ['attributeMapJson' => json_encode(['upn' => 'email'])]]);

        self::assertResponseRedirects();
        self::assertSame(['upn' => 'email'], $this->reload($client, $id)->getAttributeMap());
    }

    /**
     * Posted by hand: the edit page nests the "discover" form inside the
     * provider form, so a crawler-built form drops the trailing fields.
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            '_token' => 'csrf-token',
            'slug' => $this->slug,
            'name' => 'Map IdP',
            'type' => IdentityProvider::TYPE_OIDC,
            'clientId' => 'cid',
            'scopesCsv' => 'openid profile email',
            'domainBindingsCsv' => '',
            'domainBindingMode' => 'optional',
            'defaultRole' => 'ROLE_USER',
        ];
    }

    /**
     * @param array<string, string> $map
     * @return array{0: KernelBrowser, 1: int}
     */
    private function boot(array $map): array
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => 'SSOMAP-FIXED']);
        if ($tenant === null) {
            $tenant = (new Tenant())->setName('SsoMapTest')->setCode('SSOMAP-FIXED');
            $em->persist($tenant);
        }
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'sso-map-admin@test.test']);
        if ($user === null) {
            $user = (new User())
                ->setEmail('sso-map-admin@test.test')
                ->setPassword('$2y$13$fake_hashed_password_sso_map_000')
                ->setRoles(['ROLE_ADMIN'])
                ->setTenant($tenant)
                ->setFirstName('Sso')
                ->setLastName('Map');
            $em->persist($user);
        }
        $provider = (new IdentityProvider())
            ->setTenant($tenant)
            ->setSlug($this->slug = 'map-' . bin2hex(random_bytes(4)))
            ->setName('Map IdP')
            ->setType(IdentityProvider::TYPE_OIDC)
            ->setClientId('cid')
            ->setAttributeMap($map);
        $em->persist($provider);
        $em->flush();

        $this->browser = $client;
        $this->providerId = (int) $provider->getId();
        $client->loginUser($user);
        // Stateless CSRF (token id `submit`): the rendered value is the
        // double-submit marker, validated by same-origin headers.
        $client->setServerParameter('HTTP_SEC_FETCH_SITE', 'same-origin');

        return [$client, (int) $provider->getId()];
    }

    private function reload(KernelBrowser $client, int $id): IdentityProvider
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $provider = $em->find(IdentityProvider::class, $id);
        self::assertInstanceOf(IdentityProvider::class, $provider);

        return $provider;
    }
}
