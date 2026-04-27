<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\ComplianceFrameworkLoaderService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for ComplianceFrameworkLoaderService
 *
 * Tests for loadFramework() method are skipped in unit tests because they require
 * actual Command instances. These should be tested via integration tests with the
 * real container.
 */
#[AllowMockObjectsWithoutExpectations]
class ComplianceFrameworkLoaderServiceTest extends KernelTestCase
{
    private ComplianceFrameworkLoaderService $service;
    private MockObject $frameworkRepository;

    protected function setUp(): void
    {
        // Ensure kernel is not already booted from a previous test
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = static::getContainer();

        // Get the service from the container
        $this->service = $container->get(ComplianceFrameworkLoaderService::class);

        // Create a mock repository for statistics tests
        $this->frameworkRepository = $this->createMock(ComplianceFrameworkRepository::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Ensure kernel is properly shut down after each test
        self::ensureKernelShutdown();
    }

    #[Test]
    public function testGetAvailableFrameworksReturnsAllFrameworks(): void
    {
        $frameworks = $this->service->getAvailableFrameworks();

        $this->assertIsArray($frameworks);
        $this->assertCount(23, $frameworks);

        // Verify TISAX framework structure
        $this->assertEquals('TISAX', $frameworks[0]['code']);
        $this->assertEquals('TISAX (Trusted Information Security Assessment Exchange)', $frameworks[0]['name']);
        $this->assertFalse($frameworks[0]['mandatory']);
        $this->assertEquals('automotive', $frameworks[0]['industry']);
    }

    #[Test]
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

    #[Test]
    public function testGetFrameworkStatistics(): void
    {
        $stats = $this->service->getFrameworkStatistics();

        $this->assertEquals(23, $stats['total_available']);
        $this->assertArrayHasKey('total_loaded', $stats);
        $this->assertArrayHasKey('total_not_loaded', $stats);
        $this->assertArrayHasKey('compliance_percentage', $stats);
        $this->assertArrayHasKey('mandatory_frameworks', $stats);
        $this->assertArrayHasKey('mandatory_loaded', $stats);
        $this->assertArrayHasKey('mandatory_not_loaded', $stats);

        // Verify mandatory frameworks count (Sprint-8-Semantik: mandatory=true heißt
        // nur noch UNIVERSELL pflichtig — nur GDPR erfüllt das. Branchen-/
        // größenabhängige Frameworks wandern auf applicability=conditional.)
        $this->assertEquals(1, $stats['mandatory_frameworks']);
    }

    #[Test]
    public function testLoadFrameworkWithInvalidCode(): void
    {
        $result = $this->service->loadFramework('INVALID_CODE');

        $this->assertFalse($result['success']);
        $this->assertEquals('Framework not found', $result['message']);
    }

    #[Test]
    public function testGetAvailableFrameworksContainsExpectedCodes(): void
    {
        $frameworks = $this->service->getAvailableFrameworks();
        $codes = array_column($frameworks, 'code');

        $expectedCodes = [
            'TISAX', 'DORA', 'NIS2', 'BSI_GRUNDSCHUTZ', 'GDPR',
            'ISO27001', 'ISO27701', 'ISO27701_2025', 'BSI-C5', 'BSI-C5-2026',
            'KRITIS', 'KRITIS-HEALTH', 'DIGAV', 'TKG-2024', 'GXP',
            'SOC2', 'NIST-CSF', 'CIS-CONTROLS', 'ISO-22301', 'ISO27005',
            'BDSG', 'EU-AI-ACT', 'NIS2UMSUCG',
        ];

        foreach ($expectedCodes as $expectedCode) {
            $this->assertContains($expectedCode, $codes, "Expected code '$expectedCode' not found in frameworks");
        }
    }

    #[Test]
    public function testApplicabilityIsCorrectlyClassified(): void
    {
        $frameworks = $this->service->getAvailableFrameworks();

        // Post-Sprint: `mandatory=true` heißt jetzt UNIVERSELL pflichtig (unabhängig
        // von Branche/Größe). Nur GDPR erfüllt das. Alles andere wandert auf
        // `applicability: conditional` (branchen-/größenabhängig) oder
        // `applicability: voluntary` (freiwillig).
        $universal = ['GDPR'];
        $conditional = [
            'TISAX', 'DORA', 'NIS2', 'BSI_GRUNDSCHUTZ', 'KRITIS', 'KRITIS-HEALTH',
            'DIGAV', 'TKG-2024', 'GXP', 'BDSG', 'EU-AI-ACT', 'NIS2UMSUCG',
        ];
        $voluntary = [
            'ISO27001', 'ISO27701', 'ISO27701_2025', 'BSI-C5', 'BSI-C5-2026',
            'SOC2', 'NIST-CSF', 'CIS-CONTROLS', 'ISO-22301', 'ISO27005',
        ];

        foreach ($frameworks as $framework) {
            $code = $framework['code'];
            $this->assertArrayHasKey('applicability', $framework, "Framework {$code} must carry applicability");

            if (in_array($code, $universal, true)) {
                $this->assertSame('universal', $framework['applicability'], "{$code} should be universal");
                $this->assertTrue($framework['mandatory'], "{$code} (universal) keeps mandatory=true");
            } elseif (in_array($code, $conditional, true)) {
                $this->assertSame('conditional', $framework['applicability'], "{$code} should be conditional");
                $this->assertFalse($framework['mandatory'], "{$code} (conditional) must have mandatory=false");
                $this->assertNotNull($framework['applicability_condition_key'], "{$code} needs a condition translation key");
            } elseif (in_array($code, $voluntary, true)) {
                $this->assertSame('voluntary', $framework['applicability'], "{$code} should be voluntary");
                $this->assertFalse($framework['mandatory'], "{$code} (voluntary) keeps mandatory=false");
            } else {
                $this->fail("Framework {$code} is not classified in universal/conditional/voluntary sets — update the test.");
            }
        }
    }

    #[Test]
    public function testFrameworksHaveRequiredModules(): void
    {
        $frameworks = $this->service->getAvailableFrameworks();

        foreach ($frameworks as $framework) {
            $this->assertIsArray($framework['required_modules']);
            $this->assertNotEmpty($framework['required_modules'], "Framework {$framework['code']} should have required modules");
            $this->assertContains('compliance', $framework['required_modules'], "All frameworks should require 'compliance' module");
        }
    }

    #[Test]
    public function testFrameworksHaveIcons(): void
    {
        $frameworks = $this->service->getAvailableFrameworks();

        foreach ($frameworks as $framework) {
            $this->assertNotEmpty($framework['icon'], "Framework {$framework['code']} should have an icon");
        }
    }

    #[Test]
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
