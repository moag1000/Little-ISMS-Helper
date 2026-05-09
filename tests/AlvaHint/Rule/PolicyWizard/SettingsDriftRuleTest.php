<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\PolicyWizard;

use App\AlvaHint\Rule\PolicyWizard\SettingsDriftRule;
use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Entity\User;
use App\Repository\TenantPolicySettingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W3-B — SettingsDriftRule unit tests.
 */
#[AllowMockObjectsWithoutExpectations]
final class SettingsDriftRuleTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    #[Test]
    public function firesWhenDriftMetaSet(): void
    {
        $tenant = $this->makeTenant(2, 'Tochter');

        $setting = new TenantPolicySetting();
        $setting->setKey('crypto.minimum_key_length');
        $setting->setValue([
            'value' => 192,
            '_meta' => [
                'settings_drift_detected' => true,
                'drift_parent_value' => 256,
            ],
        ]);

        $rule = $this->makeRule([$setting]);

        $this->assertTrue($rule->appliesTo($tenant, $this->user));
    }

    #[Test]
    public function doesNotFireOnCleanTenant(): void
    {
        $tenant = $this->makeTenant(2, 'Tochter');

        $setting = new TenantPolicySetting();
        $setting->setKey('crypto.minimum_key_length');
        $setting->setValue(192); // plain scalar, no drift meta

        $rule = $this->makeRule([$setting]);

        $this->assertFalse($rule->appliesTo($tenant, $this->user));

        // Empty setting list also returns false.
        $emptyRule = $this->makeRule([]);
        $this->assertFalse($emptyRule->appliesTo($tenant, $this->user));
    }

    #[Test]
    public function actionLinkIncludesDriftKeys(): void
    {
        $tenant = $this->makeTenant(2, 'Tochter');

        $a = new TenantPolicySetting();
        $a->setKey('crypto.minimum_key_length');
        $a->setValue([
            'value' => 192,
            '_meta' => [
                'settings_drift_detected' => true,
                'drift_parent_value' => 256,
            ],
        ]);

        $b = new TenantPolicySetting();
        $b->setKey('backup.rpo_hours');
        $b->setValue([
            'value' => 24,
            '_meta' => [
                'settings_drift_detected' => true,
                'drift_parent_value' => 12,
            ],
        ]);

        // Non-drift entry should NOT appear in the action link.
        $c = new TenantPolicySetting();
        $c->setKey('risk.appetite_tier');
        $c->setValue(3);

        $rule = $this->makeRule([$a, $b, $c]);

        $hint = $rule->build($tenant, $this->user);

        $this->assertSame('policy_wizard.settings_drift', $hint->key);
        $this->assertSame(1, $hint->priorityTier);
        $this->assertFalse($hint->dismissible, 'Tier-1 hints must not be dismissible');
        $this->assertSame('app_policy_wizard_index', $hint->actionRoute);
        $this->assertSame('targeted', $hint->actionRouteParams['mode'] ?? null);

        $driftCsv = $hint->actionRouteParams['drift'] ?? '';
        $driftKeys = explode(',', $driftCsv);

        $this->assertContains('crypto.minimum_key_length', $driftKeys);
        $this->assertContains('backup.rpo_hours', $driftKeys);
        $this->assertNotContains('risk.appetite_tier', $driftKeys, 'clean settings must not leak into drift list');

        $this->assertSame('Tenant', $hint->entityType);
        $this->assertSame(2, $hint->entityId);
        $this->assertSame(['ROLE_CISO'], $hint->requiredRoles);

        $params = $hint->bodyTranslationParams;
        $this->assertSame('Tochter', $params['%konzern_name%']);
        $this->assertSame('crypto.minimum_key_length', $params['%setting_key%']);
        $this->assertSame('192', $params['%old_value%']);
        $this->assertSame('256', $params['%new_value%']);
        $this->assertSame('2', $params['%affected_count%']);
    }

    /**
     * @param list<TenantPolicySetting> $settings
     */
    private function makeRule(array $settings): SettingsDriftRule
    {
        $repo = $this->createMock(TenantPolicySettingRepository::class);
        $repo->method('findByTenant')->willReturn($settings);
        return new SettingsDriftRule($repo);
    }

    private function makeTenant(int $id, string $rootName): Tenant
    {
        $root = $this->createStub(Tenant::class);
        $root->method('getName')->willReturn($rootName);
        $root->method('getId')->willReturn($id);

        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getRootParent')->willReturn($root);
        return $stub;
    }
}
