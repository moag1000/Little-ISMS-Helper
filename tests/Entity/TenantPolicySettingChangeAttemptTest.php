<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Tenant;
use App\Entity\TenantPolicySettingChangeAttempt;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TenantPolicySettingChangeAttemptTest extends TestCase
{
    #[Test]
    public function testCanInstantiate(): void
    {
        $attempt = new TenantPolicySettingChangeAttempt();
        $tenant = new Tenant();
        $user = new User();

        $attempt->setTenant($tenant)
            ->setKey('crypto.minimum_key_length')
            ->setAttemptedValue(192)
            ->setBlockedReason('floor_only_violated')
            ->setOverrideMode('floor_only')
            ->setAttemptedByUser($user);

        $this->assertSame($tenant, $attempt->getTenant());
        $this->assertSame('crypto.minimum_key_length', $attempt->getKey());
        $this->assertSame(192, $attempt->getAttemptedValue());
        $this->assertSame('floor_only_violated', $attempt->getBlockedReason());
        $this->assertSame('floor_only', $attempt->getOverrideMode());
        $this->assertSame($user, $attempt->getAttemptedByUser());
        $this->assertNotNull($attempt->getAttemptedAt());
    }

    #[Test]
    public function testTenantScoping(): void
    {
        $attempt = new TenantPolicySettingChangeAttempt();
        $tenant = new Tenant();
        $tenant->setName('Tochter GmbH');

        $attempt->setTenant($tenant);

        $this->assertSame($tenant, $attempt->getTenant());
        $this->assertNull($attempt->getTenantId(),
            'in-memory tenant has null id; getTenantId proxies safely');
    }

    #[Test]
    public function testBlockedReasonRequired(): void
    {
        // Schema-level NOT NULL on blocked_reason is enforced by the
        // migration. At the entity level, the property is typed
        // ?string and must round-trip whatever code-driven reason the
        // HierarchyOverrideValidator emits.
        $attempt = new TenantPolicySettingChangeAttempt();

        $this->assertNull($attempt->getBlockedReason(),
            'unset before write — represents pre-validator state');

        $attempt->setBlockedReason('forbidden_to_change_at_parent');
        $this->assertSame('forbidden_to_change_at_parent', $attempt->getBlockedReason());

        // The audit-log row also captures the override mode in force at
        // attempt time; both fields together describe the rejection.
        $attempt->setOverrideMode('forbidden_to_change');
        $this->assertSame('forbidden_to_change', $attempt->getOverrideMode());

        // JSON value can hold structured payloads (array) or scalars.
        $attempt->setAttemptedValue(['tier' => 5, 'note' => 'wanted to relax']);
        $this->assertSame(['tier' => 5, 'note' => 'wanted to relax'], $attempt->getAttemptedValue());
    }
}
