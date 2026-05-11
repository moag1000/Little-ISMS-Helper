<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\Tenant;
use App\Service\Import\DeltaCalculator;
use App\Service\Import\Dto\DeltaConfig;
use App\Service\Import\Dto\ParsedSpreadsheet;
use App\Service\Import\EntityMapperRegistry;
use App\Service\Import\Mapper\EntityMapperInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject; // used for typed property declarations below
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DeltaCalculator.
 *
 * All external dependencies are mocked — no database is touched.
 */
#[AllowMockObjectsWithoutExpectations]
final class DeltaCalculatorTest extends TestCase
{
    /** @var EntityMapperInterface&MockObject */
    private EntityMapperInterface $mapper;

    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $em;

    /** @var Tenant&MockObject */
    private Tenant $tenant;

    private DeltaCalculator $calculator;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(EntityMapperInterface::class);
        $this->em     = $this->createMock(EntityManagerInterface::class);
        $this->tenant = $this->createMock(Tenant::class);

        // EntityMapperRegistry is final and cannot be mocked.
        // Build the real registry with a single stubbed mapper that supports 'Asset'.
        $this->mapper->method('supportsEntityType')
            ->willReturnCallback(fn (string $t): bool => $t === 'Asset');

        $registry         = new EntityMapperRegistry([$this->mapper]);
        $this->calculator = new DeltaCalculator($registry, $this->em);
    }

    // -------------------------------------------------------------------------
    // Helper: build a minimal ParsedSpreadsheet
    // -------------------------------------------------------------------------

    /** @param array<int, array<string, mixed>> $rows */
    private function makeSheet(array $rows): ParsedSpreadsheet
    {
        return new ParsedSpreadsheet(
            headers: array_keys($rows[0] ?? []),
            rows: $rows,
            warnings: [],
            sheetName: 'Sheet1',
            totalRowCount: count($rows),
        );
    }

    private function makeConfig(bool $includeDeletes = false, array $ignoredFields = ['updatedAt', 'createdAt']): DeltaConfig
    {
        return new DeltaConfig(
            entityType: 'Asset',
            tenant: $this->tenant,
            includeDeletes: $includeDeletes,
            ignoredFields: $ignoredFields,
        );
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    #[Test]
    public function testAllRowsAreCreatesWhenNoExistingEntities(): void
    {
        $rows = [
            ['name' => 'Server A', 'type' => 'Hardware'],
            ['name' => 'Server B', 'type' => 'Hardware'],
        ];

        $this->mapper->method('validate')->willReturn(['errors' => [], 'warnings' => []]);
        $this->mapper->method('findExisting')->willReturn(null);
        $this->mapper->method('toEntityData')->willReturnCallback(
            fn (array $row): array => $row
        );

        $result = $this->calculator->calculate($this->makeSheet($rows), $this->makeConfig());

        self::assertCount(2, $result->creates);
        self::assertCount(0, $result->updates);
        self::assertCount(0, $result->unchanged);
        self::assertCount(0, $result->errors);
        self::assertNull($result->creates[0]['entityId']);
        self::assertNull($result->creates[0]['oldValues']);
        self::assertNull($result->creates[0]['diff']);
        self::assertSame(1, $result->creates[0]['rowNumber']);
        self::assertSame(2, $result->creates[1]['rowNumber']);
    }

    #[Test]
    public function testAllRowsAreUnchangedWhenIdenticalValues(): void
    {
        $row = ['name' => 'Server A'];

        // A simple entity stub with getId() returning 10
        $existingEntity = new class {
            public string $name = 'Server A';
            public function getId(): int { return 10; }
        };

        $this->mapper->method('validate')->willReturn(['errors' => [], 'warnings' => []]);
        $this->mapper->method('findExisting')->willReturn($existingEntity);
        $this->mapper->method('toEntityData')->willReturn(['name' => 'Server A']);

        $result = $this->calculator->calculate($this->makeSheet([$row]), $this->makeConfig());

        self::assertCount(0, $result->creates);
        self::assertCount(0, $result->updates);
        self::assertCount(1, $result->unchanged);
        self::assertCount(0, $result->errors);
        self::assertSame(10, $result->unchanged[0]['entityId']);
        self::assertSame([], $result->unchanged[0]['diff']);
    }

    #[Test]
    public function testRowIsUpdateWhenValuesDiffer(): void
    {
        $row = ['name' => 'Server A-renamed'];

        $existingEntity = new class {
            public string $name = 'Server A';
            public function getId(): int { return 5; }
        };

        $this->mapper->method('validate')->willReturn(['errors' => [], 'warnings' => []]);
        $this->mapper->method('findExisting')->willReturn($existingEntity);
        $this->mapper->method('toEntityData')->willReturn(['name' => 'Server A-renamed']);

        $result = $this->calculator->calculate($this->makeSheet([$row]), $this->makeConfig());

        self::assertCount(0, $result->creates);
        self::assertCount(1, $result->updates);
        self::assertCount(0, $result->unchanged);
        self::assertSame(5, $result->updates[0]['entityId']);
    }

    #[Test]
    public function testDiffPayloadContainsOnlyChangedFields(): void
    {
        $row = ['name' => 'New Name', 'type' => 'Hardware'];

        $existingEntity = new class {
            public string $name = 'Old Name';
            public string $type = 'Hardware';
            public function getId(): int { return 1; }
        };

        $this->mapper->method('validate')->willReturn(['errors' => [], 'warnings' => []]);
        $this->mapper->method('findExisting')->willReturn($existingEntity);
        $this->mapper->method('toEntityData')->willReturn(['name' => 'New Name', 'type' => 'Hardware']);

        $result = $this->calculator->calculate($this->makeSheet([$row]), $this->makeConfig());

        self::assertCount(1, $result->updates);
        $diff = $result->updates[0]['diff'];

        // Only 'name' changed
        self::assertArrayHasKey('name', $diff);
        self::assertArrayNotHasKey('type', $diff);
        self::assertSame('Old Name', $diff['name']['old']);
        self::assertSame('New Name', $diff['name']['new']);
    }

    #[Test]
    public function testIgnoredFieldsAreNotInDiff(): void
    {
        $row = ['name' => 'Server A', 'updatedAt' => '2026-01-02T00:00:00+00:00'];

        $existingEntity = new class {
            public string $name      = 'Server A';
            public string $updatedAt = '2026-01-01T00:00:00+00:00';
            public function getId(): int { return 3; }
        };

        $this->mapper->method('validate')->willReturn(['errors' => [], 'warnings' => []]);
        $this->mapper->method('findExisting')->willReturn($existingEntity);
        // toEntityData returns the new value including the changed updatedAt
        $this->mapper->method('toEntityData')->willReturn([
            'name'      => 'Server A',
            'updatedAt' => '2026-01-02T00:00:00+00:00',
        ]);

        // updatedAt is in ignoredFields → row should be classified unchanged
        $result = $this->calculator->calculate($this->makeSheet([$row]), $this->makeConfig(
            ignoredFields: ['updatedAt'],
        ));

        self::assertCount(0, $result->updates, 'updatedAt change must not trigger an update');
        self::assertCount(1, $result->unchanged);
    }

    #[Test]
    public function testRowWithValidationErrorsLandsInErrorsArray(): void
    {
        $row = ['name' => ''];

        $this->mapper->method('validate')->willReturn([
            'errors'   => ['Name must not be blank.'],
            'warnings' => [],
        ]);

        $result = $this->calculator->calculate($this->makeSheet([$row]), $this->makeConfig());

        self::assertCount(0, $result->creates);
        self::assertCount(1, $result->errors);
        self::assertSame(1, $result->errors[0]['rowNumber']);
        self::assertContains('Name must not be blank.', $result->errors[0]['errors']);
    }

    #[Test]
    public function testIncludeDeletesFalseDoesNotQueryRepository(): void
    {
        $row = ['name' => 'Server A'];

        $this->mapper->method('validate')->willReturn(['errors' => [], 'warnings' => []]);
        $this->mapper->method('findExisting')->willReturn(null);
        $this->mapper->method('toEntityData')->willReturn(['name' => 'Server A']);

        // getRepository must never be called when includeDeletes=false
        $this->em->expects(self::never())->method('getRepository');

        $result = $this->calculator->calculate($this->makeSheet([$row]), $this->makeConfig(includeDeletes: false));

        self::assertCount(0, $result->deletes);
    }

    #[Test]
    public function testIncludeDeletesTrueQueriesRemainder(): void
    {
        // Two rows in the sheet — both match existing entities (IDs 10, 20)
        $rows = [
            ['name' => 'Server A'],
            ['name' => 'Server B'],
        ];

        $entityA = new class {
            public string $name = 'Server A';
            public function getId(): int { return 10; }
        };
        $entityB = new class {
            public string $name = 'Server B';
            public function getId(): int { return 20; }
        };
        // A third entity in DB not in sheet — ID 30
        $entityC = new class {
            public string $name = 'Server C';
            public function getId(): int { return 30; }
        };

        $this->mapper->method('validate')->willReturn(['errors' => [], 'warnings' => []]);
        $this->mapper->method('findExisting')->willReturnOnConsecutiveCalls($entityA, $entityB);
        $this->mapper->method('toEntityData')->willReturnCallback(
            fn (array $row): array => $row
        );

        /** @var EntityRepository&MockObject $repository */
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$entityA, $entityB, $entityC]);

        $this->em->method('getRepository')->willReturn($repository);

        $result = $this->calculator->calculate($this->makeSheet($rows), $this->makeConfig(includeDeletes: true));

        // entityA and entityB matched → only entityC lands in deletes
        self::assertCount(2, $result->unchanged);
        self::assertCount(1, $result->deletes);
        self::assertSame(30, $result->deletes[0]['entityId']);
        self::assertArrayHasKey('snapshot', $result->deletes[0]);
    }
}
