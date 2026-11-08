<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\ComplianceMapping;
use App\Service\Import\ComplianceMappingRowRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The compliance-mapping CSV import accepts the values the mapping form allows
 * (percentage 0-150, type weak/partial/full/exceeds, confidence low/medium/high)
 * and refuses the rest — preview and commit share these rules.
 */
final class ComplianceMappingRowRulesTest extends TestCase
{
    /** @return array<string, mixed> */
    private function row(array $override = []): array
    {
        return $override + ['mapping_percentage' => '80', 'mapping_type' => 'partial', 'confidence' => 'high'];
    }

    #[Test]
    public function validRowPasses(): void
    {
        self::assertSame([], ComplianceMappingRowRules::invalidColumns($this->row()));
        self::assertSame([], ComplianceMappingRowRules::invalidColumns($this->row([
            'mapping_percentage' => ' 150 ',
            'mapping_type' => ' Exceeds ',
            'confidence' => 'LOW',
        ])), 'trimmed, case-insensitive, upper bound inclusive');
        self::assertSame([], ComplianceMappingRowRules::invalidColumns($this->row(['mapping_percentage' => '0'])));
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function invalidRows(): iterable
    {
        yield 'percentage above 150' => [['mapping_percentage' => '151'], ['mapping_percentage']];
        yield 'negative percentage' => [['mapping_percentage' => '-5'], ['mapping_percentage']];
        yield 'fraction' => [['mapping_percentage' => '80.5'], ['mapping_percentage']];
        yield 'text percentage' => [['mapping_percentage' => 'eighty'], ['mapping_percentage']];
        yield 'empty percentage' => [['mapping_percentage' => ''], ['mapping_percentage']];
        yield 'unknown type' => [['mapping_type' => 'strong'], ['mapping_type']];
        yield 'unknown confidence' => [['confidence' => 'certain'], ['confidence']];
        yield 'all three' => [
            ['mapping_percentage' => 'x', 'mapping_type' => '', 'confidence' => 'sure'],
            ['mapping_percentage', 'mapping_type', 'confidence'],
        ];
    }

    /**
     * @param array<string, mixed> $override
     * @param list<string>         $expected
     */
    #[Test]
    #[DataProvider('invalidRows')]
    public function invalidValuesAreNamed(array $override, array $expected): void
    {
        self::assertSame($expected, ComplianceMappingRowRules::invalidColumns($this->row($override)));
    }

    #[Test]
    public function allowedValuesMatchTheEntity(): void
    {
        self::assertSame(['weak', 'partial', 'full', 'exceeds'], ComplianceMapping::MAPPING_TYPES);
        self::assertSame(['low', 'medium', 'high'], ComplianceMapping::CONFIDENCE_LEVELS);
        self::assertSame(150, ComplianceMapping::MAX_PERCENTAGE);
        self::assertSame('full', ComplianceMappingRowRules::normalised(' FULL '));
    }
}
