<?php

namespace App\Tests\Service;

use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\TestCase;

class ModuleConfigurationServiceTest extends TestCase
{
    private ModuleConfigurationService $service;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/test_modules';

        if (!is_dir($this->projectDir)) {
            mkdir($this->projectDir, 0777, true);
        }
        if (!is_dir($this->projectDir . '/config')) {
            mkdir($this->projectDir . '/config', 0777, true);
        }

        // Create test modules.yaml
        $modulesConfig = [
            'modules' => [
                'core' => [
                    'name' => 'Core Module',
                    'required' => true,
                    'dependencies' => []
                ],
                'risk' => [
                    'name' => 'Risk Management',
                    'required' => false,
                    'dependencies' => ['core']
                ],
                'compliance' => [
                    'name' => 'Compliance',
                    'required' => false,
                    'dependencies' => ['core', 'risk']
                ]
            ],
            'base_data' => [
                [
                    'name' => 'Base Controls',
                    'required_modules' => ['core'],
                    'command' => 'app:import-controls'
                ]
            ],
            'sample_data' => [
                'sample1' => [
                    'name' => 'Sample Data 1',
                    'required_modules' => ['core'],
                    'command' => 'app:sample1'
                ]
            ]
        ];

        file_put_contents(
            $this->projectDir . '/config/modules.yaml',
            \Symfony\Component\Yaml\Yaml::dump($modulesConfig, 4)
        );

        $this->service = new ModuleConfigurationService($this->projectDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }
    }

    public function testGetAllModulesReturnsAllModules(): void
    {
        $modules = $this->service->getAllModules();

        $this->assertIsArray($modules);
        $this->assertArrayHasKey('core', $modules);
        $this->assertArrayHasKey('risk', $modules);
        $this->assertArrayHasKey('compliance', $modules);
    }

    public function testGetModuleReturnsSpecificModule(): void
    {
        $module = $this->service->getModule('risk');

        $this->assertIsArray($module);
        $this->assertSame('Risk Management', $module['name']);
        $this->assertFalse($module['required']);
    }

    public function testGetModuleReturnsNullForNonexistent(): void
    {
        $module = $this->service->getModule('nonexistent');

        $this->assertNull($module);
    }

    public function testGetRequiredModulesReturnsOnlyRequired(): void
    {
        $required = $this->service->getRequiredModules();

        $this->assertCount(1, $required);
        $this->assertArrayHasKey('core', $required);
    }

    public function testGetOptionalModulesReturnsOnlyOptional(): void
    {
        $optional = $this->service->getOptionalModules();

        $this->assertCount(2, $optional);
        $this->assertArrayHasKey('risk', $optional);
        $this->assertArrayHasKey('compliance', $optional);
    }

    public function testValidateModuleSelectionWithValidSelection(): void
    {
        $result = $this->service->validateModuleSelection(['core', 'risk']);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateModuleSelectionAddsRequiredModules(): void
    {
        $result = $this->service->validateModuleSelection(['risk']);

        $this->assertTrue($result['valid']);
        $this->assertContains('core', $result['modules']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testValidateModuleSelectionDetectsMissingDependencies(): void
    {
        $result = $this->service->validateModuleSelection(['compliance']);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testValidateModuleSelectionWithNonexistentModule(): void
    {
        $result = $this->service->validateModuleSelection(['nonexistent']);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testResolveModuleDependenciesAddsRequiredModules(): void
    {
        $result = $this->service->resolveModuleDependencies(['risk']);

        $this->assertContains('core', $result['modules']);
        $this->assertContains('risk', $result['modules']);
        $this->assertContains('core', $result['added']);
    }

    public function testResolveModuleDependenciesHandlesChainedDependencies(): void
    {
        $result = $this->service->resolveModuleDependencies(['compliance']);

        $this->assertContains('core', $result['modules']);
        $this->assertContains('risk', $result['modules']);
        $this->assertContains('compliance', $result['modules']);
    }

    public function testSaveActiveModulesCreatesFile(): void
    {
        $this->service->saveActiveModules(['core', 'risk']);

        $configPath = $this->projectDir . '/config/active_modules.yaml';
        $this->assertFileExists($configPath);

        $config = \Symfony\Component\Yaml\Yaml::parseFile($configPath);
        $this->assertArrayHasKey('active_modules', $config);
        $this->assertContains('core', $config['active_modules']);
        $this->assertContains('risk', $config['active_modules']);
    }

    public function testGetActiveModulesReturnsRequiredWhenNoFile(): void
    {
        $active = $this->service->getActiveModules();

        $this->assertIsArray($active);
        $this->assertContains('core', $active);
    }

    public function testGetActiveModulesReadsFromFile(): void
    {
        $this->service->saveActiveModules(['core', 'risk']);

        $active = $this->service->getActiveModules();

        $this->assertContains('core', $active);
        $this->assertContains('risk', $active);
    }

    public function testIsModuleActiveReturnsTrueForActive(): void
    {
        $this->service->saveActiveModules(['core', 'risk']);

        $this->assertTrue($this->service->isModuleActive('core'));
        $this->assertTrue($this->service->isModuleActive('risk'));
    }

    public function testIsModuleActiveReturnsFalseForInactive(): void
    {
        $this->service->saveActiveModules(['core']);

        $this->assertFalse($this->service->isModuleActive('compliance'));
    }

    public function testActivateModuleSucceeds(): void
    {
        $this->service->saveActiveModules(['core']);

        $result = $this->service->activateModule('risk');

        $this->assertTrue($result['success']);
        $this->assertTrue($this->service->isModuleActive('risk'));
    }

    public function testActivateModuleWithNonexistent(): void
    {
        $result = $this->service->activateModule('nonexistent');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testActivateModuleAlreadyActive(): void
    {
        $this->service->saveActiveModules(['core', 'risk']);

        $result = $this->service->activateModule('risk');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['already_active']);
    }

    public function testActivateModuleIncludesDependencies(): void
    {
        $this->service->saveActiveModules(['core']);

        $result = $this->service->activateModule('compliance');

        $this->assertTrue($result['success']);
        $this->assertTrue($this->service->isModuleActive('compliance'));
        $this->assertTrue($this->service->isModuleActive('risk'));
    }

    public function testDeactivateModuleSucceeds(): void
    {
        $this->service->saveActiveModules(['core', 'risk']);

        $result = $this->service->deactivateModule('risk');

        $this->assertTrue($result['success']);
        $this->assertFalse($this->service->isModuleActive('risk'));
    }

    public function testDeactivateRequiredModuleFails(): void
    {
        $this->service->saveActiveModules(['core', 'risk']);

        $result = $this->service->deactivateModule('core');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testDeactivateModuleWithDependentsFails(): void
    {
        $this->service->saveActiveModules(['core', 'risk', 'compliance']);

        $result = $this->service->deactivateModule('risk');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('dependents', $result);
    }

    public function testDeactivateAlreadyInactiveModule(): void
    {
        $this->service->saveActiveModules(['core']);

        $result = $this->service->deactivateModule('risk');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['already_inactive']);
    }

    public function testGetBaseDataReturnsArray(): void
    {
        $baseData = $this->service->getBaseData();

        $this->assertIsArray($baseData);
        $this->assertNotEmpty($baseData);
    }

    public function testGetSampleDataReturnsArray(): void
    {
        $sampleData = $this->service->getSampleData();

        $this->assertIsArray($sampleData);
        $this->assertNotEmpty($sampleData);
    }

    public function testGetAvailableSampleDataFiltersInactive(): void
    {
        $available = $this->service->getAvailableSampleData(['core']);

        $this->assertIsArray($available);
    }

    public function testGetStatisticsReturnsCorrectCounts(): void
    {
        $this->service->saveActiveModules(['core', 'risk']);

        $stats = $this->service->getStatistics();

        $this->assertSame(3, $stats['total_modules']);
        $this->assertSame(2, $stats['active_modules']);
        $this->assertSame(1, $stats['inactive_modules']);
        $this->assertSame(1, $stats['required_modules']);
        $this->assertSame(2, $stats['optional_modules']);
    }

    public function testGetDependencyGraphReturnsCompleteGraph(): void
    {
        $graph = $this->service->getDependencyGraph();

        $this->assertIsArray($graph);
        $this->assertArrayHasKey('core', $graph);
        $this->assertArrayHasKey('risk', $graph);
        $this->assertArrayHasKey('compliance', $graph);

        // Check structure
        $this->assertArrayHasKey('name', $graph['core']);
        $this->assertArrayHasKey('dependencies', $graph['core']);
        $this->assertArrayHasKey('dependents', $graph['core']);
        $this->assertArrayHasKey('required', $graph['core']);
    }

    public function testGetDependencyGraphCalculatesDependents(): void
    {
        $graph = $this->service->getDependencyGraph();

        // Core should have risk and compliance as dependents
        $this->assertContains('risk', $graph['core']['dependents']);
        $this->assertContains('compliance', $graph['core']['dependents']);

        // Risk should have compliance as dependent
        $this->assertContains('compliance', $graph['risk']['dependents']);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
