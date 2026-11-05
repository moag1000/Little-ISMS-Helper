<?php

declare(strict_types=1);

namespace App\Tests\Service\TenantSettingResolver;

use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Repository\SystemSettingsRepository;
use App\Repository\TenantPolicySettingRepository;
use App\Service\TenantSettingResolver\OverrideMode;
use App\Service\TenantSettingResolver\TenantPolicySettingProvider;
use App\Service\TenantSettingResolver\TenantSettingResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class TenantPolicySettingProviderTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $stored tenantId => key => value
     */
    private function makeProvider(array $stored, mixed $globalValue = null): TenantPolicySettingProvider
    {
        $repo = $this->createStub(TenantPolicySettingRepository::class);
        $repo->method('findOneByTenantAndKey')->willReturnCallback(
            static function (Tenant $tenant, string $key) use ($stored): ?TenantPolicySetting {
                if (!array_key_exists($key, $stored[$tenant->getId()] ?? [])) {
                    return null;
                }
                return (new TenantPolicySetting())->setValue($stored[$tenant->getId()][$key]);
            },
        );
        $system = $this->createStub(SystemSettingsRepository::class);
        $system->method('getSetting')->willReturnCallback(
            static fn (string $category, string $key, mixed $default = null): mixed => $globalValue ?? $default,
        );

        return new TenantPolicySettingProvider($repo, $system);
    }

    private function makeTenant(int $id, array $ancestors = []): Tenant
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getAllAncestors')->willReturn($ancestors);
        return $tenant;
    }

    #[Test]
    public function konzernValueLimitsSubsidiary(): void
    {
        $konzern = $this->makeTenant(1);
        $tochter = $this->makeTenant(2, [$konzern]);
        $resolver = new TenantSettingResolver($this->makeProvider([
            1 => ['crypto.minimum_key_length' => 3072],
            2 => ['crypto.minimum_key_length' => 2048],
        ]));

        $result = $resolver->resolveFor($tochter, 'crypto.minimum_key_length');

        self::assertSame(3072, $result->value);
        self::assertTrue($result->childRelaxBlocked);
    }

    #[Test]
    public function driftMetaIsStrippedFromStoredValue(): void
    {
        $tenant = $this->makeTenant(1);
        $provider = $this->makeProvider([1 => ['backup.rpo_hours' => [
            'value' => 24,
            '_meta' => ['settings_drift_detected' => true],
        ]]]);

        self::assertSame(24, $provider->getStoredValue($tenant, 'backup.rpo_hours'));
    }

    #[Test]
    public function passwordMinLengthIsFloorOnlyAndUnknownKeysAreFree(): void
    {
        self::assertSame(OverrideMode::FloorOnly, TenantPolicySettingProvider::overrideModeFor('security.password_min_length'));
        self::assertSame(OverrideMode::Free, TenantPolicySettingProvider::overrideModeFor('bsi.tier_filter'));
    }

    #[Test]
    public function globalDefaultComesFromSystemSettings(): void
    {
        $provider = $this->makeProvider([], 14);

        self::assertSame(14, $provider->getGlobalDefault('security.password_min_length', 8));
        self::assertSame(8, $provider->getGlobalDefault('nodot', 8));
    }
}
