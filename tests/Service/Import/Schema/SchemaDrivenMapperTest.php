<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Schema;

use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\SchemaDrivenMapper;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
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

    private function mapper(bool $doraActive): SchemaDrivenMapper
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
        ]);

        return new SchemaDrivenMapper($this->createMock(EntityManagerInterface::class), $this->moduleConfig, $schema);
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
    public function moduleGatedValueIsIgnoredWhenModuleInactive(): void
    {
        $data = $this->mapper(doraActive: false)->toEntityData(['name' => 'X', 'doraFlag' => 'yes']);
        self::assertArrayNotHasKey('doraFlag', $data);
    }
}
