<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\IdentityProvider;
use App\Entity\IdentityProviderRoleMapping;
use App\Entity\Tenant;
use App\Repository\IdentityProviderRoleMappingRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A signed-in user re-authenticating through a global or parent-organisation
 * provider has the tenant filter armed for their own organisation. The
 * provider's role mappings carry the provider's tenant (null / parent), so
 * the filter used to hide every mapping and the role sync was skipped.
 */
final class IdentityProviderRoleMappingRepositoryTest extends KernelTestCase
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
    public function mappingsOfAGlobalAndAParentProviderAreFoundUnderAChildTenantFilter(): void
    {
        $uid = uniqid();
        $parent = (new Tenant())->setName('Parent ' . $uid)->setCode('iprm_p_' . $uid);
        $child = (new Tenant())->setName('Child ' . $uid)->setCode('iprm_c_' . $uid);
        $this->em->persist($parent);
        $this->em->persist($child);

        $global = $this->provider('glob-' . $uid, null);
        $parentProvider = $this->provider('par-' . $uid, $parent);
        $this->mapping($global, null, 'isms-admins', 10);
        $this->mapping($global, null, 'isms-inactive', 5, active: false);
        $this->mapping($parentProvider, $parent, 'parent-staff', 0);
        $this->em->flush();

        $filter = $this->em->getFilters()->enable('tenant_filter');
        $filter->setParameter('tenant_id', (string) $child->getId());
        $this->em->clear();

        $repository = self::getContainer()->get(IdentityProviderRoleMappingRepository::class);
        $globalRef = $this->em->getReference(IdentityProvider::class, $global->getId());
        $parentRef = $this->em->getReference(IdentityProvider::class, $parentProvider->getId());

        self::assertSame(
            ['isms-admins'],
            array_map(static fn (IdentityProviderRoleMapping $m): string => $m->getClaimValueExpression(), $repository->findActiveByProvider($globalRef)),
        );
        self::assertSame(
            ['parent-staff'],
            array_map(static fn (IdentityProviderRoleMapping $m): string => $m->getClaimValueExpression(), $repository->findActiveByProvider($parentRef)),
        );
        self::assertTrue($this->em->getFilters()->isEnabled('tenant_filter'), 'the tenant filter is restored afterwards');
    }

    private function provider(string $slug, ?Tenant $tenant): IdentityProvider
    {
        $provider = (new IdentityProvider())
            ->setSlug($slug)->setName($slug)->setClientId('c')->setEnabled(true)->setTenant($tenant);
        $this->em->persist($provider);

        return $provider;
    }

    private function mapping(IdentityProvider $provider, ?Tenant $tenant, string $value, int $priority, bool $active = true): void
    {
        $mapping = (new IdentityProviderRoleMapping())
            ->setIdentityProvider($provider)
            ->setTenant($tenant)
            ->setClaimKey('groups')
            ->setClaimValueExpression($value)
            ->setAssignedRole('ROLE_MANAGER')
            ->setPriority($priority)
            ->setIsActive($active);
        $this->em->persist($mapping);
    }
}
