<?php

declare(strict_types=1);

namespace App\Tests\Controller\Trait;

use App\Controller\Trait\BulkActionTrait;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Security\TenantOwnership;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * BulkActionTrait::isInBulkContextScope() is the one tenant guard of every
 * bulk endpoint (export, assign, status change, delete): the documented
 * record scope — SUPER_ADMIN everywhere, everyone else only the tenant it
 * acts in per TenantContext, never subsidiaries.
 */
final class BulkActionTraitTenantScopeTest extends TestCase
{
    #[Test]
    public function actingOrganisationRowIsInScopeEvenAsADifferentInstance(): void
    {
        self::assertTrue($this->controller(false)->inScope($this->risk($this->tenant(1)), $this->context($this->tenant(1))));
    }

    #[Test]
    public function subsidiaryRowIsOutOfScope(): void
    {
        $holding = $this->tenant(1);

        self::assertFalse($this->controller(false)->inScope($this->risk($this->tenant(2, $holding)), $this->context($holding)));
    }

    #[Test]
    public function foreignParentAndOrphanRowsAreOutOfScope(): void
    {
        $holding = $this->tenant(1);
        $subsidiary = $this->tenant(2, $holding);
        $controller = $this->controller(false);

        self::assertFalse($controller->inScope($this->risk($this->tenant(3)), $this->context($holding)), 'foreign');
        self::assertFalse($controller->inScope($this->risk($holding), $this->context($subsidiary)), 'parent');
        self::assertFalse($controller->inScope($this->risk(null), $this->context($holding)), 'orphan');
    }

    #[Test]
    public function withoutTenantContextOnlySuperAdminIsInScope(): void
    {
        $row = $this->risk($this->tenant(2));

        self::assertFalse($this->controller(false)->inScope($row, $this->context(null)));
        self::assertFalse($this->controller(false)->inScope($row, null));
        self::assertTrue($this->controller(true)->inScope($row, $this->context(null)));
    }

    #[Test]
    public function superAdminIsGlobalEvenWithATenantContext(): void
    {
        self::assertTrue($this->controller(true)->inScope($this->risk($this->tenant(2)), $this->context($this->tenant(1))));
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

            public function inScope(object $entity, ?TenantContext $context): bool
            {
                return $this->isInBulkContextScope($entity, $context);
            }
        };
    }

    private function context(?Tenant $acting): TenantContext
    {
        $context = $this->createStub(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn($acting);
        $context->method('canAccessTenant')->willReturnCallback(
            static fn (Tenant $candidate): bool => TenantOwnership::isSameOrDescendant($acting, $candidate),
        );

        return $context;
    }

    private function risk(?Tenant $tenant): Risk
    {
        $risk = new Risk();
        $risk->setTenant($tenant);

        return $risk;
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
