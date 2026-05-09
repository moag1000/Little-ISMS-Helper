<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TenantPolicySettingTest extends TestCase
{
    #[Test]
    public function testCanInstantiate(): void
    {
        $setting = new TenantPolicySetting();
        $tenant = new Tenant();
        $user = new User();

        $setting->setTenant($tenant)
            ->setKey('isms.scope_statement')
            ->setValue(['statement' => 'Headquarters in Berlin only'])
            ->setUpdatedByUser($user);

        $this->assertSame($tenant, $setting->getTenant());
        $this->assertSame('isms.scope_statement', $setting->getKey());
        $this->assertSame(['statement' => 'Headquarters in Berlin only'], $setting->getValue());
        $this->assertSame($user, $setting->getUpdatedByUser());
        $this->assertSame('free', $setting->getOverrideMode(), 'default override mode is free');
        $this->assertNull($setting->getInheritedFromTenant());
        $this->assertNotNull($setting->getUpdatedAt());
    }

    #[Test]
    public function testTenantScoping(): void
    {
        $setting = new TenantPolicySetting();
        $tenant = new Tenant();
        $tenant->setName('Tochter GmbH');

        $setting->setTenant($tenant);

        $this->assertSame($tenant, $setting->getTenant());
        $this->assertNull($setting->getTenantId(),
            'in-memory tenant has null id, getTenantId proxies safely');
    }

    #[Test]
    public function testOverrideModeStored(): void
    {
        $setting = new TenantPolicySetting();

        // Walk through every architecturally-valid override mode and
        // verify it round-trips. The validator service enforces that the
        // value is in this set; the entity itself is permissive.
        foreach (['forbidden_to_change', 'forbidden_to_relax', 'floor_only', 'ceiling_only', 'free'] as $mode) {
            $setting->setOverrideMode($mode);
            $this->assertSame($mode, $setting->getOverrideMode());
        }

        // Inherited-from chain: subsidiary inherits from parent tenant.
        $parent = new Tenant();
        $parent->setName('Konzern AG');
        $setting->setInheritedFromTenant($parent);
        $this->assertSame($parent, $setting->getInheritedFromTenant());

        // Clearing returns to own-value.
        $setting->setInheritedFromTenant(null);
        $this->assertNull($setting->getInheritedFromTenant());
    }
}
