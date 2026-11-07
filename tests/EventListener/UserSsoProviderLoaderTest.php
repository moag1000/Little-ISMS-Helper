<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\IdentityProvider;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An account bound to a parent-organisation provider: the tenant filter of
 * its own organisation hides that provider, and initialising the lazy proxy
 * later ended in an EntityNotFoundException (500 on every page, reauth modal
 * in base.html.twig). Global providers are shared rows and stay readable.
 */
final class UserSsoProviderLoaderTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $filters = $this->em->getFilters();
        if ($filters->isEnabled('tenant_filter')) {
            $filters->disable('tenant_filter');
        }
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    #[Test]
    public function aGlobalAndAParentProviderBindingStaysReadableUnderTheAccountsTenantFilter(): void
    {
        $uid = uniqid();
        $parent = (new Tenant())->setName('Parent ' . $uid)->setCode('usp_p_' . $uid);
        $child = (new Tenant())->setName('Child ' . $uid)->setCode('usp_c_' . $uid);
        $child->setParent($parent);
        $this->em->persist($parent);
        $this->em->persist($child);
        $global = $this->provider('glob-' . $uid, null);
        $parentProvider = $this->provider('par-' . $uid, $parent);
        $globalUser = $this->user($child, $global, 'sub-g');
        $parentUser = $this->user($child, $parentProvider, 'sub-p');
        $this->em->flush();

        $filter = $this->em->getFilters()->enable('tenant_filter');
        $filter->setParameter('tenant_id', (string) $child->getId());
        $this->em->clear();

        $users = $this->em->getRepository(User::class);
        self::assertSame('glob-' . $uid, $users->find($globalUser->getId())?->getSsoProvider()?->getSlug());
        self::assertSame('par-' . $uid, $users->find($parentUser->getId())?->getSsoProvider()?->getSlug());
        self::assertTrue($this->em->getFilters()->isEnabled('tenant_filter'), 'the tenant filter is restored afterwards');
        self::assertSame(
            [],
            $this->em->createQuery('SELECT p.id FROM ' . IdentityProvider::class . ' p WHERE p.id = :id')
                ->setParameter('id', $parentProvider->getId())
                ->getScalarResult(),
            'the parent provider itself stays hidden from the child organisation\'s queries',
        );
    }

    private function provider(string $slug, ?Tenant $tenant): IdentityProvider
    {
        $provider = (new IdentityProvider())
            ->setSlug($slug)->setName($slug)->setClientId('c')->setEnabled(true)->setTenant($tenant);
        $this->em->persist($provider);

        return $provider;
    }

    private function user(Tenant $tenant, IdentityProvider $provider, string $subject): User
    {
        $user = new User();
        $user->setEmail('usp_' . uniqid('', true) . '@example.com');
        $user->setFirstName('Sso');
        $user->setLastName('User');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('hashed_password');
        $user->setTenant($tenant);
        $user->setIsActive(true);
        $user->setSsoProvider($provider)->setSsoExternalId($subject);
        $this->em->persist($user);

        return $user;
    }
}
