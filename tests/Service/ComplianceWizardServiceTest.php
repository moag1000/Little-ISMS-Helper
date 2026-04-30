<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ComplianceWizardService;
use App\Service\ModuleConfigurationService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use PHPUnit\Framework\Attributes\Test;

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

    #[Test]
    public function testGetAvailableWizardsReturnsArray(): void
    {
        $this->requireDatabase();
        $wizards = $this->wizardService->getAvailableWizards();
        $this->assertIsArray($wizards);
    }

    #[Test]
    public function testAvailableWizardsHaveRequiredKeys(): void
    {
        $this->requireDatabase();
        $wizards = $this->wizardService->getAvailableWizards();

        if ($wizards === []) {
            $this->markTestSkipped('No wizards available — required modules may not be active in this environment');
        }

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

    #[Test]
    public function testIsWizardAvailableReturnsBool(): void
    {
        $this->requireDatabase();
        $result = $this->wizardService->isWizardAvailable('iso27001');
        $this->assertIsBool($result);

        $result = $this->wizardService->isWizardAvailable('nonexistent');
        $this->assertFalse($result);
    }

    #[Test]
    public function testGetWizardConfigReturnsNullForInvalidWizard(): void
    {
        $this->requireDatabase();
        $config = $this->wizardService->getWizardConfig('nonexistent');
        $this->assertNull($config);
    }

    #[Test]
    public function testGetWizardConfigReturnsArrayForValidWizard(): void
    {
        $this->requireDatabase();
        $wizards = $this->wizardService->getAvailableWizards();

        if ($wizards === []) {
            $this->markTestSkipped('No wizards available — required modules may not be active in this environment');
        }

        foreach (array_keys($wizards) as $wizardKey) {
            $config = $this->wizardService->getWizardConfig($wizardKey);
            $this->assertIsArray($config, "Config for '$wizardKey' should be array");
        }
    }

    #[Test]
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

    #[Test]
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

    #[Test]
    public function testRunAssessmentFailsForUnavailableWizard(): void
    {
        $this->requireDatabase();
        $result = $this->wizardService->runAssessment('nonexistent');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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
    #[Test]
    public function testAllAvailableWizardsCanRun(): void
    {
        $this->requireDatabase();
        $wizards = $this->wizardService->getAvailableWizards();

        if ($wizards === []) {
            $this->markTestSkipped('No wizards available — required modules may not be active in this environment');
        }

        foreach (array_keys($wizards) as $wizardKey) {
            $result = $this->wizardService->runAssessment($wizardKey);

            $this->assertTrue(
                $result['success'],
                "Wizard '$wizardKey' failed: " . ($result['error'] ?? 'unknown error')
            );
        }
    }

    #[Test]
    public function testIso22301WizardIsAvailableWhenBcmModuleActive(): void
    {
        $this->requireDatabase();
        $config = $this->wizardService->getWizardConfig('iso22301');

        // Wizard only registers when 'bcm' module is active. If inactive in
        // this env we skip — the smoke test still proves no fatal error.
        if ($config === null) {
            $this->markTestSkipped('iso22301 wizard requires the "bcm" module');
        }

        $this->assertSame('ISO22301', $config['code']);
        $this->assertArrayHasKey('categories', $config);
        $this->assertGreaterThanOrEqual(7, count($config['categories']),
            'ISO 22301 should expose at least 7 clauses (4-10)');
        foreach (['context', 'leadership', 'planning', 'support', 'operation', 'evaluation', 'improvement'] as $key) {
            $this->assertArrayHasKey($key, $config['categories'],
                "ISO 22301 missing category '$key'");
        }
    }

    #[Test]
    public function testConsentCoverageCheckReturnsZeroWhenNoConsents(): void
    {
        $this->requireDatabase();

        $reflect = new \ReflectionClass($this->wizardService);
        if (!$reflect->hasMethod('checkConsentCoverage')) {
            $this->fail('ComplianceWizardService::checkConsentCoverage() not implemented');
        }

        $method = $reflect->getMethod('checkConsentCoverage');
        $result = $method->invoke($this->wizardService, ['type' => 'consent_coverage'], null);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('details', $result);
        // No tenant + likely no consents → score is 0 and a gap is reported.
        $this->assertSame(0.0, (float) $result['score']);
        $this->assertArrayHasKey('gap', $result);
    }

    #[Test]
    public function testDsrCoverageCheckReturnsScoreShape(): void
    {
        $this->requireDatabase();

        $reflect = new \ReflectionClass($this->wizardService);
        if (!$reflect->hasMethod('checkDsrCoverage')) {
            $this->fail('ComplianceWizardService::checkDsrCoverage() not implemented');
        }

        $method = $reflect->getMethod('checkDsrCoverage');
        $result = $method->invoke($this->wizardService, ['type' => 'dsr_coverage'], null);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('details', $result);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }
}
