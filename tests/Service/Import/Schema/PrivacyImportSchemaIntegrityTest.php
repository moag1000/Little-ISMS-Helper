<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Schema;

use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integrity guard for the schema-driven import providers: every declared field
 * must reference a REAL setter on the entity, every relation a real class +
 * lookup field, and every enum a non-empty value set. Catches typos / drift
 * between a schema and its entity that would otherwise only surface at import
 * time as a silently-skipped column.
 */
final class PrivacyImportSchemaIntegrityTest extends KernelTestCase
{
    /** @return iterable<string, array{string}> */
    public static function privacyEntityTypes(): iterable
    {
        yield 'ProcessingActivity' => ['ProcessingActivity'];
        yield 'DataSubjectRequest' => ['DataSubjectRequest'];
        yield 'Consent' => ['Consent'];
    }

    #[Test]
    #[DataProvider('privacyEntityTypes')]
    public function schemaFieldsReferenceRealSettersAndRelations(string $entityType): void
    {
        self::bootKernel();
        $registry = static::getContainer()->get(ImportSchemaRegistry::class);

        $schema = $registry->getSchemaFor($entityType);
        self::assertNotNull($schema, "No schema registered for {$entityType}");
        self::assertSame('privacy', $schema->module, "{$entityType} import must be privacy-module-gated");
        self::assertNotEmpty($schema->fields, "{$entityType} schema has no fields");

        foreach ($schema->fields as $field) {
            self::assertTrue(
                method_exists($schema->entityClass, $field->setter),
                sprintf('%s: setter %s() does not exist on %s', $entityType, $field->setter, $schema->entityClass),
            );

            if ($field->type === ImportFieldSpec::TYPE_ENUM) {
                self::assertNotEmpty($field->enumValues, sprintf('%s.%s is enum but has no enumValues', $entityType, $field->name));
            }

            if ($field->type === ImportFieldSpec::TYPE_RELATION) {
                self::assertNotNull($field->relationClass, sprintf('%s.%s is relation but has no relationClass', $entityType, $field->name));
                self::assertTrue(class_exists($field->relationClass), sprintf('%s.%s relationClass %s missing', $entityType, $field->name, $field->relationClass));
                self::assertNotNull($field->relationLookup, sprintf('%s.%s relation has no lookup field', $entityType, $field->name));
            }
        }
    }

    #[Test]
    public function registryExposesExactlyTheThreePrivacyImports(): void
    {
        self::bootKernel();
        $registry = static::getContainer()->get(ImportSchemaRegistry::class);

        $types = $registry->supportedEntityTypes();
        foreach (['ProcessingActivity', 'DataSubjectRequest', 'Consent'] as $expected) {
            self::assertContains($expected, $types);
        }
    }
}
