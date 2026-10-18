<?php

declare(strict_types=1);

namespace App\Tests\Controller\Trait;

use App\Controller\Trait\BulkActionTrait;
use App\Entity\Risk;
use App\Entity\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * BulkActionTrait::isInBulkTenantScope() guards every bulk export / assign
 * loop. It replaces `if ($tenant !== null && $e->getTenant() !== $tenant)`,
 * which exported every tenant's rows when the actor had no tenant and
 * compared Doctrine proxies by identity.
 */
final class BulkActionTraitTenantScopeTest extends TestCase
{
    #[Test]
    public function ownTenantRowIsInScopeEvenAsADifferentInstance(): void
    {
        $proxyOfSameTenant = $this->tenant(1);
        $row = $this->risk($proxyOfSameTenant);

        self::assertTrue($this->controller(false)->inScope($row, $this->tenant(1)));
    }

    #[Test]
    public function foreignTenantRowIsOutOfScope(): void
    {
        self::assertFalse($this->controller(false)->inScope($this->risk($this->tenant(2)), $this->tenant(1)));
    }

    #[Test]
    public function rowWithoutTenantIsOutOfScope(): void
    {
        self::assertFalse($this->controller(false)->inScope($this->risk(null), $this->tenant(1)));
    }

    #[Test]
    public function actorWithoutTenantSeesNothing(): void
    {
        self::assertFalse($this->controller(false)->inScope($this->risk($this->tenant(2)), null));
    }

    #[Test]
    public function superAdminWithoutTenantIsTheOnlyAllTenantCase(): void
    {
        self::assertTrue($this->controller(true)->inScope($this->risk($this->tenant(2)), null));
        // With a tenant context even SUPER_ADMIN stays inside it.
        self::assertFalse($this->controller(true)->inScope($this->risk($this->tenant(2)), $this->tenant(1)));
    }

    private function controller(bool $isSuperAdmin): object
    {
        return new class ($isSuperAdmin) extends AbstractController {
            use BulkActionTrait;

            public function __construct(private readonly bool $isSuperAdmin)
            {
            }

            protected function isGranted(mixed $attribute, mixed $subject = null): bool
            {
                return $attribute === 'ROLE_SUPER_ADMIN' && $this->isSuperAdmin;
            }

            public function inScope(object $entity, ?Tenant $tenant): bool
            {
                return $this->isInBulkTenantScope($entity, $tenant);
            }
        };
    }

    private function risk(?Tenant $tenant): Risk
    {
        $risk = new Risk();
        $risk->setTenant($tenant);

        return $risk;
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        new ReflectionProperty(Tenant::class, 'id')->setValue($tenant, $id);

        return $tenant;
    }
}
