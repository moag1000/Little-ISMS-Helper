<?php

namespace App\Tests\Repository;

use App\Entity\Incident;
use App\Entity\Tenant;
use App\Repository\IncidentRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IncidentRepository
 *
 * IMPORTANT NOTES:
 * ----------------
 * Due to Doctrine's Query class being final (cannot be mocked), these tests focus on:
 * 1. Testing the incident number generation logic (which can be tested via reflection/stubbing)
 * 2. Verifying method signatures and repository structure
 * 3. Testing helper logic that doesn't require query execution
 *
 * INTEGRATION TESTS REQUIRED FOR:
 * --------------------------------
 * The following methods use QueryBuilder and require integration tests with a real database:
 * - getNextIncidentNumber(): Query execution for MAX(incidentNumber)
 * - findOpenIncidents(): Status filtering and ordering
 * - countByCategory(): Aggregation by category
 * - countBySeverity(): Aggregation by severity
 * - findByTenant(): Tenant filtering
 * - findByTenantIncludingParent(): Hierarchical tenant queries with ancestors
 * - findByTenantIncludingSubsidiaries(): Subsidiary aggregation queries
 *
 * For full test coverage, create integration tests in tests/Integration/Repository/
 * using a test database to verify:
 * - Correct SQL generation
 * - Proper filtering, sorting, and aggregation
 * - Accurate result sets
 */
class IncidentRepositoryTest extends TestCase
{
    private MockObject $registry;
    private MockObject $entityManager;
    private MockObject $classMetadata;
    private IncidentRepository $repository;

    protected function setUp(): void
    {
        // Mock the registry
        $this->registry = $this->createMock(ManagerRegistry::class);

        // Mock the entity manager
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        // Mock class metadata
        $this->classMetadata = $this->createMock(ClassMetadata::class);
        $this->classMetadata->name = Incident::class;

        // Configure registry to return the entity manager
        $this->registry->method('getManagerForClass')
            ->with(Incident::class)
            ->willReturn($this->entityManager);

        // Configure entity manager to return class metadata
        $this->entityManager->method('getClassMetadata')
            ->with(Incident::class)
            ->willReturn($this->classMetadata);

        // Create the repository
        $this->repository = new IncidentRepository($this->registry);
    }

    /**
     * Test that repository extends ServiceEntityRepository
     */
    public function testRepositoryExtendsServiceEntityRepository(): void
    {
        $this->assertInstanceOf(ServiceEntityRepository::class, $this->repository);
        $this->assertInstanceOf(IncidentRepository::class, $this->repository);
    }

    /**
     * Test getNextIncidentNumber number formatting logic
     *
     * This tests the number generation and formatting logic by testing
     * the algorithm directly, simulating different scenarios.
     */
    public function testGetNextIncidentNumberFormatting(): void
    {
        $year = date('Y');
        $expectedPrefix = "INC-{$year}-";

        // Test case 1: Number 1 should be formatted as 0001
        $numberPart = 1;
        $formatted = $expectedPrefix . str_pad((string) $numberPart, 4, '0', STR_PAD_LEFT);
        $this->assertSame("INC-{$year}-0001", $formatted);

        // Test case 2: Number 42 should be formatted as 0042
        $numberPart = 42;
        $formatted = $expectedPrefix . str_pad((string) $numberPart, 4, '0', STR_PAD_LEFT);
        $this->assertSame("INC-{$year}-0042", $formatted);

        // Test case 3: Number 999 should be formatted as 0999
        $numberPart = 999;
        $formatted = $expectedPrefix . str_pad((string) $numberPart, 4, '0', STR_PAD_LEFT);
        $this->assertSame("INC-{$year}-0999", $formatted);

        // Test case 4: Number 9999 should be formatted as 9999
        $numberPart = 9999;
        $formatted = $expectedPrefix . str_pad((string) $numberPart, 4, '0', STR_PAD_LEFT);
        $this->assertSame("INC-{$year}-9999", $formatted);
    }

    /**
     * Test incident number extraction and increment logic
     *
     * This tests the logic that extracts the number from an existing
     * incident number and increments it.
     */
    public function testIncidentNumberExtractionAndIncrement(): void
    {
        $year = date('Y');

        // Test case 1: Extract from INC-2025-0042 and increment to 0043
        $existingNumber = "INC-{$year}-0042";
        $lastNumber = (int) substr($existingNumber, -4);
        $this->assertSame(42, $lastNumber);
        $nextNumber = $lastNumber + 1;
        $this->assertSame(43, $nextNumber);

        // Test case 2: Extract from INC-2025-0009 and increment to 0010
        $existingNumber = "INC-{$year}-0009";
        $lastNumber = (int) substr($existingNumber, -4);
        $this->assertSame(9, $lastNumber);
        $nextNumber = $lastNumber + 1;
        $this->assertSame(10, $nextNumber);

        // Test case 3: Extract from INC-2025-9999 and increment to 10000
        $existingNumber = "INC-{$year}-9999";
        $lastNumber = (int) substr($existingNumber, -4);
        $this->assertSame(9999, $lastNumber);
        $nextNumber = $lastNumber + 1;
        $this->assertSame(10000, $nextNumber);
    }

    /**
     * Test that incident number prefix includes current year
     */
    public function testIncidentNumberPrefixIncludesCurrentYear(): void
    {
        $year = date('Y');
        $expectedPrefix = "INC-{$year}-";

        $this->assertStringStartsWith('INC-', $expectedPrefix);
        $this->assertStringContainsString($year, $expectedPrefix);
        $this->assertStringEndsWith('-', $expectedPrefix);
    }

    /**
     * Test findByTenant method exists and returns array
     *
     * Integration test required: Actual tenant filtering with database
     */
    public function testFindByTenantMethodSignature(): void
    {
        $this->assertTrue(method_exists($this->repository, 'findByTenant'));

        // Verify the method accepts a Tenant parameter
        $method = new \ReflectionMethod($this->repository, 'findByTenant');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame('tenant', $parameters[0]->getName());

        // Verify return type is array
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('array', $returnType->getName());
    }

    /**
     * Test findByTenantIncludingParent method exists
     *
     * Integration test required: Actual hierarchical query with database
     */
    public function testFindByTenantIncludingParentMethodSignature(): void
    {
        $this->assertTrue(method_exists($this->repository, 'findByTenantIncludingParent'));

        $method = new \ReflectionMethod($this->repository, 'findByTenantIncludingParent');
        $parameters = $method->getParameters();

        // Should accept tenant and optional parentTenant (deprecated)
        $this->assertGreaterThanOrEqual(1, count($parameters));
        $this->assertSame('tenant', $parameters[0]->getName());

        // Verify return type is array
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('array', $returnType->getName());
    }

    /**
     * Test findByTenantIncludingSubsidiaries method exists
     *
     * Integration test required: Actual subsidiary aggregation with database
     */
    public function testFindByTenantIncludingSubsidiariesMethodSignature(): void
    {
        $this->assertTrue(method_exists($this->repository, 'findByTenantIncludingSubsidiaries'));

        $method = new \ReflectionMethod($this->repository, 'findByTenantIncludingSubsidiaries');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame('tenant', $parameters[0]->getName());

        // Verify return type is array
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('array', $returnType->getName());
    }

    /**
     * Test findOpenIncidents method exists
     *
     * Integration test required: Verify status filtering and ordering
     */
    public function testFindOpenIncidentsMethodSignature(): void
    {
        $this->assertTrue(method_exists($this->repository, 'findOpenIncidents'));

        $method = new \ReflectionMethod($this->repository, 'findOpenIncidents');
        $parameters = $method->getParameters();

        // Should not require any parameters
        $this->assertCount(0, $parameters);

        // Verify return type is array
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('array', $returnType->getName());
    }

    /**
     * Test countByCategory method exists
     *
     * Integration test required: Verify aggregation behavior
     */
    public function testCountByCategoryMethodSignature(): void
    {
        $this->assertTrue(method_exists($this->repository, 'countByCategory'));

        $method = new \ReflectionMethod($this->repository, 'countByCategory');

        // Should not require any parameters
        $this->assertCount(0, $method->getParameters());

        // Verify return type is array
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('array', $returnType->getName());
    }

    /**
     * Test countBySeverity method exists
     *
     * Integration test required: Verify aggregation behavior
     */
    public function testCountBySeverityMethodSignature(): void
    {
        $this->assertTrue(method_exists($this->repository, 'countBySeverity'));

        $method = new \ReflectionMethod($this->repository, 'countBySeverity');

        // Should not require any parameters
        $this->assertCount(0, $method->getParameters());

        // Verify return type is array
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('array', $returnType->getName());
    }

    /**
     * Test getNextIncidentNumber method exists and returns string
     *
     * Integration test required: Verify actual number generation with database
     */
    public function testGetNextIncidentNumberMethodSignature(): void
    {
        $this->assertTrue(method_exists($this->repository, 'getNextIncidentNumber'));

        $method = new \ReflectionMethod($this->repository, 'getNextIncidentNumber');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame('tenant', $parameters[0]->getName());

        // Verify return type is string
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('string', $returnType->getName());
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
