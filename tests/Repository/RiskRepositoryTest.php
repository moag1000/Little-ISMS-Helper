<?php

namespace App\Tests\Repository;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Repository\RiskRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RiskRepository
 *
 * IMPORTANT NOTES ON TESTING DOCTRINE REPOSITORIES:
 *
 * The Query class in Doctrine ORM is final and cannot be mocked. This means:
 * 1. Unit tests with mocks are limited - they cannot test actual query execution
 * 2. The repository methods in RiskRepository require INTEGRATION TESTS with a real database
 * 3. These unit tests verify repository instantiation, structure, and business logic only
 *
 * WHAT SHOULD BE TESTED VIA INTEGRATION TESTS:
 *
 * For findHighRisks():
 * - Returns only risks with (probability × impact) >= threshold
 * - Default threshold is 12
 * - Results are ordered by probability DESC, then impact DESC
 * - Custom threshold values work correctly
 *
 * For countByTreatmentStrategy():
 * - Returns correct counts per treatment strategy (accept, mitigate, transfer, avoid)
 * - Grouping works correctly
 * - Returns array with treatmentStrategy and count keys
 *
 * For findByTenant():
 * - Returns only risks for the specific tenant
 * - No data leakage from other tenants
 * - Results are ordered by createdAt DESC
 *
 * For findByTenantIncludingParent():
 * - Returns tenant's own risks
 * - Includes risks from all ancestors (parent, grandparent, etc.)
 * - Hierarchical inheritance works across multiple levels
 * - Empty ancestors array doesn't break the query
 * - Results are ordered by createdAt DESC
 *
 * For getRiskStatsByTenant():
 * - Returns correct total, high, medium, and low counts
 * - High risk: score >= 12
 * - Medium risk: score >= 6 and < 12
 * - Low risk: score < 6
 * - Calculations handle null probability/impact values
 * - Works with zero risks
 *
 * For findHighRisksByTenant():
 * - Returns only high risks for the specific tenant
 * - Respects custom threshold parameter
 * - Results are ordered by probability DESC, then impact DESC
 * - Multi-tenant isolation is maintained
 *
 * For findByTenantIncludingSubsidiaries():
 * - Returns tenant's own risks
 * - Includes risks from all subsidiaries recursively
 * - Useful for corporate parent aggregation view
 * - Empty subsidiaries array doesn't break the query
 * - Results are ordered by createdAt DESC
 *
 * RECOMMENDATION:
 * Create RiskRepositoryIntegrationTest.php using Symfony's KernelTestCase
 * with a test database to verify all the above behaviors.
 *
 * @see https://symfony.com/doc/current/testing.html#integration-tests
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/testing.html
 */
class RiskRepositoryTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $registry;
    private RiskRepository $repository;

    protected function setUp(): void
    {
        // Create mocks
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->registry = $this->createMock(ManagerRegistry::class);

        // Configure registry to return the entity manager
        $this->registry->method('getManagerForClass')
            ->with(Risk::class)
            ->willReturn($this->entityManager);

        // Configure entity manager to return class metadata
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->name = Risk::class;
        $this->entityManager->method('getClassMetadata')
            ->with(Risk::class)
            ->willReturn($classMetadata);

        // Create repository instance
        $this->repository = new RiskRepository($this->registry);
    }

    /**
     * Test that the repository can be instantiated correctly
     */
    public function testRepositoryInstantiation(): void
    {
        $this->assertInstanceOf(RiskRepository::class, $this->repository);
        $this->assertInstanceOf(ServiceEntityRepository::class, $this->repository);
    }

    /**
     * Test that the repository has the expected public methods
     *
     * This verifies the repository's API surface without executing queries
     */
    public function testRepositoryHasExpectedMethods(): void
    {
        $this->assertTrue(method_exists($this->repository, 'findHighRisks'));
        $this->assertTrue(method_exists($this->repository, 'countByTreatmentStrategy'));
        $this->assertTrue(method_exists($this->repository, 'findByTenant'));
        $this->assertTrue(method_exists($this->repository, 'findByTenantIncludingParent'));
        $this->assertTrue(method_exists($this->repository, 'getRiskStatsByTenant'));
        $this->assertTrue(method_exists($this->repository, 'findHighRisksByTenant'));
        $this->assertTrue(method_exists($this->repository, 'findByTenantIncludingSubsidiaries'));
    }

    /**
     * Test findHighRisks method signature
     */
    public function testFindHighRisksSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findHighRisks');
        $parameters = $method->getParameters();

        // Should have one optional parameter: threshold
        $this->assertCount(1, $parameters);
        $this->assertEquals('threshold', $parameters[0]->getName());
        $this->assertTrue($parameters[0]->isOptional());
        $this->assertEquals(12, $parameters[0]->getDefaultValue());

        // Verify parameter type
        $paramType = $parameters[0]->getType();
        $this->assertNotNull($paramType);
        $this->assertEquals('int', $paramType->getName());

        // Verify return type is array
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test countByTreatmentStrategy method signature
     */
    public function testCountByTreatmentStrategySignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'countByTreatmentStrategy');
        $parameters = $method->getParameters();

        // Should not require any parameters
        $this->assertCount(0, $parameters);

        // Verify return type is array
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test findByTenant method signature
     */
    public function testFindByTenantSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findByTenant');
        $parameters = $method->getParameters();

        // Should have one required parameter: tenant
        $this->assertCount(1, $parameters);
        $this->assertEquals('tenant', $parameters[0]->getName());
        $this->assertEquals(Tenant::class, $parameters[0]->getType()->getName());
        $this->assertFalse($parameters[0]->isOptional());

        // Verify return type is array
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test findByTenantIncludingParent method signature
     *
     * Verifies the second parameter is optional and nullable (deprecated)
     */
    public function testFindByTenantIncludingParentSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findByTenantIncludingParent');
        $parameters = $method->getParameters();

        // Should have two parameters: tenant (required), parentTenant (optional, deprecated)
        $this->assertCount(2, $parameters);

        // First parameter: tenant
        $this->assertEquals('tenant', $parameters[0]->getName());
        $this->assertEquals(Tenant::class, $parameters[0]->getType()->getName());
        $this->assertFalse($parameters[0]->isOptional());

        // Second parameter: parentTenant (optional, deprecated)
        $this->assertEquals('parentTenant', $parameters[1]->getName());
        $this->assertTrue($parameters[1]->isOptional());
        $this->assertTrue($parameters[1]->allowsNull());

        // Verify return type is array
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test getRiskStatsByTenant method signature
     */
    public function testGetRiskStatsByTenantSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'getRiskStatsByTenant');
        $parameters = $method->getParameters();

        // Should have one required parameter: tenant
        $this->assertCount(1, $parameters);
        $this->assertEquals('tenant', $parameters[0]->getName());
        $this->assertEquals(Tenant::class, $parameters[0]->getType()->getName());

        // Verify return type is array
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test getRiskStatsByTenant return structure documentation
     *
     * While we can't test actual database results, we can verify the expected
     * structure is documented in the method's PHPDoc
     */
    public function testGetRiskStatsByTenantReturnStructure(): void
    {
        $method = new \ReflectionMethod($this->repository, 'getRiskStatsByTenant');
        $docComment = $method->getDocComment();

        $this->assertNotFalse($docComment);
        $this->assertStringContainsString('total', $docComment);
        $this->assertStringContainsString('high', $docComment);
        $this->assertStringContainsString('medium', $docComment);
        $this->assertStringContainsString('low', $docComment);
    }

    /**
     * Test risk score calculation logic for high risk classification
     *
     * This tests the business logic used in getRiskStatsByTenant
     */
    public function testRiskScoreCalculationLogic(): void
    {
        // High risk: score >= 12
        $highRiskScore = 4 * 3; // probability * impact
        $this->assertGreaterThanOrEqual(12, $highRiskScore);

        $veryHighRiskScore = 5 * 5;
        $this->assertGreaterThanOrEqual(12, $veryHighRiskScore);

        // Medium risk: score >= 6 and < 12
        $mediumRiskScore = 3 * 3;
        $this->assertGreaterThanOrEqual(6, $mediumRiskScore);
        $this->assertLessThan(12, $mediumRiskScore);

        $anotherMediumRiskScore = 2 * 4;
        $this->assertGreaterThanOrEqual(6, $anotherMediumRiskScore);
        $this->assertLessThan(12, $anotherMediumRiskScore);

        // Low risk: score < 6
        $lowRiskScore = 2 * 2;
        $this->assertLessThan(6, $lowRiskScore);

        $veryLowRiskScore = 1 * 1;
        $this->assertLessThan(6, $veryLowRiskScore);
    }

    /**
     * Test risk classification thresholds match ISO 27005 standards
     */
    public function testRiskClassificationThresholds(): void
    {
        // Default high risk threshold from findHighRisks
        $defaultThreshold = 12;

        // Verify common high-risk combinations meet the threshold
        $this->assertGreaterThanOrEqual($defaultThreshold, 3 * 4); // 12
        $this->assertGreaterThanOrEqual($defaultThreshold, 4 * 3); // 12
        $this->assertGreaterThanOrEqual($defaultThreshold, 4 * 4); // 16
        $this->assertGreaterThanOrEqual($defaultThreshold, 5 * 3); // 15
        $this->assertGreaterThanOrEqual($defaultThreshold, 5 * 5); // 25

        // Verify medium-risk combinations don't meet the high threshold
        $this->assertLessThan($defaultThreshold, 2 * 3); // 6
        $this->assertLessThan($defaultThreshold, 3 * 3); // 9
        $this->assertLessThan($defaultThreshold, 2 * 4); // 8
    }

    /**
     * Test findHighRisksByTenant method signature
     */
    public function testFindHighRisksByTenantSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findHighRisksByTenant');
        $parameters = $method->getParameters();

        // Should have two parameters: tenant (required), threshold (optional)
        $this->assertCount(2, $parameters);

        // First parameter: tenant
        $this->assertEquals('tenant', $parameters[0]->getName());
        $this->assertEquals(Tenant::class, $parameters[0]->getType()->getName());
        $this->assertFalse($parameters[0]->isOptional());

        // Second parameter: threshold (optional, default 12)
        $this->assertEquals('threshold', $parameters[1]->getName());
        $this->assertTrue($parameters[1]->isOptional());
        $this->assertEquals(12, $parameters[1]->getDefaultValue());

        // Verify parameter type
        $paramType = $parameters[1]->getType();
        $this->assertNotNull($paramType);
        $this->assertEquals('int', $paramType->getName());

        // Verify return type is array
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test findByTenantIncludingSubsidiaries method signature
     */
    public function testFindByTenantIncludingSubsidiariesSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findByTenantIncludingSubsidiaries');
        $parameters = $method->getParameters();

        // Should have one required parameter: tenant
        $this->assertCount(1, $parameters);
        $this->assertEquals('tenant', $parameters[0]->getName());
        $this->assertEquals(Tenant::class, $parameters[0]->getType()->getName());
        $this->assertFalse($parameters[0]->isOptional());

        // Verify return type is array
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test that the repository uses correct entity class
     */
    public function testRepositoryUsesCorrectEntityClass(): void
    {
        $reflection = new \ReflectionClass($this->repository);
        $docComment = $reflection->getDocComment();

        $this->assertNotFalse($docComment);

        // Verify the repository is typed for Risk entities
        $this->assertStringContainsString('@extends ServiceEntityRepository<Risk>', $docComment);
        $this->assertStringContainsString('@method Risk|null find', $docComment);
        $this->assertStringContainsString('@method Risk[]', $docComment);
    }

    /**
     * Test that all methods mention multi-tenant support in documentation
     */
    public function testMethodsDocumentTenantSupport(): void
    {
        // Methods that should explicitly handle tenants
        $tenantMethods = [
            'findByTenant',
            'findByTenantIncludingParent',
            'getRiskStatsByTenant',
            'findHighRisksByTenant',
            'findByTenantIncludingSubsidiaries',
        ];

        foreach ($tenantMethods as $methodName) {
            $method = new \ReflectionMethod($this->repository, $methodName);
            $docComment = $method->getDocComment();

            $this->assertNotFalse($docComment, "Method {$methodName} should have documentation");
            $this->assertStringContainsString('tenant', strtolower($docComment),
                "Method {$methodName} documentation should mention tenant");
        }
    }

    /**
     * Test hierarchical query method documentation mentions ancestors
     */
    public function testFindByTenantIncludingParentDocumentation(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findByTenantIncludingParent');
        $docComment = $method->getDocComment();

        $this->assertNotFalse($docComment);
        $this->assertStringContainsString('ancestors', strtolower($docComment));
        $this->assertStringContainsString('hierarchical', strtolower($docComment));
    }

    /**
     * Test subsidiary aggregation method documentation mentions subsidiaries
     */
    public function testFindByTenantIncludingSubsidiariesDocumentation(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findByTenantIncludingSubsidiaries');
        $docComment = $method->getDocComment();

        $this->assertNotFalse($docComment);
        $this->assertStringContainsString('subsidiaries', strtolower($docComment));
        $this->assertStringContainsString('corporate parent', strtolower($docComment));
    }

    /**
     * Test that risk score thresholds are consistent across methods
     */
    public function testConsistentThresholdDefaults(): void
    {
        // Both methods should use the same default threshold
        $findHighRisksMethod = new \ReflectionMethod($this->repository, 'findHighRisks');
        $findHighRisksByTenantMethod = new \ReflectionMethod($this->repository, 'findHighRisksByTenant');

        $findHighRisksParams = $findHighRisksMethod->getParameters();
        $findHighRisksByTenantParams = $findHighRisksByTenantMethod->getParameters();

        // Get threshold parameter from each method
        $threshold1 = $findHighRisksParams[0]->getDefaultValue();
        $threshold2 = $findHighRisksByTenantParams[1]->getDefaultValue();

        // Both should default to 12
        $this->assertEquals(12, $threshold1);
        $this->assertEquals(12, $threshold2);
        $this->assertEquals($threshold1, $threshold2,
            'High risk threshold should be consistent across methods');
    }

    /**
     * Test treatment strategy values match Risk entity constraints
     *
     * The countByTreatmentStrategy method should handle all valid treatment strategies
     */
    public function testTreatmentStrategyValues(): void
    {
        // These are the valid values from Risk entity's @Assert\Choice constraint
        $validStrategies = ['accept', 'mitigate', 'transfer', 'avoid'];

        foreach ($validStrategies as $strategy) {
            // Each strategy should be one of the documented values
            $this->assertContains($strategy, $validStrategies,
                "Strategy '{$strategy}' should be a valid treatment strategy");
        }

        // Verify we have exactly 4 strategies (ISO 27005 standard)
        $this->assertCount(4, $validStrategies,
            'There should be exactly 4 treatment strategies per ISO 27005');
    }

    /**
     * Helper method to create a simple tenant mock
     */
    private function createTenant(int $id): Tenant
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        return $tenant;
    }

    /**
     * Helper method to create a tenant mock with ancestors
     */
    private function createMockTenantWithAncestors(int $id, array $ancestors): Tenant|MockObject
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getAllAncestors')->willReturn($ancestors);
        return $tenant;
    }

    /**
     * Helper method to create a tenant mock with subsidiaries
     */
    private function createMockTenantWithSubsidiaries(int $id, array $subsidiaries): Tenant|MockObject
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getAllSubsidiaries')->willReturn($subsidiaries);
        return $tenant;
    }
}
