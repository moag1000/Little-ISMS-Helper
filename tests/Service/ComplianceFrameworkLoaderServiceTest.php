<?php

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\ComplianceFrameworkLoaderService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests for ComplianceFrameworkLoaderService
 *
 * Tests for loadFramework() method are skipped in unit tests because they require
 * actual Command instances. These should be tested via integration tests with the
 * real container.
 */
class ComplianceFrameworkLoaderServiceTest extends KernelTestCase
{
    private ComplianceFrameworkLoaderService $service;
    private MockObject $frameworkRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        // Get the service from the container
        $this->service = $container->get(ComplianceFrameworkLoaderService::class);

        // Create a mock repository for statistics tests
        $this->frameworkRepository = $this->createMock(ComplianceFrameworkRepository::class);
    }

    public function testGetAvailableFrameworksReturnsAllFrameworks(): void
    {
        $frameworks = $this->service->getAvailableFrameworks();

        $this->assertIsArray($frameworks);
        $this->assertCount(15, $frameworks);

        // Verify TISAX framework structure
        $this->assertEquals('TISAX', $frameworks[0]['code']);
        $this->assertEquals('TISAX (Trusted Information Security Assessment Exchange)', $frameworks[0]['name']);
        $this->assertFalse($frameworks[0]['mandatory']);
        $this->assertEquals('automotive', $frameworks[0]['industry']);
    }

    public function testGetAvailableFrameworksIncludesAllRequiredFields(): void
    {
        $frameworks = $this->service->getAvailableFrameworks();

        foreach ($frameworks as $framework) {
            $this->assertArrayHasKey('code', $framework);
            $this->assertArrayHasKey('name', $framework);
            $this->assertArrayHasKey('description', $framework);
            $this->assertArrayHasKey('industry', $framework);
            $this->assertArrayHasKey('regulatory_body', $framework);
            $this->assertArrayHasKey('mandatory', $framework);
            $this->assertArrayHasKey('version', $framework);
            $this->assertArrayHasKey('loaded', $framework);
            $this->assertArrayHasKey('icon', $framework);
            $this->assertArrayHasKey('required_modules', $framework);
        }
    }

    public function testGetFrameworkStatistics(): void
    {
        $stats = $this->service->getFrameworkStatistics();

        $this->assertEquals(15, $stats['total_available']);
        $this->assertArrayHasKey('total_loaded', $stats);
        $this->assertArrayHasKey('total_not_loaded', $stats);
        $this->assertArrayHasKey('compliance_percentage', $stats);
        $this->assertArrayHasKey('mandatory_frameworks', $stats);
        $this->assertArrayHasKey('mandatory_loaded', $stats);
        $this->assertArrayHasKey('mandatory_not_loaded', $stats);

        // Verify mandatory frameworks count (DORA, NIS2, GDPR, KRITIS, KRITIS-HEALTH, DIGAV, TKG-2024, GXP = 8)
        $this->assertEquals(8, $stats['mandatory_frameworks']);
    }

    public function testLoadFrameworkWithInvalidCode(): void
    {
        $result = $this->service->loadFramework('INVALID_CODE');

        $this->assertFalse($result['success']);
        $this->assertEquals('Framework not found', $result['message']);
    }

    public function testGetAvailableFrameworksContainsExpectedCodes(): void
    {
        $frameworks = $this->service->getAvailableFrameworks();
        $codes = array_column($frameworks, 'code');

        $expectedCodes = [
            'TISAX', 'DORA', 'NIS2', 'BSI_GRUNDSCHUTZ', 'GDPR',
            'ISO27001', 'ISO27701', 'ISO27701_2025', 'BSI-C5', 'BSI-C5-2025',
            'KRITIS', 'KRITIS-HEALTH', 'DIGAV', 'TKG-2024', 'GXP'
        ];

        foreach ($expectedCodes as $expectedCode) {
            $this->assertContains($expectedCode, $codes, "Expected code '$expectedCode' not found in frameworks");
        }
    }

    public function testMandatoryFrameworksAreCorrectlyMarked(): void
    {
        $frameworks = $this->service->getAvailableFrameworks();

        $mandatoryCodes = ['DORA', 'NIS2', 'GDPR', 'KRITIS', 'KRITIS-HEALTH', 'DIGAV', 'TKG-2024', 'GXP'];
        $optionalCodes = ['TISAX', 'BSI_GRUNDSCHUTZ', 'ISO27001', 'ISO27701', 'ISO27701_2025', 'BSI-C5', 'BSI-C5-2025'];

        foreach ($frameworks as $framework) {
            if (in_array($framework['code'], $mandatoryCodes)) {
                $this->assertTrue($framework['mandatory'], "Framework {$framework['code']} should be mandatory");
            } else {
                $this->assertFalse($framework['mandatory'], "Framework {$framework['code']} should not be mandatory");
            }
        }
    }

    public function testFrameworksHaveRequiredModules(): void
    {
        $frameworks = $this->service->getAvailableFrameworks();

        foreach ($frameworks as $framework) {
            $this->assertIsArray($framework['required_modules']);
            $this->assertNotEmpty($framework['required_modules'], "Framework {$framework['code']} should have required modules");
            $this->assertContains('compliance', $framework['required_modules'], "All frameworks should require 'compliance' module");
        }
    }

    public function testFrameworksHaveIcons(): void
    {
        $frameworks = $this->service->getAvailableFrameworks();

        foreach ($frameworks as $framework) {
            $this->assertNotEmpty($framework['icon'], "Framework {$framework['code']} should have an icon");
        }
    }

    public function testFrameworkIndustryCategories(): void
    {
        $frameworks = $this->service->getAvailableFrameworks();
        $validIndustries = ['automotive', 'financial_services', 'all', 'all_sectors', 'healthcare', 'telecommunications', 'pharmaceutical', 'critical_infrastructure', 'cloud_services'];

        foreach ($frameworks as $framework) {
            $this->assertContains(
                $framework['industry'],
                $validIndustries,
                "Framework {$framework['code']} has invalid industry: {$framework['industry']}"
            );
        }
    }
}
