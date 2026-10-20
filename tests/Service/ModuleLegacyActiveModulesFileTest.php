<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Upgrade path of the central module gate (#1043): an instance file written
 * before the gate does not list the newly gated modules, yet their pages were
 * reachable before — they must stay active until the operator saves a module
 * selection.
 */
final class ModuleLegacyActiveModulesFileTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/module_legacy_' . uniqid();
        mkdir($this->projectDir . '/config', 0777, true);
        copy(\dirname(__DIR__, 2) . '/config/modules.yaml', $this->projectDir . '/config/modules.yaml');
    }

    protected function tearDown(): void
    {
        foreach (['/config/modules.yaml', '/config/active_modules.yaml'] as $file) {
            @unlink($this->projectDir . $file);
        }
        @rmdir($this->projectDir . '/config');
        @rmdir($this->projectDir);
    }

    private function service(): ModuleConfigurationService
    {
        return new ModuleConfigurationService($this->projectDir);
    }

    #[Test]
    public function legacyFileKeepsNewlyGatedModulesActive(): void
    {
        file_put_contents($this->projectDir . '/config/active_modules.yaml', "active_modules:\n    - assets\n");

        $service = $this->service();

        foreach (ModuleConfigurationService::LATE_GATED_MODULES as $module) {
            self::assertTrue($service->isModuleActive($module), $module . ' was reachable before the gate');
        }
        self::assertTrue($service->isModuleActive('assets'));
        self::assertFalse($service->isModuleActive('bcm'), 'only the late-gated keys are grandfathered');
    }

    #[Test]
    public function fileWithBaselineMarkerIsTakenAtFaceValue(): void
    {
        file_put_contents(
            $this->projectDir . '/config/active_modules.yaml',
            "active_modules:\n    - assets\ngating_baseline: 1\n",
        );

        $service = $this->service();

        self::assertFalse($service->isModuleActive('persons'));
        self::assertTrue($service->isModuleActive('assets'));
    }

    #[Test]
    public function savingASelectionWritesTheMarkerSoDeactivationSticks(): void
    {
        file_put_contents($this->projectDir . '/config/active_modules.yaml', "active_modules:\n    - assets\n");

        $service = $this->service();
        $service->saveActiveModules(['assets']);

        $written = Yaml::parseFile($this->projectDir . '/config/active_modules.yaml');
        self::assertSame(ModuleConfigurationService::GATING_BASELINE, $written['gating_baseline']);
        self::assertFalse($this->service()->isModuleActive('persons'));
    }

    #[Test]
    public function shippedDistListsEveryLateGatedModuleAndTheMarker(): void
    {
        $dist = Yaml::parseFile(\dirname(__DIR__, 2) . '/config/active_modules.yaml.dist');

        self::assertSame([], array_diff(ModuleConfigurationService::LATE_GATED_MODULES, $dist['active_modules']));
        self::assertSame(ModuleConfigurationService::GATING_BASELINE, $dist['gating_baseline']);
    }
}
