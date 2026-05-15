<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Mapper;

use App\Entity\Asset;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\UserRepository;
use App\Service\Import\Mapper\AssetMapper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class AssetMapperTest extends TestCase
{
    private MockObject $em;
    private MockObject $assetRepository;
    private MockObject $userRepository;
    private AssetMapper $mapper;

    protected function setUp(): void
    {
        $this->em              = $this->createMock(EntityManagerInterface::class);
        $this->assetRepository = $this->createMock(AssetRepository::class);
        $this->userRepository  = $this->createMock(UserRepository::class);

        $this->mapper = new AssetMapper(
            $this->em,
            $this->assetRepository,
            $this->userRepository,
        );
    }

    #[Test]
    public function supportsEntityTypeReturnsTrueForAsset(): void
    {
        self::assertTrue($this->mapper->supportsEntityType('Asset'));
    }

    #[Test]
    public function supportsEntityTypeReturnsFalseForOthers(): void
    {
        self::assertFalse($this->mapper->supportsEntityType('Supplier'));
        self::assertFalse($this->mapper->supportsEntityType('Control'));
    }

    #[Test]
    public function validateRequiresName(): void
    {
        $result = $this->mapper->validate(['assetType' => 'hardware']);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('name', $result['errors'][0]);
    }

    #[Test]
    public function validateRequiresAssetType(): void
    {
        $result = $this->mapper->validate(['name' => 'Laptop 01']);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('assetType', $result['errors'][0]);
    }

    #[Test]
    public function validateRejectsCiaOutOfRange(): void
    {
        $result = $this->mapper->validate([
            'name'             => 'Server',
            'assetType'        => 'hardware',
            'confidentiality'  => '6',
        ]);
        self::assertNotEmpty($result['errors']);
        self::assertStringContainsString('confidentiality', $result['errors'][0]);
    }

    #[Test]
    public function validatePassesValidRow(): void
    {
        $result = $this->mapper->validate([
            'name'             => 'Laptop 01',
            'assetType'        => 'hardware',
            'confidentiality'  => '3',
            'integrity'        => '2',
            'availability'     => '4',
        ]);
        self::assertEmpty($result['errors']);
        self::assertEmpty($result['warnings']);
    }

    #[Test]
    public function validateEmitsWarningForNonEmailOwner(): void
    {
        $result = $this->mapper->validate([
            'name'      => 'Switch',
            'assetType' => 'network',
            'owner'     => 'John Doe',  // name, not email
        ]);
        self::assertEmpty($result['errors']);
        self::assertNotEmpty($result['warnings']);
    }

    #[Test]
    public function validateEmitsWarningForUnknownClassification(): void
    {
        $result = $this->mapper->validate([
            'name'           => 'Switch',
            'assetType'      => 'network',
            'classification' => 'top_secret',
        ]);
        self::assertEmpty($result['errors']);
        self::assertNotEmpty($result['warnings']);
    }

    #[Test]
    public function toEntityDataMapsRequiredFields(): void
    {
        $data = $this->mapper->toEntityData([
            'name'      => 'DB Server',
            'assetType' => 'server',
        ]);

        self::assertSame('DB Server', $data['name']);
        self::assertSame('server', $data['assetType']);
    }

    #[Test]
    public function toEntityDataMapsCiaValues(): void
    {
        $data = $this->mapper->toEntityData([
            'name'             => 'DB Server',
            'assetType'        => 'server',
            'confidentiality'  => '5',
            'integrity'        => '3',
            'availability'     => '4',
        ]);

        self::assertSame(5, $data['confidentialityValue']);
        self::assertSame(3, $data['integrityValue']);
        self::assertSame(4, $data['availabilityValue']);
    }

    #[Test]
    public function toEntityDataMapsClassification(): void
    {
        $data = $this->mapper->toEntityData([
            'name'           => 'HR Data',
            'assetType'      => 'data',
            'classification' => 'CONFIDENTIAL',   // upper-case → normalised
        ]);

        self::assertSame('confidential', $data['dataClassification']);
    }

    #[Test]
    public function toEntityDataIgnoresInvalidClassification(): void
    {
        $data = $this->mapper->toEntityData([
            'name'           => 'HR Data',
            'assetType'      => 'data',
            'classification' => 'top_secret',
        ]);

        self::assertArrayNotHasKey('dataClassification', $data);
    }

    // ── isDoraRelevant mapping (DORA Art. 28) ────────────────────────────────

    #[Test]
    public function toEntityDataEmitsIsDoraRelevantTrueWhenFlagSet(): void
    {
        $data = $this->mapper->toEntityData([
            'name'           => 'Payment API',
            'assetType'      => 'software',
            'isDoraRelevant' => 'yes',
        ]);
        self::assertTrue($data['isDoraRelevant']);
    }

    #[Test]
    public function toEntityDataEmitsIsDoraRelevantFalseWhenFlagCleared(): void
    {
        $data = $this->mapper->toEntityData([
            'name'           => 'Coffee Machine',
            'assetType'      => 'hardware',
            'isDoraRelevant' => '0',
        ]);
        self::assertFalse($data['isDoraRelevant']);
    }

    #[Test]
    public function toEntityDataOmitsIsDoraRelevantWhenAbsent(): void
    {
        $data = $this->mapper->toEntityData([
            'name'      => 'Office Laptop',
            'assetType' => 'hardware',
        ]);
        self::assertArrayNotHasKey('isDoraRelevant', $data);
    }

    #[Test]
    public function resolveOwnerUserReturnNullWhenOwnerMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $result = $this->mapper->resolveOwnerUser(['name' => 'Asset'], $tenant);
        self::assertNull($result);
    }

    #[Test]
    public function resolveOwnerUserReturnNullWhenOwnerIsNotEmail(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $result = $this->mapper->resolveOwnerUser(['name' => 'Asset', 'owner' => 'Jane Doe'], $tenant);
        self::assertNull($result);
    }

    #[Test]
    public function resolveOwnerUserResolvesEmailToUser(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $user   = $this->createMock(User::class);

        $this->userRepository
            ->method('findOneBy')
            ->willReturn($user);

        $result = $this->mapper->resolveOwnerUser(
            ['name' => 'Asset', 'owner' => 'jane@example.com'],
            $tenant,
        );
        self::assertSame($user, $result);
    }

    #[Test]
    public function resolveOwnerUserReturnsNullWhenUserNotFound(): void
    {
        $tenant = $this->createMock(Tenant::class);

        $this->userRepository
            ->method('findOneBy')
            ->willReturn(null);

        $result = $this->mapper->resolveOwnerUser(
            ['name' => 'Asset', 'owner' => 'unknown@example.com'],
            $tenant,
        );
        self::assertNull($result);
    }

    #[Test]
    public function findExistingReturnNullWhenNameMissing(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $result = $this->mapper->findExisting(['assetType' => 'hardware'], $tenant);
        self::assertNull($result);
    }

    #[Test]
    public function findExistingQueriesDatabase(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $asset  = $this->createMock(Asset::class);

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($asset);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->em->method('createQueryBuilder')->willReturn($qb);

        $result = $this->mapper->findExisting(
            ['name' => 'Laptop 01', 'assetType' => 'hardware'],
            $tenant,
        );

        self::assertSame($asset, $result);
    }
}
