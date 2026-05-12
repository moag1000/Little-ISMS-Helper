<?php

declare(strict_types=1);

namespace App\Tests\Service\Sso;

use App\Entity\IdentityProvider;
use App\Repository\IdentityProviderRepository;
use App\Service\Sso\SsoProviderRegistry;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SsoProviderRegistry preset support (Wave 1).
 */
#[AllowMockObjectsWithoutExpectations]
final class SsoProviderRegistryPresetTest extends TestCase
{
    private function makeRegistry(): SsoProviderRegistry
    {
        $repo = $this->createMock(IdentityProviderRepository::class);
        $context = $this->createMock(TenantContext::class);
        return new SsoProviderRegistry($repo, $context);
    }

    #[Test]
    public function getAllPresetsReturnsSixPresets(): void
    {
        $presets = $this->makeRegistry()->getAllPresets();
        self::assertCount(6, $presets);
        self::assertArrayHasKey('entra_id', $presets);
        self::assertArrayHasKey('google', $presets);
        self::assertArrayHasKey('keycloak', $presets);
        self::assertArrayHasKey('okta', $presets);
        self::assertArrayHasKey('auth0', $presets);
        self::assertArrayHasKey('generic', $presets);
    }

    #[Test]
    public function getPresetReturnsNullForUnknownKey(): void
    {
        self::assertNull($this->makeRegistry()->getPreset('unknown_provider_xyz'));
    }

    #[Test]
    public function eachPresetHasRequiredFields(): void
    {
        $presets = $this->makeRegistry()->getAllPresets();
        foreach ($presets as $key => $preset) {
            self::assertArrayHasKey('label', $preset, "Preset $key missing 'label'");
            self::assertArrayHasKey('icon', $preset, "Preset $key missing 'icon'");
            self::assertArrayHasKey('scopes', $preset, "Preset $key missing 'scopes'");
            self::assertArrayHasKey('attributeMap', $preset, "Preset $key missing 'attributeMap'");
            self::assertIsArray($preset['scopes'], "Preset $key 'scopes' must be an array");
            self::assertContains('openid', $preset['scopes'], "Preset $key must include 'openid' scope");
        }
    }

    #[Test]
    public function applyPresetToProviderSetsPresetType(): void
    {
        $provider = new IdentityProvider();
        $this->makeRegistry()->applyPresetToProvider($provider, 'google');
        self::assertSame('google', $provider->getPresetType());
    }

    #[Test]
    public function applyPresetDoesNotOverrideExistingButtonLabel(): void
    {
        $provider = (new IdentityProvider())->setButtonLabel('Custom Label');
        $this->makeRegistry()->applyPresetToProvider($provider, 'google');
        self::assertSame('Custom Label', $provider->getButtonLabel());
    }

    #[Test]
    public function applyPresetSetsButtonLabelWhenEmpty(): void
    {
        $provider = new IdentityProvider();
        $this->makeRegistry()->applyPresetToProvider($provider, 'entra_id');
        self::assertNotNull($provider->getButtonLabel());
        self::assertNotEmpty($provider->getButtonLabel());
    }

    #[Test]
    public function applyUnknownPresetDoesNothing(): void
    {
        $provider = new IdentityProvider();
        $this->makeRegistry()->applyPresetToProvider($provider, 'nonexistent');
        self::assertNull($provider->getPresetType());
    }
}
