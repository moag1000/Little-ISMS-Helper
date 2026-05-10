<?php

declare(strict_types=1);

namespace App\Tests\Service\Setup;

use App\Service\Setup\SetupIndustryPresetService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * V4-EF-1 — Unit tests for the Setup-Wizard Industry-Preset service.
 *
 * Tests preset-loading from real fixture files (`fixtures/library/presets/`)
 * and session-population side-effects. The service applies presets to the
 * setup wizard SESSION (not to a tenant — tenant does not exist yet during
 * setup), so all assertions are session-based.
 */
final class SetupIndustryPresetServiceTest extends TestCase
{
    private SetupIndustryPresetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SetupIndustryPresetService();
    }

    #[Test]
    public function testListPresetsReturnsAllCuratedPresets(): void
    {
        $presets = $this->service->listPresets();

        $this->assertNotEmpty($presets, 'At least one preset must be discoverable.');
        $ids = array_column($presets, 'id');
        // The four V3-W2-M3 presets ship in fixtures/library/presets/.
        $this->assertContains('saas-iso27001', $ids);
        $this->assertContains('de-mittelstand-nis2', $ids);
        $this->assertContains('health-care-dora', $ids);
        $this->assertContains('kritis-energie', $ids);
    }

    #[Test]
    public function testListPresetsExposesUiMetadata(): void
    {
        $presets = $this->service->listPresets();
        foreach ($presets as $preset) {
            $this->assertArrayHasKey('icon', $preset);
            $this->assertArrayHasKey('variant', $preset);
            $this->assertArrayHasKey('modules_count', $preset);
            $this->assertArrayHasKey('frameworks_count', $preset);
            $this->assertArrayHasKey('primary_frameworks', $preset);
            $this->assertGreaterThan(0, $preset['modules_count']);
        }
    }

    #[Test]
    public function testApplyToSessionPopulatesSelectedModulesAndFrameworks(): void
    {
        $session = new Session(new MockArraySessionStorage());

        $result = $this->service->applyToSession('saas-iso27001', $session);

        $this->assertNotNull($result, 'Known preset id must apply.');
        $this->assertNotEmpty($result['modules']);
        $this->assertNotEmpty($result['frameworks']);

        // ISO27001 is force-included by the service (step8 enforces it anyway).
        $this->assertContains('ISO27001', $result['frameworks']);

        // Session must carry the populated state for subsequent wizard steps.
        $this->assertSame($result['modules'], $session->get(SetupIndustryPresetService::SESSION_KEY_MODULES));
        $this->assertSame($result['frameworks'], $session->get(SetupIndustryPresetService::SESSION_KEY_FRAMEWORKS));
        $this->assertTrue($session->get(SetupIndustryPresetService::SESSION_KEY_APPLIED));
        $this->assertSame('saas-iso27001', $session->get(SetupIndustryPresetService::SESSION_KEY_PRESET_ID));
    }

    #[Test]
    public function testApplyToSessionRejectsUnknownPreset(): void
    {
        $session = new Session(new MockArraySessionStorage());

        $result = $this->service->applyToSession('does-not-exist', $session);

        $this->assertNull($result);
        $this->assertFalse($session->has(SetupIndustryPresetService::SESSION_KEY_APPLIED));
    }

    #[Test]
    public function testApplyToSessionRejectsPathTraversalAttempt(): void
    {
        $session = new Session(new MockArraySessionStorage());

        // Service must reject anything outside [a-z0-9-] to prevent file
        // traversal via the YAML loader.
        $result = $this->service->applyToSession('../../../etc/passwd', $session);

        $this->assertNull($result);
    }

    #[Test]
    public function testClearSessionRemovesAppliedFlags(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $this->service->applyToSession('saas-iso27001', $session);

        $this->service->clearSession($session);

        $this->assertFalse($session->has(SetupIndustryPresetService::SESSION_KEY_APPLIED));
        $this->assertFalse($session->has(SetupIndustryPresetService::SESSION_KEY_PRESET_ID));
        // Selected modules + frameworks remain — clearing the *applied* flag
        // does not undo the populated wizard state. (Switching paths happens
        // by re-applying or by user action in step7.)
    }

    #[Test]
    public function testNis2PresetActivatesCorrectFrameworks(): void
    {
        $session = new Session(new MockArraySessionStorage());

        $result = $this->service->applyToSession('de-mittelstand-nis2', $session);

        $this->assertNotNull($result);
        $this->assertContains('NIS2', $result['frameworks']);
        $this->assertContains('ISO27001', $result['frameworks']);
        $this->assertContains('GDPR', $result['frameworks']);
    }

    #[Test]
    public function testKritisPresetActivatesBsiGrundschutz(): void
    {
        $session = new Session(new MockArraySessionStorage());

        $result = $this->service->applyToSession('kritis-energie', $session);

        $this->assertNotNull($result);
        $this->assertContains('BSI_GRUNDSCHUTZ', $result['frameworks']);
        $this->assertContains('NIS2', $result['frameworks']);
    }
}
