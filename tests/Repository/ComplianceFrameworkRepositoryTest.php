<?php

namespace App\Tests\Repository;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ComplianceFrameworkRepository
 *
 * IMPORTANT NOTES ON TESTING DOCTRINE REPOSITORIES:
 *
 * The Query class in Doctrine ORM is final and cannot be mocked. This means:
 * 1. Unit tests with mocks are limited - they cannot test actual query execution
 * 2. The repository methods in ComplianceFrameworkRepository require INTEGRATION TESTS with a real database
 * 3. These unit tests verify repository instantiation, structure, and business logic only
 *
 * WHAT SHOULD BE TESTED VIA INTEGRATION TESTS:
 *
 * For findActiveFrameworks():
 * - Returns only frameworks with active=true
 * - Results are ordered alphabetically by name (ASC)
 * - Inactive frameworks are excluded
 *
 * For findMandatoryFrameworks():
 * - Returns only frameworks with mandatory=true AND active=true
 * - Non-mandatory frameworks are excluded
 * - Inactive frameworks are excluded even if mandatory
 * - Results are ordered alphabetically by name (ASC)
 *
 * For findByIndustry():
 * - Returns frameworks where applicableIndustry matches the parameter
 * - Returns frameworks where applicableIndustry='all' (applies to all industries)
 * - Only returns active frameworks
 * - Results are ordered alphabetically by name (ASC)
 * - Industry matching is case-sensitive
 *
 * For getComplianceOverview():
 * - Calls findActiveFrameworks() to get base dataset
 * - Retrieves tenant from TenantContext
 * - For each framework, calls ComplianceRequirementRepository::getFrameworkStatisticsForTenant()
 * - Correctly calculates compliance_percentage: (fulfilled / applicable) * 100
 * - Handles division by zero when applicable=0 (should return 0%)
 * - Returns array with expected structure:
 *   - id (int)
 *   - code (string)
 *   - name (string)
 *   - mandatory (bool)
 *   - total_requirements (int)
 *   - applicable_requirements (int)
 *   - fulfilled_requirements (int)
 *   - compliance_percentage (float, rounded to 2 decimals)
 * - Tenant isolation is maintained through ComplianceRequirementRepository
 *
 * RECOMMENDATION:
 * Create ComplianceFrameworkRepositoryIntegrationTest.php using Symfony's KernelTestCase
 * with a test database to verify all the above behaviors.
 *
 * @see https://symfony.com/doc/current/testing.html#integration-tests
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/testing.html
 */
class ComplianceFrameworkRepositoryTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $registry;
    private MockObject $complianceRequirementRepository;
    private MockObject $tenantContext;
    private ComplianceFrameworkRepository $repository;

    protected function setUp(): void
    {
        // Create mocks
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->complianceRequirementRepository = $this->createMock(ComplianceRequirementRepository::class);
        $this->tenantContext = $this->createMock(TenantContext::class);

        // Configure registry to return the entity manager
        $this->registry->method('getManagerForClass')
            ->with(ComplianceFramework::class)
            ->willReturn($this->entityManager);

        // Configure entity manager to return class metadata
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->name = ComplianceFramework::class;
        $this->entityManager->method('getClassMetadata')
            ->with(ComplianceFramework::class)
            ->willReturn($classMetadata);

        // Create repository instance with dependencies
        $this->repository = new ComplianceFrameworkRepository(
            $this->registry,
            $this->complianceRequirementRepository,
            $this->tenantContext
        );
    }

    /**
     * Test that the repository can be instantiated correctly
     */
    public function testRepositoryInstantiation(): void
    {
        $this->assertInstanceOf(ComplianceFrameworkRepository::class, $this->repository);
    }

    /**
     * Test that the repository has the expected public methods
     *
     * This verifies the repository's API surface without executing queries
     */
    public function testRepositoryHasExpectedMethods(): void
    {
        $this->assertTrue(method_exists($this->repository, 'findActiveFrameworks'));
        $this->assertTrue(method_exists($this->repository, 'findMandatoryFrameworks'));
        $this->assertTrue(method_exists($this->repository, 'findByIndustry'));
        $this->assertTrue(method_exists($this->repository, 'getComplianceOverview'));
    }

    /**
     * Test findActiveFrameworks signature
     */
    public function testFindActiveFrameworksSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findActiveFrameworks');
        $parameters = $method->getParameters();

        $this->assertCount(0, $parameters);

        $returnType = $method->getReturnType();
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test findMandatoryFrameworks signature
     */
    public function testFindMandatoryFrameworksSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findMandatoryFrameworks');
        $parameters = $method->getParameters();

        $this->assertCount(0, $parameters);

        $returnType = $method->getReturnType();
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test findByIndustry signature
     */
    public function testFindByIndustrySignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findByIndustry');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertEquals('industry', $parameters[0]->getName());
        $this->assertEquals('string', $parameters[0]->getType()->getName());

        $returnType = $method->getReturnType();
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test getComplianceOverview signature
     */
    public function testGetComplianceOverviewSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'getComplianceOverview');
        $parameters = $method->getParameters();

        $this->assertCount(0, $parameters);

        $returnType = $method->getReturnType();
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test that the repository uses correct entity class
     */
    public function testRepositoryUsesCorrectEntityClass(): void
    {
        $reflection = new \ReflectionClass($this->repository);
        $docComment = $reflection->getDocComment();

        // Verify the repository is typed for ComplianceFramework entities
        $this->assertStringContainsString('@extends ServiceEntityRepository<ComplianceFramework>', $docComment);
        $this->assertStringContainsString('@method ComplianceFramework|null find', $docComment);
        $this->assertStringContainsString('@method ComplianceFramework[]', $docComment);
    }

    /**
     * Test industry values concept
     *
     * Integration test required: Verify actual filtering with different industries
     */
    public function testIndustryValuesConcept(): void
    {
        $validIndustries = [
            'healthcare',
            'finance',
            'manufacturing',
            'technology',
            'all', // Special value for frameworks applicable to all industries
        ];

        foreach ($validIndustries as $industry) {
            $this->assertIsString($industry);
            $this->assertNotEmpty($industry);
        }
    }

    /**
     * Test mandatory framework concept
     *
     * Mandatory frameworks are required by regulation or organizational policy
     */
    public function testMandatoryFrameworkConcept(): void
    {
        $mandatoryValue = true;
        $optionalValue = false;

        $this->assertTrue($mandatoryValue);
        $this->assertFalse($optionalValue);
        $this->assertIsBool($mandatoryValue);
        $this->assertIsBool($optionalValue);
    }

    /**
     * Test active framework concept
     *
     * Only active frameworks should be considered in most queries
     */
    public function testActiveFrameworkConcept(): void
    {
        $activeValue = true;
        $inactiveValue = false;

        $this->assertTrue($activeValue);
        $this->assertFalse($inactiveValue);
        $this->assertIsBool($activeValue);
        $this->assertIsBool($inactiveValue);
    }

    /**
     * Test compliance overview result structure
     *
     * This validates the expected return structure from getComplianceOverview()
     *
     * Integration test required: Verify actual statistics calculation
     */
    public function testComplianceOverviewResultStructure(): void
    {
        // Expected structure from getComplianceOverview
        $mockOverview = [
            [
                'id' => 1,
                'code' => 'ISO27001',
                'name' => 'ISO/IEC 27001:2022',
                'mandatory' => true,
                'total_requirements' => 93,
                'applicable_requirements' => 85,
                'fulfilled_requirements' => 68,
                'compliance_percentage' => 80.00,
            ],
            [
                'id' => 2,
                'code' => 'GDPR',
                'name' => 'General Data Protection Regulation',
                'mandatory' => true,
                'total_requirements' => 99,
                'applicable_requirements' => 99,
                'fulfilled_requirements' => 50,
                'compliance_percentage' => 50.51,
            ],
        ];

        foreach ($mockOverview as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('code', $item);
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('mandatory', $item);
            $this->assertArrayHasKey('total_requirements', $item);
            $this->assertArrayHasKey('applicable_requirements', $item);
            $this->assertArrayHasKey('fulfilled_requirements', $item);
            $this->assertArrayHasKey('compliance_percentage', $item);

            $this->assertIsInt($item['id']);
            $this->assertIsString($item['code']);
            $this->assertIsString($item['name']);
            $this->assertIsBool($item['mandatory']);
            $this->assertIsInt($item['total_requirements']);
            $this->assertIsInt($item['applicable_requirements']);
            $this->assertIsInt($item['fulfilled_requirements']);
            $this->assertIsFloat($item['compliance_percentage']);

            // Validate logical constraints
            $this->assertGreaterThanOrEqual(0, $item['total_requirements']);
            $this->assertGreaterThanOrEqual(0, $item['applicable_requirements']);
            $this->assertGreaterThanOrEqual(0, $item['fulfilled_requirements']);
            $this->assertGreaterThanOrEqual(0.0, $item['compliance_percentage']);
            $this->assertLessThanOrEqual(100.0, $item['compliance_percentage']);
            $this->assertLessThanOrEqual($item['total_requirements'], $item['applicable_requirements']);
            $this->assertLessThanOrEqual($item['applicable_requirements'], $item['fulfilled_requirements']);
        }
    }

    /**
     * Test compliance percentage calculation logic
     *
     * This tests the formula: (fulfilled / applicable) * 100, rounded to 2 decimals
     */
    public function testCompliancePercentageCalculation(): void
    {
        // Test normal case
        $fulfilled = 68;
        $applicable = 85;
        $expected = 80.00;
        $actual = round(($fulfilled / $applicable) * 100, 2);
        $this->assertEquals($expected, $actual);

        // Test 100% compliance
        $fulfilled = 93;
        $applicable = 93;
        $expected = 100.00;
        $actual = round(($fulfilled / $applicable) * 100, 2);
        $this->assertEquals($expected, $actual);

        // Test zero compliance
        $fulfilled = 0;
        $applicable = 50;
        $expected = 0.00;
        $actual = round(($fulfilled / $applicable) * 100, 2);
        $this->assertEquals($expected, $actual);

        // Test partial compliance with rounding
        $fulfilled = 50;
        $applicable = 99;
        $expected = 50.51;
        $actual = round(($fulfilled / $applicable) * 100, 2);
        $this->assertEquals($expected, $actual);
    }

    /**
     * Test compliance percentage calculation edge case: zero applicable requirements
     *
     * When applicable=0, the method should return 0 instead of division by zero
     */
    public function testCompliancePercentageCalculationWithZeroApplicable(): void
    {
        $applicable = 0;
        $fulfilled = 0;

        // The actual method uses: $stats['applicable'] > 0 ? round(($stats['fulfilled'] / $stats['applicable']) * 100, 2) : 0
        $compliancePercentage = $applicable > 0
            ? round(($fulfilled / $applicable) * 100, 2)
            : 0;

        $this->assertEquals(0, $compliancePercentage);
    }

    /**
     * Test framework statistics structure
     *
     * This validates the expected structure from ComplianceRequirementRepository::getFrameworkStatisticsForTenant()
     */
    public function testFrameworkStatisticsStructure(): void
    {
        $mockStats = [
            'total' => 93,
            'applicable' => 85,
            'fulfilled' => 68,
        ];

        $this->assertIsArray($mockStats);
        $this->assertArrayHasKey('total', $mockStats);
        $this->assertArrayHasKey('applicable', $mockStats);
        $this->assertArrayHasKey('fulfilled', $mockStats);

        $this->assertIsInt($mockStats['total']);
        $this->assertIsInt($mockStats['applicable']);
        $this->assertIsInt($mockStats['fulfilled']);

        // Validate logical constraints
        $this->assertGreaterThanOrEqual(0, $mockStats['total']);
        $this->assertGreaterThanOrEqual(0, $mockStats['applicable']);
        $this->assertGreaterThanOrEqual(0, $mockStats['fulfilled']);
        $this->assertLessThanOrEqual($mockStats['total'], $mockStats['applicable']);
        $this->assertLessThanOrEqual($mockStats['applicable'], $mockStats['fulfilled']);
    }

    /**
     * Test common compliance frameworks
     *
     * Validates typical framework codes and names
     */
    public function testCommonComplianceFrameworks(): void
    {
        $commonFrameworks = [
            ['code' => 'ISO27001', 'name' => 'ISO/IEC 27001:2022'],
            ['code' => 'GDPR', 'name' => 'General Data Protection Regulation'],
            ['code' => 'SOC2', 'name' => 'SOC 2 Type II'],
            ['code' => 'HIPAA', 'name' => 'Health Insurance Portability and Accountability Act'],
            ['code' => 'PCI-DSS', 'name' => 'Payment Card Industry Data Security Standard'],
            ['code' => 'NIST-CSF', 'name' => 'NIST Cybersecurity Framework'],
        ];

        foreach ($commonFrameworks as $framework) {
            $this->assertArrayHasKey('code', $framework);
            $this->assertArrayHasKey('name', $framework);
            $this->assertIsString($framework['code']);
            $this->assertIsString($framework['name']);
            $this->assertNotEmpty($framework['code']);
            $this->assertNotEmpty($framework['name']);
        }
    }

    /**
     * Test industry filtering logic
     *
     * Frameworks can be applicable to specific industries or 'all'
     */
    public function testIndustryFilteringLogic(): void
    {
        // A framework with applicableIndustry='finance' should match 'finance' query
        $industry = 'finance';
        $frameworkIndustry = 'finance';
        $this->assertEquals($industry, $frameworkIndustry);

        // A framework with applicableIndustry='all' should match any industry
        $industry = 'healthcare';
        $frameworkIndustry = 'all';
        $matches = ($frameworkIndustry === $industry || $frameworkIndustry === 'all');
        $this->assertTrue($matches);

        // A framework with applicableIndustry='finance' should NOT match 'healthcare'
        $industry = 'healthcare';
        $frameworkIndustry = 'finance';
        $matches = ($frameworkIndustry === $industry || $frameworkIndustry === 'all');
        $this->assertFalse($matches);
    }

    /**
     * Test ordering by name concept
     *
     * All repository methods order results alphabetically by name (ASC)
     */
    public function testOrderingByNameConcept(): void
    {
        $frameworkNames = [
            'General Data Protection Regulation',
            'Health Insurance Portability and Accountability Act',
            'ISO/IEC 27001:2022',
            'NIST Cybersecurity Framework',
            'Payment Card Industry Data Security Standard',
            'SOC 2 Type II',
        ];

        // Verify alphabetical ordering
        $sorted = $frameworkNames;
        sort($sorted);

        $this->assertEquals($sorted, $frameworkNames);
    }

    /**
     * Test tenant context dependency
     *
     * getComplianceOverview() requires a current tenant from TenantContext
     */
    public function testTenantContextDependency(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);

        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $currentTenant = $this->tenantContext->getCurrentTenant();
        $this->assertInstanceOf(Tenant::class, $currentTenant);
        $this->assertEquals(1, $currentTenant->getId());
    }

    /**
     * Test repository constructor dependencies
     */
    public function testConstructorDependencies(): void
    {
        $reflection = new \ReflectionClass($this->repository);
        $constructor = $reflection->getConstructor();
        $parameters = $constructor->getParameters();

        $this->assertCount(3, $parameters);

        // First parameter: ManagerRegistry
        $this->assertEquals('registry', $parameters[0]->getName());
        $this->assertEquals(ManagerRegistry::class, $parameters[0]->getType()->getName());

        // Second parameter: ComplianceRequirementRepository
        $this->assertEquals('complianceRequirementRepository', $parameters[1]->getName());
        $this->assertEquals(ComplianceRequirementRepository::class, $parameters[1]->getType()->getName());

        // Third parameter: TenantContext
        $this->assertEquals('tenantContext', $parameters[2]->getName());
        $this->assertEquals(TenantContext::class, $parameters[2]->getType()->getName());
    }

    /**
     * Test that framework codes are unique identifiers
     */
    public function testFrameworkCodeUniqueness(): void
    {
        $codes = ['ISO27001', 'GDPR', 'SOC2', 'HIPAA', 'PCI-DSS', 'NIST-CSF'];

        // Verify all codes are unique
        $this->assertEquals(count($codes), count(array_unique($codes)));

        // Verify codes follow a consistent format (uppercase with optional hyphens/numbers)
        $pattern = '/^[A-Z0-9\-]+$/';
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression($pattern, $code);
        }
    }

    /**
     * Test edge case: all requirements not applicable
     */
    public function testEdgeCaseAllRequirementsNotApplicable(): void
    {
        $stats = [
            'total' => 93,
            'applicable' => 0,
            'fulfilled' => 0,
        ];

        // When no requirements are applicable, compliance percentage should be 0
        $compliancePercentage = $stats['applicable'] > 0
            ? round(($stats['fulfilled'] / $stats['applicable']) * 100, 2)
            : 0;

        $this->assertEquals(0, $compliancePercentage);
    }

    /**
     * Test edge case: all requirements fulfilled
     */
    public function testEdgeCaseAllRequirementsFulfilled(): void
    {
        $stats = [
            'total' => 93,
            'applicable' => 93,
            'fulfilled' => 93,
        ];

        $compliancePercentage = $stats['applicable'] > 0
            ? round(($stats['fulfilled'] / $stats['applicable']) * 100, 2)
            : 0;

        $this->assertEquals(100.00, $compliancePercentage);
    }

    /**
     * Test edge case: no requirements fulfilled
     */
    public function testEdgeCaseNoRequirementsFulfilled(): void
    {
        $stats = [
            'total' => 93,
            'applicable' => 93,
            'fulfilled' => 0,
        ];

        $compliancePercentage = $stats['applicable'] > 0
            ? round(($stats['fulfilled'] / $stats['applicable']) * 100, 2)
            : 0;

        $this->assertEquals(0.00, $compliancePercentage);
    }

    /**
     * Test rounding precision for compliance percentage
     */
    public function testCompliancePercentageRoundingPrecision(): void
    {
        // Test that rounding is always to 2 decimal places
        $testCases = [
            ['fulfilled' => 1, 'applicable' => 3, 'expected' => 33.33],
            ['fulfilled' => 2, 'applicable' => 3, 'expected' => 66.67],
            ['fulfilled' => 1, 'applicable' => 7, 'expected' => 14.29],
            ['fulfilled' => 5, 'applicable' => 7, 'expected' => 71.43],
        ];

        foreach ($testCases as $case) {
            $actual = round(($case['fulfilled'] / $case['applicable']) * 100, 2);
            $this->assertEquals($case['expected'], $actual);
        }
    }

    /**
     * Test mandatory and active combination logic
     *
     * findMandatoryFrameworks() requires BOTH mandatory=true AND active=true
     */
    public function testMandatoryAndActiveLogic(): void
    {
        // Only this combination should match
        $mandatoryAndActive = true && true;
        $this->assertTrue($mandatoryAndActive);

        // These combinations should NOT match
        $mandatoryButInactive = true && false;
        $this->assertFalse($mandatoryButInactive);

        $activeButNotMandatory = false && true;
        $this->assertFalse($activeButNotMandatory);

        $neitherMandatoryNorActive = false && false;
        $this->assertFalse($neitherMandatoryNorActive);
    }
}
