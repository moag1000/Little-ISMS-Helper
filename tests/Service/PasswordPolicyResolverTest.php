<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Repository\SystemSettingsRepository;
use App\Service\PasswordPolicyResolver;
use App\Service\TenantSettingResolver\OverrideMode;
use App\Service\TenantSettingResolver\SystemSettingsBackedProvider;
use App\Service\TenantSettingResolver\TenantSettingResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Phase 8QW-4 / 8M.4 + W1-C — regression test for PasswordPolicyResolver.
 *
 * After W1-C the class became a thin adapter over TenantSettingResolver.
 * This test pins the public {@see PasswordPolicyResolver::resolveFor()}
 * contract: returns int, never below GLOBAL_FLOOR, honors floor-only
 * tightening down the holding chain.
 */
#[AllowMockObjectsWithoutExpectations]
class PasswordPolicyResolverTest extends TestCase
{
    /**
     * @param list<Tenant> $ancestors immediate-parent first
     * @return Stub&Tenant
     */
    private function makeTenant(int $id, array $ancestors = []): Stub
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getAllAncestors')->willReturn($ancestors);
        return $tenant;
    }

    private function makeRepoWithGlobal(int $globalMin): SystemSettingsRepository
    {
        $repo = $this->createStub(SystemSettingsRepository::class);
        $repo->method('getSetting')->willReturn($globalMin);
        return $repo;
    }

    #[Test]
    public function testRootTenantUsesGlobalMin(): void
    {
        $tenant = $this->makeTenant(1, []);
        $repo = $this->makeRepoWithGlobal(12);

        $resolver = new PasswordPolicyResolver($repo);
        $this->assertSame(12, $resolver->resolveFor($tenant));
    }

    #[Test]
    public function testGlobalMinBelowFloorClampedToFloor(): void
    {
        $tenant = $this->makeTenant(1, []);
        $repo = $this->makeRepoWithGlobal(0); // invalid → fall back to GLOBAL_FLOOR

        $resolver = new PasswordPolicyResolver($repo);
        $this->assertSame(PasswordPolicyResolver::GLOBAL_FLOOR, $resolver->resolveFor($tenant));
    }

    #[Test]
    public function testHigherGlobalMinReturnedAsIs(): void
    {
        $tenant = $this->makeTenant(1, []);
        $repo = $this->makeRepoWithGlobal(20);

        $resolver = new PasswordPolicyResolver($repo);
        $this->assertSame(20, $resolver->resolveFor($tenant));
    }

    #[Test]
    public function testCacheReusesResultForSameTenant(): void
    {
        $tenant = $this->makeTenant(1, []);
        $repo = $this->createMock(SystemSettingsRepository::class);
        $repo->expects($this->once())
            ->method('getSetting')
            ->willReturn(12);

        $resolver = new PasswordPolicyResolver($repo);
        $first = $resolver->resolveFor($tenant);
        $second = $resolver->resolveFor($tenant);

        $this->assertSame(12, $first);
        $this->assertSame(12, $second);
    }

    #[Test]
    public function testInvalidateClearsCache(): void
    {
        $tenant = $this->makeTenant(1, []);
        $repo = $this->createMock(SystemSettingsRepository::class);
        $repo->expects($this->exactly(2))
            ->method('getSetting')
            ->willReturn(12);

        $resolver = new PasswordPolicyResolver($repo);
        $resolver->resolveFor($tenant);
        $resolver->invalidate();
        $resolver->resolveFor($tenant);
    }

    #[Test]
    public function testAdapterWiresFloorOnlyMode(): void
    {
        // Verify the adapter delegates to the generic resolver with
        // FloorOnly semantics: child stricter (longer) wins over parent
        // baseline.
        $parent = $this->makeTenant(1, []);
        $child = $this->makeTenant(2, [$parent]);

        // Build a generic resolver with a custom in-memory provider mimicking
        // a future world where tenants have own values (W1-A wired up).
        $provider = new class implements \App\Service\TenantSettingResolver\SettingProviderInterface {
            public function getOverrideMode(string $key): OverrideMode
            {
                return OverrideMode::FloorOnly;
            }
            public function getStoredValue(Tenant $tenant, string $key): mixed
            {
                return match ($tenant->getId()) {
                    1 => 12, // parent floor
                    2 => 16, // child stricter
                    default => null,
                };
            }
            public function getGlobalDefault(string $key, mixed $default): mixed
            {
                return $default;
            }
        };
        $generic = new TenantSettingResolver($provider);

        $repo = $this->createStub(SystemSettingsRepository::class);
        $resolver = new PasswordPolicyResolver(
            systemSettingsRepository: $repo,
            settingResolver: $generic,
        );

        $this->assertSame(16, $resolver->resolveFor($child));
    }

    #[Test]
    public function testRealProviderPathBackwardCompatibility(): void
    {
        // Wire the real SystemSettingsBackedProvider with the same
        // SystemSettingsRepository the legacy resolver used. Confirms the
        // adapter retains end-to-end behaviour.
        $tenant = $this->makeTenant(1, []);
        $repo = $this->createStub(SystemSettingsRepository::class);
        $repo->method('getSetting')->willReturn(10);

        $generic = new TenantSettingResolver(
            new SystemSettingsBackedProvider(
                systemSettingsRepository: $repo,
                overrideModeMap: [PasswordPolicyResolver::SETTING_KEY => OverrideMode::FloorOnly],
            ),
        );
        $resolver = new PasswordPolicyResolver(
            systemSettingsRepository: $repo,
            settingResolver: $generic,
        );

        $this->assertSame(10, $resolver->resolveFor($tenant));
    }
}
