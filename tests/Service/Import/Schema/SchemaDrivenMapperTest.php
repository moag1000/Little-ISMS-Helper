<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Schema;

use App\Entity\Tenant;
use App\Enum\TreatmentStrategy;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\SchemaDrivenMapper;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the generic schema-driven mapper: validation, type casting and
 * — the key invariant — module-aware field gating.
 */
#[AllowMockObjectsWithoutExpectations]
final class SchemaDrivenMapperTest extends TestCase
{
    private MockObject $moduleConfig;

    private function mapper(bool $doraActive, ?EntityManagerInterface $em = null): SchemaDrivenMapper
    {
        $this->moduleConfig = $this->createMock(ModuleConfigurationService::class);
        $this->moduleConfig->method('isModuleActive')->willReturnCallback(
            static fn(string $m): bool => $m === 'nis2_dora' ? $doraActive : true,
        );

        $schema = new EntityImportSchema('Demo', \stdClass::class, [
            new ImportFieldSpec(name: 'name', setter: 'setName', type: ImportFieldSpec::TYPE_STRING, required: true, unique: true),
            new ImportFieldSpec(name: 'count', setter: 'setCount', type: ImportFieldSpec::TYPE_INT),
            new ImportFieldSpec(name: 'active', setter: 'setActive', type: ImportFieldSpec::TYPE_BOOL),
            new ImportFieldSpec(name: 'tags', setter: 'setTags', type: ImportFieldSpec::TYPE_LIST),
            new ImportFieldSpec(name: 'level', setter: 'setLevel', type: ImportFieldSpec::TYPE_ENUM, enumValues: ['low', 'high']),
            new ImportFieldSpec(name: 'doraFlag', setter: 'setDoraFlag', type: ImportFieldSpec::TYPE_BOOL, module: 'nis2_dora'),
            new ImportFieldSpec(
                name: 'strategy',
                setter: 'setStrategy',
                type: ImportFieldSpec::TYPE_ENUM,
                enumValues: ['accept', 'mitigate', 'transfer', 'avoid'],
                enumClass: TreatmentStrategy::class,
            ),
            new ImportFieldSpec(name: 'score', setter: 'setScore', type: ImportFieldSpec::TYPE_DECIMAL),
        ]);

        return new SchemaDrivenMapper($em ?? $this->createMock(EntityManagerInterface::class), $this->moduleConfig, $schema);
    }

    #[Test]
    public function moduleGatedFieldIsHiddenWhenModuleInactive(): void
    {
        $names = array_map(fn($f) => $f->name, $this->mapper(doraActive: false)->activeFields());
        self::assertNotContains('doraFlag', $names);

        $names = array_map(fn($f) => $f->name, $this->mapper(doraActive: true)->activeFields());
        self::assertContains('doraFlag', $names);
    }

    #[Test]
    public function requiredFieldMissingProducesError(): void
    {
        $result = $this->mapper(true)->validate(['count' => '5']);
        self::assertNotEmpty($result['errors']);
    }

    #[Test]
    public function invalidEnumProducesError(): void
    {
        $result = $this->mapper(true)->validate(['name' => 'X', 'level' => 'medium']);
        self::assertNotEmpty($result['errors']);

        $ok = $this->mapper(true)->validate(['name' => 'X', 'level' => 'high']);
        self::assertEmpty($ok['errors']);
    }

    #[Test]
    public function toEntityDataCastsTypesAndKeysByProperty(): void
    {
        $data = $this->mapper(true)->toEntityData([
            'name'   => ' Hello ',
            'count'  => '42',
            'active' => 'yes',
            'tags'   => 'a, b; c',
            'level'  => 'LOW',
        ]);

        self::assertSame('Hello', $data['name']);
        self::assertSame(42, $data['count']);
        self::assertTrue($data['active']);
        self::assertSame(['a', 'b', 'c'], $data['tags']);
        self::assertSame('low', $data['level']);
    }

    #[Test]
    public function enumClassFieldCastsToTheBackedEnumCaseInsensitive(): void
    {
        $data = $this->mapper(true)->toEntityData(['name' => 'X', 'strategy' => ' Mitigate ']);

        self::assertSame(TreatmentStrategy::Mitigate, $data['strategy']);
    }

    #[Test]
    public function unknownEnumClassValueIsAValidationErrorSoThePreviewShowsIt(): void
    {
        $result = $this->mapper(true)->validate(['name' => 'X', 'strategy' => 'ignore']);

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('strategy', $result['errors'][0]);
    }

    #[Test]
    public function decimalFieldYieldsANumericStringForDecimalColumnSetters(): void
    {
        $data = $this->mapper(true)->toEntityData(['name' => 'X', 'score' => ' 7.25 ']);
        self::assertSame('7.25', $data['score']);

        $floatCell = $this->mapper(true)->toEntityData(['name' => 'X', 'score' => 9.5]);
        self::assertSame('9.5', $floatCell['score'], 'a numeric spreadsheet cell becomes a string too');

        self::assertNotEmpty($this->mapper(true)->validate(['name' => 'X', 'score' => 'n/a'])['errors']);
    }

    #[Test]
    public function validateReadsTheCellsTheUserMapped(): void
    {
        $mapping = ['Bezeichnung' => 'name', 'Stufe' => 'level'];

        self::assertNotEmpty($this->mapper(true)->validate(['Bezeichnung' => 'X'])['errors'], 'without mapping: name missing');
        self::assertSame([], $this->mapper(true)->validate(['Bezeichnung' => 'X', 'Stufe' => 'low'], $mapping)['errors']);
        self::assertNotEmpty(
            $this->mapper(true)->validate(['Bezeichnung' => 'X', 'Stufe' => 'medium'], $mapping)['errors'],
            'the mapped column is validated, not skipped',
        );
    }

    #[Test]
    public function findExistingAndDuplicateKeyUseTheUserMapping(): void
    {
        $tenant = $this->createStub(Tenant::class);
        $found  = new \stdClass();

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('findOneBy')
            ->with(['tenant' => $tenant, 'name' => 'Server A'])
            ->willReturn($found);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $mapper  = $this->mapper(true, $em);
        $row     = ['Bezeichnung' => ' Server A '];
        $mapping = ['Bezeichnung' => 'name'];

        self::assertSame($found, $mapper->findExisting($row, $tenant, $mapping));
        self::assertSame('server a', $mapper->duplicateKey($row, $mapping));
        self::assertNull($mapper->duplicateKey($row), 'without mapping the key column is unknown');
    }

    #[Test]
    public function moduleGatedValueIsIgnoredWhenModuleInactive(): void
    {
        $data = $this->mapper(doraActive: false)->toEntityData(['name' => 'X', 'doraFlag' => 'yes']);
        self::assertArrayNotHasKey('doraFlag', $data);
    }
}
