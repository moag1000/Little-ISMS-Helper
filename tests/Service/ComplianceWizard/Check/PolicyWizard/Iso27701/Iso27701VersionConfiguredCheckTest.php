<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Iso27701;

use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Repository\TenantPolicySettingRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Iso27701\Iso27701VersionConfiguredCheck;
use App\Service\TenantSettingResolver\OverrideMode;
use App\Service\TenantSettingResolver\PolicySettingProvider;
use App\Service\TenantSettingResolver\SettingResolutionResult;
use App\Service\TenantSettingResolver\TenantSettingResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class Iso27701VersionConfiguredCheckTest extends TestCase
{
    private TenantSettingResolver&MockObject $resolver;
    private PolicySettingProvider $policySettingProvider;
    private TenantPolicySettingRepository&MockObject $settingRepository;
    private Iso27701VersionConfiguredCheck $check;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(TenantSettingResolver::class);
        $this->policySettingProvider = new PolicySettingProvider($this->resolver);
        $this->settingRepository = $this->createMock(TenantPolicySettingRepository::class);
        $this->check = new Iso27701VersionConfiguredCheck(
            $this->policySettingProvider,
            $this->settingRepository,
        );
    }

    #[Test]
    public function testPassesWhenPimsEnabledAndVersionDeclared(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->stubResolver(true);

        $setting = new TenantPolicySetting();
        $setting->setKey(PolicySettingProvider::SETTING_ISO27701_VERSION);
        $setting->setValue(PolicySettingProvider::ISO27701_VERSION_2025);
        $this->settingRepository->method('findOneByTenantAndKey')->willReturn($setting);

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertTrue($result->details['iso27701_enabled']);
        self::assertSame(PolicySettingProvider::ISO27701_VERSION_2025, $result->details['iso27701_version']);
        self::assertNull($result->gap);
        self::assertSame('iso27701', $this->check->getStandard());
    }

    #[Test]
    public function testFailsWhenPimsEnabledButVersionMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->stubResolver(true);
        $this->settingRepository->method('findOneByTenantAndKey')->willReturn(null);

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
        self::assertSame('version_setting_missing', $result->details['reason']);
        self::assertNotNull($result->gap);
        self::assertSame('high', $result->gap['priority']);
    }

    #[Test]
    public function testGapActionableAndVacuousWhenPimsDisabled(): void
    {
        $tenant = $this->createMock(Tenant::class);

        // PIMS disabled → vacuously satisfied.
        $disabledResolver = $this->createMock(TenantSettingResolver::class);
        $disabledResolver->method('resolveFor')->willReturn(
            new SettingResolutionResult(false, null, OverrideMode::Free),
        );
        $disabledProvider = new PolicySettingProvider($disabledResolver);
        $disabledCheck = new Iso27701VersionConfiguredCheck(
            $disabledProvider,
            $this->settingRepository,
        );
        $vacuous = $disabledCheck->run($tenant);
        self::assertTrue($vacuous->passed);
        self::assertSame('pims_not_enabled', $vacuous->details['reason']);
        self::assertNull($vacuous->gap);

        // Invalid version → fails with version_setting_invalid.
        $invalidResolver = $this->createMock(TenantSettingResolver::class);
        $invalidResolver->method('resolveFor')->willReturn(
            new SettingResolutionResult(true, null, OverrideMode::Free),
        );
        $invalidProvider = new PolicySettingProvider($invalidResolver);
        $invalidSettingRepo = $this->createMock(TenantPolicySettingRepository::class);
        $invalidSetting = new TenantPolicySetting();
        $invalidSetting->setKey(PolicySettingProvider::SETTING_ISO27701_VERSION);
        $invalidSetting->setValue('1999');
        $invalidSettingRepo->method('findOneByTenantAndKey')->willReturn($invalidSetting);
        $invalidCheck = new Iso27701VersionConfiguredCheck($invalidProvider, $invalidSettingRepo);

        $invalidResult = $invalidCheck->run($tenant);
        self::assertFalse($invalidResult->passed);
        self::assertSame('version_setting_invalid', $invalidResult->details['reason']);
        self::assertSame('app_policy_wizard_index', $invalidResult->gap['route']);
        self::assertSame('policy_wizard', $invalidResult->gap['translation_domain']);
        self::assertSame(
            'compliance_check.iso27701_version_configured.fail_message',
            $invalidResult->gap['title'],
        );

        $nullResult = $this->check->run(null);
        self::assertFalse($nullResult->passed);
        self::assertSame('no_tenant', $nullResult->details['reason']);
    }

    private function stubResolver(bool $isEnabled): void
    {
        $this->resolver->method('resolveFor')->willReturn(
            new SettingResolutionResult($isEnabled, null, OverrideMode::Free),
        );
    }
}
