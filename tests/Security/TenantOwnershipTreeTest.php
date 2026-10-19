<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Tenant;
use App\Security\TenantOwnership;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * TenantOwnership::isSameOrDescendant() is the one holding-tree comparison
 * behind TenantContext::canAccessTenant() (organisation administration, bulk
 * context scope) and the holding read of HoldingTreeAccessTrait.
 */
final class TenantOwnershipTreeTest extends TestCase
{
    #[Test]
    public function rootAndEveryDescendantAreInTheTreeParentsAndSiblingsAreNot(): void
    {
        $holding = $this->tenant(1);
        $subsidiary = $this->tenant(2, $holding);
        $grandchild = $this->tenant(3, $subsidiary);
        $sibling = $this->tenant(4, $holding);

        self::assertTrue(TenantOwnership::isSameOrDescendant($holding, $holding));
        self::assertTrue(TenantOwnership::isSameOrDescendant($holding, $subsidiary));
        self::assertTrue(TenantOwnership::isSameOrDescendant($holding, $grandchild));
        self::assertFalse(TenantOwnership::isSameOrDescendant($subsidiary, $holding), 'never up');
        self::assertFalse(TenantOwnership::isSameOrDescendant($subsidiary, $sibling), 'never sideways');
    }

    #[Test]
    public function ancestorsAreComparedByIdLikeTheOwnTenantCheck(): void
    {
        // A second instance of the holding (detached copy / Doctrine proxy)
        // used to fail the identity-only Tenant::isChildOf() walk.
        $holding = $this->tenant(1);
        $subsidiary = $this->tenant(2, $holding);

        self::assertTrue(TenantOwnership::isSameOrDescendant($this->tenant(1), $subsidiary));
    }

    #[Test]
    public function failsClosed(): void
    {
        $tenant = $this->tenant(1);

        self::assertFalse(TenantOwnership::isSameOrDescendant(null, $tenant));
        self::assertFalse(TenantOwnership::isSameOrDescendant($tenant, null));
        self::assertFalse(TenantOwnership::isSameOrDescendant(new Tenant(), new Tenant()), 'unsaved tenants never match');
    }

    private function tenant(int $id, ?Tenant $parent = null): Tenant
    {
        $tenant = new Tenant();
        new ReflectionProperty(Tenant::class, 'id')->setValue($tenant, $id);
        if ($parent instanceof Tenant) {
            $tenant->setParent($parent);
        }

        return $tenant;
    }
}
