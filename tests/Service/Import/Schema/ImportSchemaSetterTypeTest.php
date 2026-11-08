<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Schema;

use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;
use App\Service\Import\Schema\SchemaDrivenMapper;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard: what the schema-driven mapper produces for a field must be
 * accepted by the entity setter it is written with (strict types, as in
 * BulkImportOrchestrator). A plain string into a BackedEnum setter or a float into
 * the `?string` setter of a DECIMAL column passed the preview and failed the commit.
 *
 * Every provider in src/Service/Import/Schema/Provider is covered automatically —
 * a new provider or field needs no change here.
 */
#[AllowMockObjectsWithoutExpectations]
final class ImportSchemaSetterTypeTest extends TestCase
{
    /** @return list<EntityImportSchema> */
    private function schemas(): array
    {
        $schemas = [];
        $dir = dirname(__DIR__, 4) . '/src/Service/Import/Schema/Provider';
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $class = 'App\\Service\\Import\\Schema\\Provider\\' . basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, ImportSchemaProviderInterface::class)) {
                $schemas[] = (new $class())->getSchema();
            }
        }
        self::assertGreaterThanOrEqual(20, count($schemas), 'Expected the full set of schema providers');

        return $schemas;
    }

    private function mapper(EntityImportSchema $schema): SchemaDrivenMapper
    {
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActive')->willReturn(true);

        return new SchemaDrivenMapper($this->createStub(EntityManagerInterface::class), $modules, $schema);
    }

    private function parameterType(string $entityClass, string $setter): ?\ReflectionNamedType
    {
        $type = (new \ReflectionMethod($entityClass, $setter))->getParameters()[0]->getType();

        return $type instanceof \ReflectionNamedType ? $type : null;
    }

    #[Test]
    public function everyFieldCastsToTheTypeItsSetterTakes(): void
    {
        foreach ($this->schemas() as $schema) {
            $mapper = $this->mapper($schema);

            foreach ($schema->fields as $field) {
                if ($field->type === ImportFieldSpec::TYPE_RELATION) {
                    continue; // resolved to an entity of relationClass; covered by ImportSchemaIntegrityTest
                }
                $ctx  = "{$schema->entityType}.{$field->name}";
                $type = $this->parameterType($schema->entityClass, $field->setter);
                if ($type === null) {
                    continue; // union / untyped parameter
                }

                $data = $mapper->toEntityData([$field->name => $this->sampleCell($field)]);
                $property = lcfirst(substr($field->setter, 3));
                self::assertArrayHasKey($property, $data, "{$ctx}: sample cell was not mapped");
                $value = $data[$property];

                $name = $type->getName();
                if ($type->isBuiltin()) {
                    $ok = match ($name) {
                        'int'    => is_int($value),
                        'float'  => is_int($value) || is_float($value),
                        'string' => is_string($value),
                        'bool'   => is_bool($value),
                        'array'  => is_array($value),
                        default  => true,
                    };
                } else {
                    $ok = $value instanceof $name;
                }

                self::assertTrue($ok, sprintf(
                    '%s: mapper yields %s, %s::%s() takes %s',
                    $ctx,
                    get_debug_type($value),
                    $schema->entityClass,
                    $field->setter,
                    $type,
                ));
            }
        }
    }

    #[Test]
    public function enumSetterFieldsDeclareTheirEnumClassAndOnlyValidValues(): void
    {
        foreach ($this->schemas() as $schema) {
            foreach ($schema->fields as $field) {
                $type = $this->parameterType($schema->entityClass, $field->setter);
                if ($type === null || $type->isBuiltin() || !is_subclass_of($type->getName(), \BackedEnum::class)) {
                    continue;
                }
                $ctx = "{$schema->entityType}.{$field->name}";
                self::assertSame(ImportFieldSpec::TYPE_ENUM, $field->type, "{$ctx}: BackedEnum setter needs TYPE_ENUM");
                self::assertSame($type->getName(), $field->enumClass, "{$ctx}: enumClass must name the setter's enum");

                foreach ($field->enumValues as $value) {
                    self::assertNotNull($type->getName()::tryFrom($value), "{$ctx}: '{$value}' is no case of the enum");
                }
            }
        }
    }

    private function sampleCell(ImportFieldSpec $field): string
    {
        return match ($field->type) {
            ImportFieldSpec::TYPE_INT, ImportFieldSpec::TYPE_FLOAT, ImportFieldSpec::TYPE_DECIMAL => '7.5',
            ImportFieldSpec::TYPE_BOOL => 'yes',
            ImportFieldSpec::TYPE_DATE => '2026-01-31',
            ImportFieldSpec::TYPE_LIST => 'a, b',
            ImportFieldSpec::TYPE_ENUM => $field->enumValues[0]
                ?? (string) ($field->enumClass !== null ? $field->enumClass::cases()[0]->value : 'x'),
            default => 'Sample',
        };
    }
}
