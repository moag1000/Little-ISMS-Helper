<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Asset;
use App\Entity\Tenant;
use App\Security\TenantOwnership;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TenantOwnershipContextScopeTest extends TestCase
{
    #[Test]
    public function contextTenantAndDescendantsAreInScopeForeignAndOrphansAreNot(): void
    {
        $viewed = $this->tenant(1);
        $child = $this->tenant(2);
        $foreign = $this->tenant(3);
        $context = $this->createStub(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn($viewed);
        $context->method('canAccessTenant')->willReturnCallback(
            static fn (Tenant $t): bool => in_array($t->getId(), [1, 2], true),
        );

        self::assertTrue(TenantOwnership::isInContextScope($this->asset($viewed), $context, false));
        self::assertTrue(TenantOwnership::isInContextScope($this->asset($child), $context, false));
        self::assertFalse(TenantOwnership::isInContextScope($this->asset($foreign), $context, true), 'super admin viewing a tenant stays scoped to it');
        self::assertFalse(TenantOwnership::isInContextScope($this->asset(null), $context, false));
    }

    #[Test]
    public function withoutContextOnlySuperAdminIsInScope(): void
    {
        $context = $this->createStub(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn(null);
        $asset = $this->asset($this->tenant(1));

        self::assertFalse(TenantOwnership::isInContextScope($asset, $context, false));
        self::assertTrue(TenantOwnership::isInContextScope($asset, $context, fn (): bool => true));
        self::assertFalse(TenantOwnership::isInContextScope($asset, null, false));
    }

    private function tenant(int $id): Tenant
    {
        $t = $this->createStub(Tenant::class);
        $t->method('getId')->willReturn($id);

        return $t;
    }

    private function asset(?Tenant $tenant): Asset
    {
        $a = $this->createStub(Asset::class);
        $a->method('getTenant')->willReturn($tenant);

        return $a;
    }
}
