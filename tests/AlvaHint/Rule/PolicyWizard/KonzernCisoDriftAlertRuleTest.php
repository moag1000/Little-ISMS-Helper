<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\PolicyWizard;

use App\AlvaHint\Rule\PolicyWizard\KonzernCisoDriftAlertRule;
use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Entity\User;
use App\Repository\TenantPolicySettingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W7-D — KonzernCisoDriftAlertRule unit tests.
 *
 * Mirrors {@see KritisOperatorReadinessRuleTest} pattern: pure unit
 * test with the TenantPolicySettingRepository mocked. Role enforcement
 * is performed by AlvaHintService (Security::isGranted) — these tests
 * only verify that the rule declares the right roles + modules.
 */
#[AllowMockObjectsWithoutExpectations]
final class KonzernCisoDriftAlertRuleTest extends TestCase
{
    private TenantPolicySettingRepository&MockObject $repository;
    private User $user;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(TenantPolicySettingRepository::class);
        $this->user = new User();
    }

    #[Test]
    public function testFiresWhenConditionsMet(): void
    {
        $tochter = $this->makeTenant(99, 'Tochter GmbH');
        $konzern = $this->makeTenantWithSubsidiaries(1, 'Konzern AG', [$tochter]);

        $this->repository->method('findByTenant')->willReturnCallback(
            function (Tenant $tenant) use ($konzern, $tochter): array {
                if ($tenant === $konzern) {
                    return [
                        $this->makeSetting('crypto.minimum_key_length', 256, 'forbidden_to_relax'),
                    ];
                }
                if ($tenant === $tochter) {
                    return [
                        $this->makeSetting('crypto.minimum_key_length', 192, 'free'),
                    ];
                }
                return [];
            }
        );

        $rule = new KonzernCisoDriftAlertRule($this->repository);
        self::assertTrue($rule->appliesTo($konzern, $this->user));
    }

    #[Test]
    public function testSkipsWhenConditionsNotMet(): void
    {
        // Konzern with no subsidiaries — rule must not fire even if
        // it carries strict-mode settings.
        $solo = $this->makeTenantWithSubsidiaries(2, 'Solo GmbH', []);
        $this->repository->method('findByTenant')->willReturn([
            $this->makeSetting('crypto.minimum_key_length', 256, 'forbidden_to_change'),
        ]);
        $solo->method('getSubsidiaries')->willReturn(new ArrayCollection([]));

        $rule = new KonzernCisoDriftAlertRule($this->repository);
        self::assertFalse($rule->appliesTo($solo, $this->user));

        // Konzern with descendants whose values match — also no drift.
        $tochter = $this->makeTenant(11, 'Tochter A');
        $konzern = $this->makeTenantWithSubsidiaries(10, 'Konzern AG', [$tochter]);

        $matchingRepo = $this->createMock(TenantPolicySettingRepository::class);
        $matchingRepo->method('findByTenant')->willReturnCallback(
            function (Tenant $tenant) use ($konzern, $tochter): array {
                if ($tenant === $konzern || $tenant === $tochter) {
                    return [
                        $this->makeSetting('crypto.minimum_key_length', 256, 'forbidden_to_relax'),
                    ];
                }
                return [];
            }
        );
        $matchingRule = new KonzernCisoDriftAlertRule($matchingRepo);
        self::assertFalse($matchingRule->appliesTo($konzern, $this->user));
    }

    #[Test]
    public function testSkipsWhenWrongRole(): void
    {
        // Role enforcement happens in AlvaHintService — verify the
        // rule's declaration contains exactly the Group-CISO role and
        // does NOT leak to plain users / auditors.
        $tochter = $this->makeTenant(99, 'Tochter');
        $konzern = $this->makeTenantWithSubsidiaries(1, 'Konzern', [$tochter]);

        $this->repository->method('findByTenant')->willReturnCallback(
            function (Tenant $tenant) use ($konzern, $tochter): array {
                if ($tenant === $konzern) {
                    return [
                        $this->makeSetting('crypto.minimum_key_length', 256, 'forbidden_to_change'),
                    ];
                }
                if ($tenant === $tochter) {
                    return [$this->makeSetting('crypto.minimum_key_length', 192, 'free')];
                }
                return [];
            }
        );

        $rule = new KonzernCisoDriftAlertRule($this->repository);
        $hint = $rule->build($konzern, $this->user);

        self::assertSame(['ROLE_GROUP_CISO'], $hint->requiredRoles);
        self::assertNotContains('ROLE_USER', $hint->requiredRoles);
        self::assertNotContains('ROLE_AUDITOR', $hint->requiredRoles);
    }

    #[Test]
    public function testSkipsWhenModuleDisabled(): void
    {
        // Module-gating happens in AlvaHintService via requiredModules().
        // Verify that the rule declares 'policy_wizard' so the service
        // skips it on tenants without the module.
        $rule = new KonzernCisoDriftAlertRule($this->repository);
        self::assertContains('policy_wizard', $rule->requiredModules());
    }

    #[Test]
    public function testRenderAndDismissTelemetry(): void
    {
        $tochter = $this->makeTenant(42, 'Tochter Süd');
        $konzern = $this->makeTenantWithSubsidiaries(7, 'Konzern AG', [$tochter]);

        $this->repository->method('findByTenant')->willReturnCallback(
            function (Tenant $tenant) use ($konzern, $tochter): array {
                if ($tenant === $konzern) {
                    return [
                        $this->makeSetting('crypto.minimum_key_length', 256, 'forbidden_to_relax'),
                    ];
                }
                if ($tenant === $tochter) {
                    return [$this->makeSetting('crypto.minimum_key_length', 128, 'free')];
                }
                return [];
            }
        );

        $rule = new KonzernCisoDriftAlertRule($this->repository);
        $hint = $rule->build($konzern, $this->user);

        self::assertSame('policy_wizard.konzern_ciso_drift_alert', $hint->key);
        self::assertSame(KonzernCisoDriftAlertRule::VERSION, $hint->version);
        self::assertSame(2, $hint->priorityTier);
        self::assertTrue($hint->dismissible, 'Tier-2 hint should be dismissible');
        self::assertSame('Tenant', $hint->entityType);
        self::assertSame(7, $hint->entityId);
        self::assertSame('alva', $hint->translationDomain);
        self::assertSame('alva_hint.konzern_ciso_drift_alert.title', $hint->titleTranslationKey);
        self::assertSame('alva_hint.konzern_ciso_drift_alert.body', $hint->bodyTranslationKey);
        self::assertSame('alva_hint.konzern_ciso_drift_alert.cta_label', $hint->actionLabelTranslationKey);
        self::assertSame('app_policy_wizard_konzern_rollup_index', $hint->actionRoute);
        self::assertSame(['tab' => 'drift'], $hint->actionRouteParams);
        self::assertSame('Konzern AG', $hint->bodyTranslationParams['%konzern_name%'] ?? null);
        self::assertSame('1', $hint->bodyTranslationParams['%affected_count%'] ?? null);
        self::assertSame('Tochter Süd', $hint->bodyTranslationParams['%first_tenant_name%'] ?? null);
        self::assertSame('crypto.minimum_key_length', $hint->bodyTranslationParams['%first_setting_key%'] ?? null);
    }

    private function makeSetting(string $key, mixed $value, string $overrideMode): TenantPolicySetting
    {
        $setting = new TenantPolicySetting();
        $setting->setKey($key);
        $setting->setValue($value);
        $setting->setOverrideMode($overrideMode);
        return $setting;
    }

    private function makeTenant(int $id, string $name): Tenant&MockObject
    {
        $stub = $this->createMock(Tenant::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getName')->willReturn($name);
        $stub->method('getSubsidiaries')->willReturn(new ArrayCollection([]));
        return $stub;
    }

    /**
     * @param list<Tenant> $subsidiaries
     */
    private function makeTenantWithSubsidiaries(int $id, string $name, array $subsidiaries): Tenant&MockObject
    {
        $stub = $this->createMock(Tenant::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getName')->willReturn($name);
        $stub->method('getSubsidiaries')->willReturn(new ArrayCollection($subsidiaries));
        return $stub;
    }
}
