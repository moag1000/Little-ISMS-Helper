<?php

declare(strict_types=1);

namespace App\Tests\Service\TenantSettingResolver;

use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Service\TenantSettingResolver\OverrideMode;
use App\Service\TenantSettingResolver\PolicySettingProvider;
use App\Service\TenantSettingResolver\SettingResolutionResult;
use App\Service\TenantSettingResolver\TenantSettingResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W6-B — ISO 27701 PIMS settings + tag-emission helper.
 *
 * Validates the three new contracts on PolicySettingProvider:
 *   1. Defaults — `iso27701.enabled=false`, `iso27701.version=2025`.
 *   2. Version switch — tenant-stored 2019 must propagate; unknown
 *      values fall back to the 2025 default.
 *   3. Tag emission — `tagDocumentWithIso27701()` returns the correct
 *      `iso27701:<clause>` list per resolved version, with asymmetric
 *      fallback when only one version's mapping is populated.
 */
#[AllowMockObjectsWithoutExpectations]
final class Iso27701SettingsTest extends TestCase
{
    private function makeTenant(): Tenant
    {
        return $this->createMock(Tenant::class);
    }

    private function makeProvider(TenantSettingResolver $resolver): PolicySettingProvider
    {
        return new PolicySettingProvider($resolver);
    }

    /**
     * Build a TenantSettingResolver mock that returns the given map of
     * setting-key → resolved value. Any unmatched key returns the
     * default supplied to resolveFor().
     *
     * @param array<string, mixed> $settings
     */
    private function makeResolver(array $settings): TenantSettingResolver
    {
        $resolver = $this->createMock(TenantSettingResolver::class);
        $resolver
            ->method('resolveFor')
            ->willReturnCallback(static function (Tenant $tenant, string $key, mixed $default) use ($settings): SettingResolutionResult {
                $value = array_key_exists($key, $settings) ? $settings[$key] : $default;
                return new SettingResolutionResult(
                    value: $value,
                    sourceTenantId: $tenant->getId(),
                    effectiveMode: OverrideMode::ForbiddenToRelax,
                );
            });
        return $resolver;
    }

    #[Test]
    public function testDefaultsWhenNothingStored(): void
    {
        // Nothing stored → enabled=false, version=2025.
        $tenant = $this->makeTenant();
        $resolver = $this->makeResolver([]);
        $provider = $this->makeProvider($resolver);

        self::assertFalse(
            $provider->isIso27701Enabled($tenant),
            'iso27701.enabled defaults to false (PIMS is opt-in addon)',
        );
        self::assertSame(
            PolicySettingProvider::ISO27701_VERSION_2025,
            $provider->resolveIso27701Version($tenant),
            'iso27701.version defaults to 2025 (current edition since 2025-09)',
        );

        // Null tenant returns defaults safely (no resolver call).
        self::assertFalse($provider->isIso27701Enabled(null));
        self::assertSame(
            PolicySettingProvider::ISO27701_VERSION_2025,
            $provider->resolveIso27701Version(null),
        );

        // tagDocumentWithIso27701 emits nothing when PIMS is off,
        // even for templates with a clause mapping.
        $template = new PolicyTemplate();
        $template->setIso27701Clauses2025(['5.1', '5.2']);
        self::assertSame([], $provider->tagDocumentWithIso27701($template, $tenant));
    }

    #[Test]
    public function testVersionSwitchAndUnknownValuesFallBack(): void
    {
        $tenant = $this->makeTenant();

        // Tenant on legacy 2019 audit cycle.
        $resolver2019 = $this->makeResolver([
            PolicySettingProvider::SETTING_ISO27701_VERSION => '2019',
        ]);
        self::assertSame(
            '2019',
            $this->makeProvider($resolver2019)->resolveIso27701Version($tenant),
            '2019 must propagate when explicitly set',
        );

        // Tenant explicitly on 2025.
        $resolver2025 = $this->makeResolver([
            PolicySettingProvider::SETTING_ISO27701_VERSION => '2025',
        ]);
        self::assertSame(
            '2025',
            $this->makeProvider($resolver2025)->resolveIso27701Version($tenant),
        );

        // Unknown / garbage values fall back to default (2025) — the
        // resolver must never propagate an invalid version downstream.
        foreach (['2018', '2030', 'latest', '', '2025 ', null] as $bad) {
            $resolver = $this->makeResolver([
                PolicySettingProvider::SETTING_ISO27701_VERSION => $bad,
            ]);
            self::assertSame(
                PolicySettingProvider::ISO27701_VERSION_DEFAULT,
                $this->makeProvider($resolver)->resolveIso27701Version($tenant),
                sprintf('unknown value %s must fall back to default', var_export($bad, true)),
            );
        }

        // ISO27701_VERSIONS constant exposed for downstream consumers.
        self::assertSame(['2019', '2025'], PolicySettingProvider::ISO27701_VERSIONS);
        self::assertSame('2025', PolicySettingProvider::ISO27701_VERSION_DEFAULT);
    }

    #[Test]
    public function testTagEmissionWiresVersionToClauseColumn(): void
    {
        $tenant = $this->makeTenant();

        // Build a Data-Breach template with the diverging 2025 vs 2019 mapping.
        $template = new PolicyTemplate();
        $template->setIso27701Clauses2025(['6.13']);
        $template->setIso27701Clauses2019(['6.13.1.5']);

        // PIMS on, version=2025 → emits 2025 clauses prefixed with iso27701:
        $resolver2025 = $this->makeResolver([
            PolicySettingProvider::SETTING_ISO27701_ENABLED => true,
            PolicySettingProvider::SETTING_ISO27701_VERSION => '2025',
        ]);
        $tags2025 = $this->makeProvider($resolver2025)->tagDocumentWithIso27701($template, $tenant);
        self::assertSame(['iso27701:6.13'], $tags2025, '2025 setting picks 2025 clause column');

        // PIMS on, version=2019 → emits 2019 clauses (the deeper sub-clause).
        $resolver2019 = $this->makeResolver([
            PolicySettingProvider::SETTING_ISO27701_ENABLED => true,
            PolicySettingProvider::SETTING_ISO27701_VERSION => '2019',
        ]);
        $tags2019 = $this->makeProvider($resolver2019)->tagDocumentWithIso27701($template, $tenant);
        self::assertSame(['iso27701:6.13.1.5'], $tags2019, '2019 setting picks 2019 clause column');

        // Asymmetric fallback: template only has 2025 clauses (legacy
        // partial seed), but tenant requests 2019. Helper falls back to
        // the populated column rather than emitting an empty list.
        $template2025Only = new PolicyTemplate();
        $template2025Only->setIso27701Clauses2025(['7.2.8']);
        $template2025Only->setIso27701Clauses2019(null);
        $tagsFallback = $this->makeProvider($resolver2019)->tagDocumentWithIso27701($template2025Only, $tenant);
        self::assertSame(
            ['iso27701:7.2.8'],
            $tagsFallback,
            'fallback to populated column when requested version is unmapped',
        );

        // Multi-clause template (Privacy Policy: Cl. 5.1 + 5.2) — every
        // clause gets prefixed, order preserved.
        $multi = new PolicyTemplate();
        $multi->setIso27701Clauses2025(['5.1', '5.2']);
        $multi->setIso27701Clauses2019(['5.1', '5.2']);
        $multiTags = $this->makeProvider($resolver2025)->tagDocumentWithIso27701($multi, $tenant);
        self::assertSame(['iso27701:5.1', 'iso27701:5.2'], $multiTags);

        // Template with NO clause mapping at all → empty tag list even
        // when PIMS is enabled.
        $unmapped = new PolicyTemplate();
        self::assertSame([], $this->makeProvider($resolver2025)->tagDocumentWithIso27701($unmapped, $tenant));

        // Setting key constants exposed for downstream consumers.
        self::assertSame('iso27701.enabled', PolicySettingProvider::SETTING_ISO27701_ENABLED);
        self::assertSame('iso27701.version', PolicySettingProvider::SETTING_ISO27701_VERSION);
    }
}
