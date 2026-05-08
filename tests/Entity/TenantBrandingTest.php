<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Tenant;
use App\Entity\TenantBranding;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TenantBrandingTest extends TestCase
{
    #[Test]
    public function testCanInstantiate(): void
    {
        $branding = new TenantBranding();
        $tenant = new Tenant();
        $user = new User();

        $branding->setTenant($tenant)
            ->setUpdatedByUser($user)
            ->setLogoPath('/var/uploads/tenant-1/logo.png')
            ->setHeaderHtml('<header>ACME GmbH</header>')
            ->setFooterHtml('<footer>Confidential</footer>');

        $this->assertSame($tenant, $branding->getTenant());
        $this->assertSame($user, $branding->getUpdatedByUser());
        $this->assertSame('/var/uploads/tenant-1/logo.png', $branding->getLogoPath());
        $this->assertSame('<header>ACME GmbH</header>', $branding->getHeaderHtml());
        $this->assertSame('<footer>Confidential</footer>', $branding->getFooterHtml());
        $this->assertNotNull($branding->getUpdatedAt());
    }

    #[Test]
    public function testTenantScoping(): void
    {
        // TenantBranding is 1:1 with Tenant via its own tenant_id column
        // (not the shared multi-tenant pattern). Verify the relation
        // round-trips and that a different Tenant can be set.
        $branding = new TenantBranding();
        $a = new Tenant();
        $a->setName('ACME GmbH');

        $branding->setTenant($a);
        $this->assertSame($a, $branding->getTenant());

        $b = new Tenant();
        $b->setName('Tochter GmbH');
        $branding->setTenant($b);
        $this->assertSame($b, $branding->getTenant(),
            '1:1 tenant relation is overwritable in-memory; uniqueness enforced at DB');
    }

    #[Test]
    public function testDefaultsApplied(): void
    {
        $branding = new TenantBranding();

        $this->assertSame('#0d6efd', $branding->getPrimaryColor(),
            'primary color defaults to Bootstrap blue');
        $this->assertSame('#6c757d', $branding->getSecondaryColor(),
            'secondary color defaults to Bootstrap secondary grey');
        $this->assertSame('Inter', $branding->getFontFamily(),
            'font family defaults to Inter');
        $this->assertNull($branding->getLogoPath());
        $this->assertNull($branding->getHeaderHtml());
        $this->assertNull($branding->getFooterHtml());

        // Defaults can be overridden.
        $branding->setPrimaryColor('#ff5722')
            ->setSecondaryColor('#212121')
            ->setFontFamily('Roboto');
        $this->assertSame('#ff5722', $branding->getPrimaryColor());
        $this->assertSame('#212121', $branding->getSecondaryColor());
        $this->assertSame('Roboto', $branding->getFontFamily());
    }
}
