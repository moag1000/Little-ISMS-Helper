<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use App\Entity\ComplianceMapping;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard: mapping_type is a NOT NULL column, and ComplianceMapping
 * only derives it inside setMappingPercentage().
 *
 * MappingLibraryLoader used to call setMappingPercentage() only when the
 * fixture entry carried a `relationship` key. Entries without one produced a
 * mapping with mapping_type = null, so the whole library import aborted with
 * "Column 'mapping_type' cannot be null" — 149 shipped TISAX pairs could never
 * be imported. Only running the importer against a real database surfaced it.
 *
 * Unit-level: no DB, no kernel.
 */
final class MappingTypeNotNullTest extends TestCase
{
    #[Test]
    public function mappingTypeIsUnsetUntilThePercentageIsAssigned(): void
    {
        $mapping = new ComplianceMapping();

        // This is the state that violated the NOT NULL column.
        self::assertNull($mapping->getMappingType());
    }

    #[Test]
    public function assigningAnyPercentageSatisfiesTheNotNullColumn(): void
    {
        foreach ([0, 30, 70, 100, 120] as $percentage) {
            $mapping = new ComplianceMapping();
            $mapping->setMappingPercentage($percentage);

            self::assertNotNull(
                $mapping->getMappingType(),
                sprintf('percentage %d left mapping_type null', $percentage),
            );
        }
    }

    #[Test]
    public function loaderAssignsThePercentageEvenWithoutARelationship(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Service/MappingLibraryLoader.php');

        // Look at the condition guarding the setMappingPercentage() call itself —
        // an isset($entry['relationship']) elsewhere in the file (e.g. around
        // setRelationship()) is fine and must not trip this test.
        self::assertSame(
            1,
            preg_match(
                '/if \((.+)\)\s*\{\s*\$mapping->setMappingPercentage\(match/U',
                $source,
                $m,
            ),
            'could not locate the setMappingPercentage() call in MappingLibraryLoader',
        );

        self::assertStringNotContainsString(
            'relationship',
            $m[1],
            'setMappingPercentage() must not be gated on a relationship key — '
            . 'entries without one then leave the NOT NULL mapping_type unset.',
        );
    }
}
