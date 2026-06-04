<?php

declare(strict_types=1);

namespace App\Tests\Service\Restore;

use App\Enum\RiskStatus;
use App\Service\Restore\RestoreEntityWriter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RestoreEntityWriter.
 *
 * This class is deeply DB-bound for restoreEntity() and restoreManyToManyAssociations(),
 * which require a live Doctrine EM with full entity metadata. Those integration paths
 * are covered by RestoreServiceTest (WebTestCase scope).
 *
 * Here we test the two pure-logic helpers that have zero DB dependency:
 *   - getUniqueConstraintFields()
 *   - orderEntitiesByDependency()
 *
 * @coverage-skip restoreEntity() and restoreManyToManyAssociations() require live Doctrine
 *   metadata and are integration-tested via RestoreServiceTest.
 */
#[AllowMockObjectsWithoutExpectations]
final class RestoreEntityWriterTest extends TestCase
{
    private RestoreEntityWriter $writer;

    protected function setUp(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $logger        = $this->createMock(LoggerInterface::class);
        $this->writer  = new RestoreEntityWriter($entityManager, $logger);
    }

    // ────────────────────────────────────────────────────────────────────────
    // getUniqueConstraintFields
    // ────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function knownEntityProvider(): array
    {
        return [
            'Role'                   => ['Role',                   ['name']],
            'User'                   => ['User',                   ['email']],
            'Tenant'                 => ['Tenant',                 ['code']],
            'Permission'             => ['Permission',             ['name']],
            'ComplianceFramework'    => ['ComplianceFramework',    ['code']],
            'Control'                => ['Control',                ['controlId']],
            'ComplianceRequirement'  => ['ComplianceRequirement',  ['requirementId']],
        ];
    }

    #[Test]
    #[DataProvider('knownEntityProvider')]
    public function get_unique_constraint_fields_returns_correct_fields(string $entityName, array $expected): void
    {
        self::assertSame($expected, $this->writer->getUniqueConstraintFields($entityName));
    }

    #[Test]
    public function get_unique_constraint_fields_returns_empty_array_for_unknown_entity(): void
    {
        self::assertSame([], $this->writer->getUniqueConstraintFields('NonExistentEntity'));
        self::assertSame([], $this->writer->getUniqueConstraintFields(''));
    }

    // ────────────────────────────────────────────────────────────────────────
    // orderEntitiesByDependency
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function order_puts_tenant_before_role_before_user(): void
    {
        $input  = ['User', 'Role', 'Tenant'];
        $result = $this->writer->orderEntitiesByDependency($input);

        $tenantIdx = array_search('Tenant', $result, true);
        $roleIdx   = array_search('Role',   $result, true);
        $userIdx   = array_search('User',   $result, true);

        self::assertLessThan($roleIdx, $tenantIdx, 'Tenant must come before Role');
        self::assertLessThan($userIdx, $roleIdx,   'Role must come before User');
    }

    #[Test]
    public function order_handles_unknown_entities_at_end(): void
    {
        $input  = ['MyCustomEntity', 'Tenant'];
        $result = $this->writer->orderEntitiesByDependency($input);

        $tenantIdx = array_search('Tenant', $result, true);
        $customIdx = array_search('MyCustomEntity', $result, true);

        self::assertLessThan($customIdx, $tenantIdx, 'Known Tenant must sort before unknown entity');
    }

    #[Test]
    public function order_preserves_all_input_entities(): void
    {
        $input  = ['Risk', 'Asset', 'Control', 'Tenant', 'User', 'Incident'];
        $result = $this->writer->orderEntitiesByDependency($input);

        self::assertCount(count($input), $result);
        foreach ($input as $entityName) {
            self::assertContains($entityName, $result);
        }
    }

    #[Test]
    public function order_returns_empty_array_for_empty_input(): void
    {
        self::assertSame([], $this->writer->orderEntitiesByDependency([]));
    }

    #[Test]
    public function order_puts_higher_priority_entities_first(): void
    {
        // Asset (priority 15) should come after Control (11) in restore order (lower = first)
        $input  = ['Asset', 'Control'];
        $result = $this->writer->orderEntitiesByDependency($input);

        self::assertSame('Control', $result[0]);
        self::assertSame('Asset',   $result[1]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // resolveEnumType — regression guard for the enum round-trip bug.
    //
    // Doctrine `enumType:` columns hydrate as a BackedEnum, so a backup serialises
    // the enum object; once written to JSON it collapses to its backing scalar.
    // On restore that scalar must be coerced back into the enum, otherwise
    // PropertyAccessor::setValue() throws a TypeError (an \Error, not \Exception).
    // resolveEnumType is the detection crux that drives that coercion.
    // ────────────────────────────────────────────────────────────────────────

    private function callResolveEnumType(ClassMetadata $meta, string $field): ?string
    {
        $ref = new \ReflectionMethod(RestoreEntityWriter::class, 'resolveEnumType');

        return $ref->invoke($this->writer, $meta, $field);
    }

    private function fieldMapping(string $field, ?string $enumType): FieldMapping
    {
        $fm = new FieldMapping('string', $field, $field);
        $fm->enumType = $enumType;

        return $fm;
    }

    #[Test]
    public function resolve_enum_type_detects_backed_enum(): void
    {
        $meta = $this->createMock(ClassMetadata::class);
        $meta->method('getFieldMapping')->willReturn($this->fieldMapping('status', RiskStatus::class));

        self::assertSame(RiskStatus::class, $this->callResolveEnumType($meta, 'status'));
    }

    #[Test]
    public function resolve_enum_type_returns_null_for_non_enum_field(): void
    {
        $meta = $this->createMock(ClassMetadata::class);
        $meta->method('getFieldMapping')->willReturn($this->fieldMapping('title', null));

        self::assertNull($this->callResolveEnumType($meta, 'title'));
    }

    #[Test]
    public function resolve_enum_type_returns_null_when_mapping_lookup_throws(): void
    {
        $meta = $this->createMock(ClassMetadata::class);
        $meta->method('getFieldMapping')->willThrowException(new \RuntimeException('no such field'));

        self::assertNull($this->callResolveEnumType($meta, 'ghost'));
    }
}
