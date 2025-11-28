<?php

namespace App\Tests\Repository;

use App\Entity\Role;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Unit tests for UserRepository
 *
 * IMPORTANT NOTES ON TESTING DOCTRINE REPOSITORIES:
 *
 * The Query class in Doctrine ORM is final and cannot be mocked. This means:
 * 1. Unit tests with mocks are limited - they cannot test actual query execution
 * 2. The repository methods in UserRepository require INTEGRATION TESTS with a real database
 * 3. These unit tests verify repository instantiation, method signatures, and business logic only
 *
 * WHAT SHOULD BE TESTED VIA INTEGRATION TESTS:
 *
 * For upgradePassword():
 * - Password is updated correctly on User entity
 * - Changes are persisted to database
 * - UnsupportedUserException thrown for non-User instances
 * - Password hash is updated in database
 *
 * For findByAzureObjectId():
 * - Returns user when Azure Object ID matches
 * - Returns null when no match found
 * - Case sensitivity handling
 * - Unique constraint enforcement
 *
 * For findOrCreateFromAzure():
 * - Finds existing user by Azure Object ID first
 * - Falls back to email if Azure ID not found
 * - Creates new user if neither match
 * - New users are pre-verified (isVerified = true)
 * - Existing users are not duplicated
 *
 * For findActiveUsers():
 * - Returns only users with isActive = true
 * - Excludes inactive users
 * - Results ordered by lastName, firstName ASC
 * - Empty array when no active users
 *
 * For findByRole():
 * - Returns users with specified role in roles array
 * - LIKE query handles JSON array format correctly
 * - Results ordered by lastName, firstName ASC
 * - Works with ROLE_USER, ROLE_ADMIN, etc.
 *
 * For findByCustomRole():
 * - Returns users with specified custom role relationship
 * - JOIN on customRoles collection works correctly
 * - Results ordered by lastName, firstName ASC
 * - Empty array when no users have the role
 *
 * For searchUsers():
 * - Searches firstName, lastName, email fields
 * - Partial matches work (LIKE %query%)
 * - Case-insensitive search
 * - Results ordered by lastName, firstName ASC
 * - Empty query returns all users
 *
 * For getUserStatistics():
 * - Total count is accurate
 * - Active/inactive split is correct
 * - Azure users counted correctly (azure_oauth, azure_saml)
 * - Local users counted correctly (local or NULL)
 * - Calculations match when users = 0
 *
 * For getRecentlyActiveUsers():
 * - Returns users ordered by lastLoginAt DESC
 * - Excludes users with NULL lastLoginAt
 * - Respects limit parameter (default 10)
 * - Returns fewer results if not enough users
 *
 * RECOMMENDATION:
 * Create UserRepositoryIntegrationTest.php using Symfony's KernelTestCase
 * with a test database to verify all the above behaviors.
 *
 * @see https://symfony.com/doc/current/testing.html#integration-tests
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/testing.html
 */
class UserRepositoryTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $registry;
    private UserRepository $repository;

    protected function setUp(): void
    {
        // Create mocks
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->registry = $this->createMock(ManagerRegistry::class);

        // Configure registry to return the entity manager
        $this->registry->method('getManagerForClass')
            ->with(User::class)
            ->willReturn($this->entityManager);

        // Configure entity manager to return class metadata
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->name = User::class;
        $this->entityManager->method('getClassMetadata')
            ->with(User::class)
            ->willReturn($classMetadata);

        // Create repository instance
        $this->repository = new UserRepository($this->registry);
    }

    /**
     * Test that the repository can be instantiated correctly
     */
    public function testRepositoryInstantiation(): void
    {
        $this->assertInstanceOf(UserRepository::class, $this->repository);
    }

    /**
     * Test that the repository implements PasswordUpgraderInterface
     */
    public function testRepositoryImplementsPasswordUpgraderInterface(): void
    {
        $this->assertInstanceOf(
            \Symfony\Component\Security\Core\User\PasswordUpgraderInterface::class,
            $this->repository
        );
    }

    /**
     * Test that the repository has all expected public methods
     */
    public function testRepositoryHasExpectedMethods(): void
    {
        $this->assertTrue(method_exists($this->repository, 'upgradePassword'));
        $this->assertTrue(method_exists($this->repository, 'findByAzureObjectId'));
        $this->assertTrue(method_exists($this->repository, 'findOrCreateFromAzure'));
        $this->assertTrue(method_exists($this->repository, 'findActiveUsers'));
        $this->assertTrue(method_exists($this->repository, 'findByRole'));
        $this->assertTrue(method_exists($this->repository, 'findByCustomRole'));
        $this->assertTrue(method_exists($this->repository, 'searchUsers'));
        $this->assertTrue(method_exists($this->repository, 'getUserStatistics'));
        $this->assertTrue(method_exists($this->repository, 'getRecentlyActiveUsers'));
    }

    /**
     * Test upgradePassword method signature
     */
    public function testUpgradePasswordSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'upgradePassword');
        $parameters = $method->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertEquals('user', $parameters[0]->getName());
        $this->assertEquals(
            PasswordAuthenticatedUserInterface::class,
            $parameters[0]->getType()->getName()
        );
        $this->assertEquals('newHashedPassword', $parameters[1]->getName());
        $this->assertEquals('string', $parameters[1]->getType()->getName());

        $returnType = $method->getReturnType();
        $this->assertEquals('void', $returnType->getName());
    }

    /**
     * Test upgradePassword throws exception for non-User instances
     */
    public function testUpgradePasswordThrowsExceptionForNonUserInstance(): void
    {
        // Create a mock that implements PasswordAuthenticatedUserInterface but is not User
        $wrongUser = $this->createMock(PasswordAuthenticatedUserInterface::class);

        $this->expectException(UnsupportedUserException::class);
        $this->expectExceptionMessage('Instances of');

        $this->repository->upgradePassword($wrongUser, 'new-hashed-password');
    }

    /**
     * Test upgradePassword with valid User instance
     * Note: This test verifies the logic flow, but cannot test actual persistence
     * due to Query being final. Integration tests are needed for full coverage.
     */
    public function testUpgradePasswordWithValidUser(): void
    {
        $user = $this->createMock(User::class);
        $newPassword = 'new-hashed-password-123';

        // Verify that setPassword is called with the new password
        $user->expects($this->once())
            ->method('setPassword')
            ->with($newPassword);

        // Verify that the entity manager persists and flushes
        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($user);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->repository->upgradePassword($user, $newPassword);
    }

    /**
     * Test findByAzureObjectId signature
     */
    public function testFindByAzureObjectIdSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findByAzureObjectId');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertEquals('azureObjectId', $parameters[0]->getName());
        $this->assertEquals('string', $parameters[0]->getType()->getName());

        $returnType = $method->getReturnType();
        $this->assertEquals(User::class, $returnType->getName());
        $this->assertTrue($returnType->allowsNull());
    }

    /**
     * Test findOrCreateFromAzure signature
     */
    public function testFindOrCreateFromAzureSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findOrCreateFromAzure');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertEquals('azureData', $parameters[0]->getName());
        $this->assertEquals('array', $parameters[0]->getType()->getName());

        $returnType = $method->getReturnType();
        $this->assertEquals(User::class, $returnType->getName());
        $this->assertFalse($returnType->allowsNull());
    }

    /**
     * Test findActiveUsers signature
     */
    public function testFindActiveUsersSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findActiveUsers');
        $parameters = $method->getParameters();

        $this->assertCount(0, $parameters);

        $returnType = $method->getReturnType();
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test findByRole signature
     */
    public function testFindByRoleSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findByRole');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertEquals('role', $parameters[0]->getName());
        $this->assertEquals('string', $parameters[0]->getType()->getName());

        $returnType = $method->getReturnType();
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test findByCustomRole signature
     */
    public function testFindByCustomRoleSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'findByCustomRole');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertEquals('roleName', $parameters[0]->getName());
        $this->assertEquals('string', $parameters[0]->getType()->getName());

        $returnType = $method->getReturnType();
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test searchUsers signature
     */
    public function testSearchUsersSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'searchUsers');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertEquals('query', $parameters[0]->getName());
        $this->assertEquals('string', $parameters[0]->getType()->getName());

        $returnType = $method->getReturnType();
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test getUserStatistics signature
     */
    public function testGetUserStatisticsSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'getUserStatistics');
        $parameters = $method->getParameters();

        $this->assertCount(0, $parameters);

        $returnType = $method->getReturnType();
        $this->assertEquals('array', $returnType->getName());
    }

    /**
     * Test getRecentlyActiveUsers signature
     */
    public function testGetRecentlyActiveUsersSignature(): void
    {
        $method = new \ReflectionMethod($this->repository, 'getRecentlyActiveUsers');
        $parameters = $method->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertEquals('limit', $parameters[0]->getName());
        $this->assertEquals('int', $parameters[0]->getType()->getName());
        $this->assertTrue($parameters[0]->isOptional());
        $this->assertEquals(10, $parameters[0]->getDefaultValue());

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

        // Verify the repository is typed for User entities
        $this->assertStringContainsString('@extends ServiceEntityRepository<User>', $docComment);
        $this->assertStringContainsString('@method User|null find', $docComment);
        $this->assertStringContainsString('@method User[]', $docComment);
    }

    /**
     * Test that the repository extends ServiceEntityRepository
     */
    public function testRepositoryExtendsServiceEntityRepository(): void
    {
        $reflection = new \ReflectionClass($this->repository);
        $parent = $reflection->getParentClass();

        $this->assertNotFalse($parent);
        $this->assertEquals(
            'Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository',
            $parent->getName()
        );
    }

    /**
     * Test that query methods return array type
     */
    public function testQueryMethodsReturnArrayType(): void
    {
        $methods = [
            'findActiveUsers',
            'findByRole',
            'findByCustomRole',
            'searchUsers',
            'getUserStatistics',
            'getRecentlyActiveUsers',
        ];

        foreach ($methods as $methodName) {
            $reflection = new \ReflectionMethod($this->repository, $methodName);
            $returnType = $reflection->getReturnType();

            $this->assertNotNull(
                $returnType,
                "Method {$methodName} should have a return type"
            );
            $this->assertEquals(
                'array',
                $returnType->getName(),
                "Method {$methodName} should return array"
            );
        }
    }

    /**
     * Test that findByAzureObjectId and findOrCreateFromAzure have correct return types
     */
    public function testAzureMethodsReturnTypes(): void
    {
        // findByAzureObjectId should return User|null
        $findMethod = new \ReflectionMethod($this->repository, 'findByAzureObjectId');
        $findReturnType = $findMethod->getReturnType();
        $this->assertEquals(User::class, $findReturnType->getName());
        $this->assertTrue($findReturnType->allowsNull());

        // findOrCreateFromAzure should return User (never null)
        $createMethod = new \ReflectionMethod($this->repository, 'findOrCreateFromAzure');
        $createReturnType = $createMethod->getReturnType();
        $this->assertEquals(User::class, $createReturnType->getName());
        $this->assertFalse($createReturnType->allowsNull());
    }

    /**
     * Test that getUserStatistics would return the expected array structure
     * Note: Actual query execution needs integration tests
     */
    public function testGetUserStatisticsExpectedStructure(): void
    {
        // This test documents the expected return structure
        // Integration tests should verify actual values
        $expectedKeys = ['total', 'active', 'inactive', 'azure', 'local'];

        // We can't execute the actual query, but we can document expectations
        $reflection = new \ReflectionMethod($this->repository, 'getUserStatistics');

        // Verify the method exists and is callable
        $this->assertTrue($reflection->isPublic());
        $this->assertFalse($reflection->isAbstract());

        // The actual structure validation requires integration tests
    }

    /**
     * Test that search query methods accept and would process string parameters correctly
     * Note: Actual LIKE query execution needs integration tests
     */
    public function testSearchMethodsAcceptStringParameters(): void
    {
        // Verify searchUsers accepts string
        $searchMethod = new \ReflectionMethod($this->repository, 'searchUsers');
        $searchParams = $searchMethod->getParameters();
        $this->assertEquals('string', $searchParams[0]->getType()->getName());

        // Verify findByRole accepts string
        $roleMethod = new \ReflectionMethod($this->repository, 'findByRole');
        $roleParams = $roleMethod->getParameters();
        $this->assertEquals('string', $roleParams[0]->getType()->getName());

        // Verify findByCustomRole accepts string
        $customRoleMethod = new \ReflectionMethod($this->repository, 'findByCustomRole');
        $customRoleParams = $customRoleMethod->getParameters();
        $this->assertEquals('string', $customRoleParams[0]->getType()->getName());

        // Verify findByAzureObjectId accepts string
        $azureMethod = new \ReflectionMethod($this->repository, 'findByAzureObjectId');
        $azureParams = $azureMethod->getParameters();
        $this->assertEquals('string', $azureParams[0]->getType()->getName());
    }

    /**
     * Test that getRecentlyActiveUsers has a sensible default limit
     */
    public function testGetRecentlyActiveUsersHasDefaultLimit(): void
    {
        $method = new \ReflectionMethod($this->repository, 'getRecentlyActiveUsers');
        $parameters = $method->getParameters();

        $limitParam = $parameters[0];
        $this->assertTrue($limitParam->isOptional());
        $this->assertEquals(10, $limitParam->getDefaultValue());
        $this->assertIsInt($limitParam->getDefaultValue());
    }

    /**
     * Test that all custom methods are properly documented
     */
    public function testCustomMethodsAreDocumented(): void
    {
        $customMethods = [
            'upgradePassword',
            'findByAzureObjectId',
            'findOrCreateFromAzure',
            'findActiveUsers',
            'findByRole',
            'findByCustomRole',
            'searchUsers',
            'getUserStatistics',
            'getRecentlyActiveUsers',
        ];

        foreach ($customMethods as $methodName) {
            $method = new \ReflectionMethod($this->repository, $methodName);
            $docComment = $method->getDocComment();

            $this->assertNotFalse(
                $docComment,
                "Method {$methodName} should have a doc comment"
            );
            $this->assertNotEmpty(
                trim($docComment),
                "Method {$methodName} should have non-empty documentation"
            );
        }
    }

    /**
     * Test the repository class is properly documented
     */
    public function testRepositoryClassIsDocumented(): void
    {
        $reflection = new \ReflectionClass($this->repository);
        $docComment = $reflection->getDocComment();

        $this->assertNotFalse($docComment);
        $this->assertStringContainsString('User Repository', $docComment);
        $this->assertStringContainsString('Azure SSO', $docComment);
        $this->assertStringContainsString('PasswordUpgraderInterface', $docComment);
    }
}
