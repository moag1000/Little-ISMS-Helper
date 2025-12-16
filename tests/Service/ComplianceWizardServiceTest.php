<?php

namespace App\Tests\Service;

use App\Service\ComplianceWizardService;
use App\Service\ModuleConfigurationService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests for ComplianceWizardService
 *
 * Phase 7E: Compliance Wizards & Module-Aware KPIs
 */
class ComplianceWizardServiceTest extends KernelTestCase
{
    private ?ComplianceWizardService $wizardService = null;
    private ?ModuleConfigurationService $moduleService = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->wizardService = $container->get(ComplianceWizardService::class);
        $this->moduleService = $container->get(ModuleConfigurationService::class);
    }

    /**
     * Helper to skip tests that require database when DB is unavailable
     */
    private function requireDatabase(): void
    {
        try {
            // Test actual database connectivity with a simple query
            $container = static::getContainer();
            $em = $container->get('doctrine.orm.entity_manager');
            $em->getConnection()->executeQuery('SELECT 1');
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Access denied') ||
                str_contains($e->getMessage(), 'Connection refused') ||
                str_contains($e->getMessage(), 'SQLSTATE')) {
                $this->markTestSkipped('Database not available: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    public function testGetAvailableWizardsReturnsArray(): void
    {
        $this->requireDatabase();
        $wizards = $this->wizardService->getAvailableWizards();
        $this->assertIsArray($wizards);
    }

    public function testAvailableWizardsHaveRequiredKeys(): void
    {
        $this->requireDatabase();
        $wizards = $this->wizardService->getAvailableWizards();

        foreach ($wizards as $key => $wizard) {
            $this->assertArrayHasKey('code', $wizard, "Wizard '$key' missing 'code'");
            $this->assertArrayHasKey('name', $wizard, "Wizard '$key' missing 'name'");
            $this->assertArrayHasKey('description', $wizard, "Wizard '$key' missing 'description'");
            $this->assertArrayHasKey('icon', $wizard, "Wizard '$key' missing 'icon'");
            $this->assertArrayHasKey('color', $wizard, "Wizard '$key' missing 'color'");
            $this->assertArrayHasKey('required_modules', $wizard, "Wizard '$key' missing 'required_modules'");
            $this->assertArrayHasKey('categories', $wizard, "Wizard '$key' missing 'categories'");
        }
    }

    public function testIsWizardAvailableReturnsBool(): void
    {
        $this->requireDatabase();
        $result = $this->wizardService->isWizardAvailable('iso27001');
        $this->assertIsBool($result);

        $result = $this->wizardService->isWizardAvailable('nonexistent');
        $this->assertFalse($result);
    }

    public function testGetWizardConfigReturnsNullForInvalidWizard(): void
    {
        $this->requireDatabase();
        $config = $this->wizardService->getWizardConfig('nonexistent');
        $this->assertNull($config);
    }

    public function testGetWizardConfigReturnsArrayForValidWizard(): void
    {
        $this->requireDatabase();
        $wizards = $this->wizardService->getAvailableWizards();

        foreach (array_keys($wizards) as $wizardKey) {
            $config = $this->wizardService->getWizardConfig($wizardKey);
            $this->assertIsArray($config, "Config for '$wizardKey' should be array");
        }
    }

    public function testRunAssessmentReturnsSuccessArray(): void
    {
        $this->requireDatabase();
        $wizards = $this->wizardService->getAvailableWizards();

        if (empty($wizards)) {
            $this->markTestSkipped('No wizards available - required modules may not be active');
        }

        $wizardKey = array_key_first($wizards);
        $result = $this->wizardService->runAssessment($wizardKey);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    public function testRunAssessmentReturnsExpectedKeys(): void
    {
        $this->requireDatabase();
        $wizards = $this->wizardService->getAvailableWizards();

        if (empty($wizards)) {
            $this->markTestSkipped('No wizards available');
        }

        $wizardKey = array_key_first($wizards);
        $result = $this->wizardService->runAssessment($wizardKey);

        $expectedKeys = [
            'success',
            'wizard',
            'framework',
            'framework_name',
            'overall_score',
            'status',
            'categories',
            'critical_gaps',
            'critical_gap_count',
            'active_modules',
            'missing_modules',
            'assessed_at',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Result missing key '$key'");
        }
    }

    public function testRunAssessmentFailsForUnavailableWizard(): void
    {
        $this->requireDatabase();
        $result = $this->wizardService->runAssessment('nonexistent');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testOverallScoreIsWithinRange(): void
    {
        $this->requireDatabase();
        $wizards = $this->wizardService->getAvailableWizards();

        if (empty($wizards)) {
            $this->markTestSkipped('No wizards available');
        }

        $wizardKey = array_key_first($wizards);
        $result = $this->wizardService->runAssessment($wizardKey);

        $this->assertGreaterThanOrEqual(0, $result['overall_score']);
        $this->assertLessThanOrEqual(100, $result['overall_score']);
    }

    public function testStatusIsValidValue(): void
    {
        $this->requireDatabase();
        $wizards = $this->wizardService->getAvailableWizards();

        if (empty($wizards)) {
            $this->markTestSkipped('No wizards available');
        }

        $wizardKey = array_key_first($wizards);
        $result = $this->wizardService->runAssessment($wizardKey);

        $validStatuses = ['compliant', 'partial', 'in_progress', 'non_compliant'];
        $this->assertContains($result['status'], $validStatuses);
    }

    public function testCategoriesHaveExpectedStructure(): void
    {
        $this->requireDatabase();
        $wizards = $this->wizardService->getAvailableWizards();

        if (empty($wizards)) {
            $this->markTestSkipped('No wizards available');
        }

        $wizardKey = array_key_first($wizards);
        $result = $this->wizardService->runAssessment($wizardKey);

        $this->assertIsArray($result['categories']);

        foreach ($result['categories'] as $categoryKey => $category) {
            $this->assertArrayHasKey('name', $category, "Category '$categoryKey' missing 'name'");
            $this->assertArrayHasKey('score', $category, "Category '$categoryKey' missing 'score'");
            $this->assertArrayHasKey('gaps', $category, "Category '$categoryKey' missing 'gaps'");
            $this->assertIsArray($category['gaps']);
        }
    }

    public function testAssessedAtIsDateTimeInterface(): void
    {
        $this->requireDatabase();
        $wizards = $this->wizardService->getAvailableWizards();

        if (empty($wizards)) {
            $this->markTestSkipped('No wizards available');
        }

        $wizardKey = array_key_first($wizards);
        $result = $this->wizardService->runAssessment($wizardKey);

        $this->assertInstanceOf(\DateTimeInterface::class, $result['assessed_at']);
    }

    public function testCriticalGapCountMatchesArray(): void
    {
        $this->requireDatabase();
        $wizards = $this->wizardService->getAvailableWizards();

        if (empty($wizards)) {
            $this->markTestSkipped('No wizards available');
        }

        $wizardKey = array_key_first($wizards);
        $result = $this->wizardService->runAssessment($wizardKey);

        $this->assertCount($result['critical_gap_count'], $result['critical_gaps']);
    }

    /**
     * Test all available wizards can run without errors
     */
    public function testAllAvailableWizardsCanRun(): void
    {
        $this->requireDatabase();
        $wizards = $this->wizardService->getAvailableWizards();

        foreach (array_keys($wizards) as $wizardKey) {
            $result = $this->wizardService->runAssessment($wizardKey);

            $this->assertTrue(
                $result['success'],
                "Wizard '$wizardKey' failed: " . ($result['error'] ?? 'unknown error')
            );
        }
    }
}
