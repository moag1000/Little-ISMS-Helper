<?php

namespace App\Tests\Repository;

use App\Entity\Asset;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AssetRepository
 *
 * IMPORTANT NOTES ON TESTING DOCTRINE REPOSITORIES:
 *
 * The Query class in Doctrine ORM is final and cannot be mocked. This means:
 * 1. Unit tests with mocks are limited - they cannot test actual query execution
 * 2. The repository methods in AssetRepository require INTEGRATION TESTS with a real database
 * 3. These unit tests verify repository instantiation and structure only
 *
 * WHAT SHOULD BE TESTED VIA INTEGRATION TESTS:
 *
 * For findActiveAssets():
 * - Returns only assets with status='active'
 * - Results are ordered alphabetically by name
 * - Multi-tenant isolation is maintained
 *
 * For countByType():
 * - Returns correct counts per asset type
 * - Only counts active assets
 * - Grouping works correctly
 *
 * For findByTenant():
 * - Returns only assets for the specific tenant
 * - No data leakage from other tenants
 * - Results are ordered by name
 *
 * For findByTenantIncludingParent():
 * - Returns tenant's own assets
 * - Includes assets from all ancestors (parent, grandparent, etc.)
 * - Hierarchical inheritance works across multiple levels
 * - Empty ancestors array doesn't break the query
 *
 * For getAssetStatsByTenant():
 * - Returns correct total, active, and inactive counts
 * - Calculations are accurate
 * - Works with zero assets
 *
 * For findActiveAssetsByTenant():
 * - Returns only active assets for the tenant
 * - Excludes inactive assets
 * - Results are ordered by name
 *
 * For findByTenantIncludingSubsidiaries():
 * - Returns tenant's own assets
 * - Includes assets from all subsidiaries recursively
 * - Useful for corporate parent aggregation view
 * - Empty subsidiaries array doesn't break the query
 *
 * RECOMMENDATION:
 * Create AssetRepositoryIntegrationTest.php using Symfony's KernelTestCase
 * with a test database to verify all the above behaviors.
 *
 * @see https://symfony.com/doc/current/testing.html#integration-tests
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/testing.html
 */
class AssetRepositoryTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $registry;
    private AssetRepository $repository;

    protected function setUp(): void
    {
        // Create mocks
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->registry = $this->createMock(ManagerRegistry::class);

        // Configure registry to return the entity manager
        $this->registry->method('getManagerForClass')
            ->with(Asset::class)
            ->willReturn($this->entityManager);

        // Configure entity manager to return class metadata
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->name = Asset::class;
        $this->entityManager->method('getClassMetadata')
            ->with(Asset::class)
            ->willReturn($classMetadata);

        // Create repository instance
        $this->repository = new AssetRepository($this->registry);
    }

    /**
     * Test that the repository can be instantiated correctly
     */
    public function testRepositoryInstantiation(): void
    {
        $this->assertInstanceOf(AssetRepository::class, $this->repository);
    }

    /**
     * Test that the repository has the expected public methods
     *
     * This verifies the repository's API surface without executing queries
     */
    public function testRepositoryHasExpectedMethods(): void
    {
        $this->assertTrue(method_exists($this->repository, 'findActiveAssets'));
        $this->assertTrue(method_exists($this->repository, 'countByType'));
        $this->assertTrue(method_exists($this->repository, 'findByTenant'));
        $this->assertTrue(method_exists($this->repository, 'findByTenantIncludingParent'));
        $this->assertTrue(method_exists($this->repository, 'getAssetStatsByTenant'));
        $this->assertTrue(method_exists($this->repository, 'findActiveAssetsByTenant'));
        $this->assertTrue(method_exists($this->repository, 'findByTenantIncludingSubsidiaries'));
    }

    /**
     * Test that method signatures are correct
     *
     * Uses reflection to verify parameter types and return types without executing
     */
    public function testFindByTenantSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findByTenant');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertEquals('tenant', $parameters[0]->getName());
        $this->assertEquals(Tenant::class, $parameters[0]->getType()->getName());

        $returnType = $method->getReturnType();
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test findByTenantIncludingParent signature
     *
     * Verifies the second parameter is optional and nullable (deprecated)
     */
    public function testFindByTenantIncludingParentSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findByTenantIncludingParent');
        $parameters = $method->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertEquals('tenant', $parameters[0]->getName());
        $this->assertEquals(Tenant::class, $parameters[0]->getType()->getName());

        // Second parameter should be optional (deprecated)
        $this->assertTrue($parameters[1]->isOptional());
        $this->assertTrue($parameters[1]->allowsNull());

        $returnType = $method->getReturnType();
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test findActiveAssets signature
     */
    public function testFindActiveAssetsSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findActiveAssets');
        $parameters = $method->getParameters();

        $this->assertCount(0, $parameters);

        $returnType = $method->getReturnType();
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test countByType signature
     */
    public function testCountByTypeSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'countByType');
        $parameters = $method->getParameters();

        $this->assertCount(0, $parameters);

        $returnType = $method->getReturnType();
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test getAssetStatsByTenant signature
     */
    public function testGetAssetStatsByTenantSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'getAssetStatsByTenant');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertEquals('tenant', $parameters[0]->getName());
        $this->assertEquals(Tenant::class, $parameters[0]->getType()->getName());

        $returnType = $method->getReturnType();
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test findActiveAssetsByTenant signature
     */
    public function testFindActiveAssetsByTenantSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findActiveAssetsByTenant');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertEquals('tenant', $parameters[0]->getName());
        $this->assertEquals(Tenant::class, $parameters[0]->getType()->getName());

        $returnType = $method->getReturnType();
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test findByTenantIncludingSubsidiaries signature
     */
    public function testFindByTenantIncludingSubsidiariesSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findByTenantIncludingSubsidiaries');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertEquals('tenant', $parameters[0]->getName());
        $this->assertEquals(Tenant::class, $parameters[0]->getType()->getName());

        $returnType = $method->getReturnType();
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test that the repository methods use correct entity class
     */
    public function testRepositoryUsesCorrectEntityClass(): void
    {
        $reflection = new \ReflectionClass($this->repository);
        $docComment = $reflection->getDocComment();

        // Verify the repository is typed for Asset entities
        $this->assertStringContainsString('@extends ServiceEntityRepository<Asset>', $docComment);
        $this->assertStringContainsString('@method Asset|null find', $docComment);
        $this->assertStringContainsString('@method Asset[]', $docComment);
    }
}
