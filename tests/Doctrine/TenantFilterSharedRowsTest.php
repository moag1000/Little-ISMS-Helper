<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\TenantFilter;
use App\Entity\IdentityProvider;
use App\Entity\Risk;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A global identity provider (tenant = NULL) serves every organisation, and the
 * repository queries already treat it that way. The tenant filter must agree,
 * or Doctrine cannot initialise the lazy proxy a user's `ssoProvider` points
 * at and every page fails with EntityNotFoundException (#1086).
 */
final class TenantFilterSharedRowsTest extends TestCase
{
    private function filterScopedTo(string $tenantId): TenantFilter
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quote')->willReturnCallback(static fn (string $value): string => '\''.$value.'\'');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $filter = new TenantFilter($em);
        $filter->setParameter('tenant_id', $tenantId);

        return $filter;
    }

    /**
     * @param class-string $entity
     */
    private function metadataFor(string $entity): ClassMetadata
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getReflectionClass')->willReturn(new \ReflectionClass($entity));
        $metadata->method('hasAssociation')->with('tenant')->willReturn(true);
        $metadata->method('getAssociationMapping')->with('tenant')
            ->willReturn(new ManyToOneAssociationMapping('tenant', $entity, 'App\Entity\Tenant'));

        return $metadata;
    }

    #[Test]
    public function theGlobalIdentityProviderStaysVisibleToAScopedTenant(): void
    {
        $constraint = $this->filterScopedTo('1')->addFilterConstraint(
            $this->metadataFor(IdentityProvider::class),
            'i',
        );

        $this->assertSame("(i.tenant_id = '1' OR i.tenant_id IS NULL)", $constraint);
    }

    #[Test]
    public function anOrganisationOnlyEntityKeepsTheStrictScope(): void
    {
        $constraint = $this->filterScopedTo('1')->addFilterConstraint(
            $this->metadataFor(Risk::class),
            'r',
        );

        $this->assertSame("r.tenant_id = '1'", $constraint);
    }
}
