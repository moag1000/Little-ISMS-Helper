<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Schema;

use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integrity guard for EVERY schema-driven import provider.
 *
 * For each registered schema and each field it asserts:
 *   - the setter exists on the entity,
 *   - enum fields carry a non-empty value set,
 *   - relation fields point at a real class AND a lookup that is a queryable
 *     Doctrine field/association (SchemaDrivenMapper::resolveRelation does
 *     findOneBy([lookup => …]) — a computed getter would throw at import time),
 *   - the unique key (if any) is a real Doctrine field for findExisting().
 *
 * This is the safety net that keeps ~25 declarative schemas honest against
 * entity drift.
 */
final class ImportSchemaIntegrityTest extends KernelTestCase
{
    #[Test]
    public function everyRegisteredSchemaIsConsistentWithItsEntity(): void
    {
        self::bootKernel();
        $registry = static::getContainer()->get(ImportSchemaRegistry::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $types = $registry->supportedEntityTypes();
        self::assertGreaterThanOrEqual(20, count($types), 'Expected the full set of schema-driven imports');

        foreach ($types as $type) {
            $schema = $registry->getSchemaFor($type);
            self::assertNotNull($schema, "No schema for {$type}");
            self::assertNotEmpty($schema->fields, "{$type} schema has no fields");
            self::assertTrue(class_exists($schema->entityClass), "{$type} entityClass missing");

            $meta = $em->getClassMetadata($schema->entityClass);

            foreach ($schema->fields as $field) {
                $ctx = "{$type}.{$field->name}";

                self::assertTrue(
                    method_exists($schema->entityClass, $field->setter),
                    "{$ctx}: setter {$field->setter}() missing on {$schema->entityClass}",
                );

                if ($field->type === ImportFieldSpec::TYPE_ENUM) {
                    self::assertNotEmpty($field->enumValues, "{$ctx}: enum has no values");
                }

                if ($field->type === ImportFieldSpec::TYPE_RELATION) {
                    self::assertNotNull($field->relationClass, "{$ctx}: relation has no class");
                    self::assertTrue(class_exists($field->relationClass), "{$ctx}: relationClass {$field->relationClass} missing");
                    self::assertNotNull($field->relationLookup, "{$ctx}: relation has no lookup field");

                    $relMeta = $em->getClassMetadata($field->relationClass);
                    self::assertTrue(
                        $relMeta->hasField($field->relationLookup) || $relMeta->hasAssociation($field->relationLookup),
                        "{$ctx}: relationLookup '{$field->relationLookup}' is not a queryable Doctrine field on {$field->relationClass}",
                    );
                }

                if ($field->unique) {
                    $property = lcfirst(substr($field->setter, 3));
                    self::assertTrue(
                        $meta->hasField($property) || $meta->hasAssociation($property),
                        "{$ctx}: unique key property '{$property}' is not a Doctrine field (findExisting would fail)",
                    );
                }
            }
        }
    }

    /**
     * F46 — the Risk import schema must carry the quantitative ALE inputs
     * (SLE/ARO), module-gated by risk_quant. This is the Phase-1 acceptance
     * criterion "Import muss €-Feld mappen" and replaces the regression cover
     * lost when the legacy RiskMapper was retired in the 360° schema migration.
     */
    #[Test]
    public function riskSchemaExposesQuantitativeAleInputs(): void
    {
        self::bootKernel();
        $registry = static::getContainer()->get(ImportSchemaRegistry::class);

        $schema = $registry->getSchemaFor('Risk');
        self::assertNotNull($schema);

        $byName = [];
        foreach ($schema->fields as $field) {
            $byName[$field->name] = $field;
        }

        foreach (['singleLossExpectancy' => 'sle', 'annualRateOfOccurrence' => 'aro'] as $name => $alias) {
            self::assertArrayHasKey($name, $byName, "Risk import schema is missing F46 field {$name}");
            self::assertSame('risk_quant', $byName[$name]->module, "{$name} must be gated by the risk_quant module");
            self::assertContains($alias, $byName[$name]->aliases, "{$name} should accept the '{$alias}' column alias");
        }
    }
}
