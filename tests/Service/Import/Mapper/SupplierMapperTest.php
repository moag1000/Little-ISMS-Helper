<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Mapper;

use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Repository\SupplierRepository;
use App\Service\Import\Mapper\SupplierMapper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class SupplierMapperTest extends TestCase
{
    private MockObject $em;
    private MockObject $supplierRepository;
    private SupplierMapper $mapper;

    protected function setUp(): void
    {
        $this->em                 = $this->createMock(EntityManagerInterface::class);
        $this->supplierRepository = $this->createMock(SupplierRepository::class);

        $this->mapper = new SupplierMapper(
            $this->em,
            $this->supplierRepository,
        );
    }

    #[Test]
    public function supportsEntityTypeReturnsTrueForSupplier(): void
    {
        self::assertTrue($this->mapper->supportsEntityType('Supplier'));
    }

    #[Test]
    public function supportsEntityTypeReturnsFalseForOthers(): void
    {
        self::assertFalse($this->mapper->supportsEntityType('Asset'));
        self::assertFalse($this->mapper->supportsEntityType('Control'));
    }

    #[Test]
    public function validateRequiresName(): void
    {
        $result = $this->mapper->validate([]);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('name', $result['errors'][0]);
    }

    #[Test]
    public function validateRejectsInvalidEmail(): void
    {
        $result = $this->mapper->validate([
            'name'         => 'ACME GmbH',
            'contactEmail' => 'not-an-email',
        ]);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('contactEmail', $result['errors'][0]);
    }

    #[Test]
    public function validatePassesValidEmail(): void
    {
        $result = $this->mapper->validate([
            'name'         => 'ACME GmbH',
            'contactEmail' => 'info@acme.example',
        ]);
        self::assertEmpty($result['errors']);
    }

    #[Test]
    public function validateEmitsWarningForUnknownCriticality(): void
    {
        $result = $this->mapper->validate([
            'name'        => 'ACME GmbH',
            'criticality' => 'extreme',
        ]);
        self::assertEmpty($result['errors']);
        self::assertNotEmpty($result['warnings']);
    }

    #[Test]
    public function validateEmitsWarningForUnknownIctCriticality(): void
    {
        $result = $this->mapper->validate([
            'name'           => 'ACME GmbH',
            'ictCriticality' => 'unknown_value',
        ]);
        self::assertEmpty($result['errors']);
        self::assertNotEmpty($result['warnings']);
    }

    #[Test]
    public function toEntityDataMapsRequiredFields(): void
    {
        $data = $this->mapper->toEntityData(['name' => 'Cloud Corp']);
        self::assertSame('Cloud Corp', $data['name']);
    }

    #[Test]
    public function toEntityDataDefaultsCriticalityToMedium(): void
    {
        $data = $this->mapper->toEntityData(['name' => 'Vendor X']);
        self::assertSame('medium', $data['criticality']);
    }

    #[Test]
    public function toEntityDataNormalisesCriticality(): void
    {
        $data = $this->mapper->toEntityData([
            'name'        => 'Vendor X',
            'criticality' => 'HIGH',
        ]);
        self::assertSame('high', $data['criticality']);
    }

    #[Test]
    public function toEntityDataMapsContactEmail(): void
    {
        $data = $this->mapper->toEntityData([
            'name'         => 'Vendor X',
            'contactEmail' => 'CONTACT@VENDOR.COM',
        ]);
        self::assertSame('contact@vendor.com', $data['email']);
    }

    #[Test]
    public function toEntityDataMapsIctCriticalityDirectly(): void
    {
        $data = $this->mapper->toEntityData([
            'name'           => 'Vendor X',
            'ictCriticality' => 'critical',
        ]);
        self::assertSame('critical', $data['ictCriticality']);
    }

    #[Test]
    public function toEntityDataMapsIsDoraRelevantTrueToImportant(): void
    {
        $data = $this->mapper->toEntityData([
            'name'           => 'Cloud SaaS',
            'isDoraRelevant' => 'yes',
        ]);
        self::assertSame('important', $data['ictCriticality']);
    }

    #[Test]
    public function toEntityDataMapsIsDoraRelevantFalseToNonIct(): void
    {
        $data = $this->mapper->toEntityData([
            'name'           => 'Local Vendor',
            'isDoraRelevant' => '0',
        ]);
        self::assertSame('non_ict', $data['ictCriticality']);
    }

    #[Test]
    public function toEntityDataIctCriticalityTakesPrecedenceOverDoraFlag(): void
    {
        $data = $this->mapper->toEntityData([
            'name'           => 'Hybrid',
            'ictCriticality' => 'critical',
            'isDoraRelevant' => 'yes',
        ]);
        self::assertSame('critical', $data['ictCriticality']);
    }

    #[Test]
    public function findExistingReturnNullWhenNameMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        self::assertNull($this->mapper->findExisting([], $tenant));
    }

    #[Test]
    public function findExistingQueriesDatabase(): void
    {
        $tenant   = $this->createMock(Tenant::class);
        $supplier = $this->createMock(Supplier::class);

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($supplier);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->em->method('createQueryBuilder')->willReturn($qb);

        $result = $this->mapper->findExisting(['name' => 'ACME GmbH'], $tenant);
        self::assertSame($supplier, $result);
    }
}
