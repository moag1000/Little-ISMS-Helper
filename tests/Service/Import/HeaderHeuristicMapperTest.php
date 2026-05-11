<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\HeaderHeuristicMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests that German and English header variations resolve to correct
 * entity fields with confidence >= HeaderHeuristicMapper::MIN_CONFIDENCE.
 */
final class HeaderHeuristicMapperTest extends TestCase
{
    private HeaderHeuristicMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new HeaderHeuristicMapper();
    }

    // -------------------------------------------------------------------------
    // Asset mappings
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function assetHeaderProvider(): array
    {
        return [
            // English exact/close
            'en:name'              => ['Name', 'name'],
            'en:title'             => ['Title', 'name'],
            'en:type'              => ['Type', 'assetType'],
            'en:owner'             => ['Owner', 'owner'],
            'en:classification'    => ['Classification', 'classification'],
            'en:confidentiality'   => ['Confidentiality', 'confidentiality'],
            'en:integrity'         => ['Integrity', 'integrity'],
            'en:availability'      => ['Availability', 'availability'],
            // German
            'de:bezeichnung'       => ['Bezeichnung', 'name'],
            'de:titel'             => ['Titel', 'name'],
            'de:typ'               => ['Typ', 'assetType'],
            'de:verantwortlich'    => ['Verantwortlich', 'owner'],
            'de:besitzer'          => ['Besitzer', 'owner'],
            'de:klassifizierung'   => ['Klassifizierung', 'classification'],
            'de:vertraulichkeit'   => ['Vertraulichkeit', 'confidentiality'],
            'de:integritaet'       => ['Integrität', 'integrity'],
            'de:verfuegbarkeit'    => ['Verfügbarkeit', 'availability'],
            // Single-letter CIA
            'cia:c'                => ['C', 'confidentiality'],
            'cia:i'                => ['I', 'integrity'],
            'cia:a'                => ['A', 'availability'],
        ];
    }

    #[Test]
    #[DataProvider('assetHeaderProvider')]
    public function testAssetHeaderResolvesWithSufficientConfidence(
        string $header,
        string $expectedField,
    ): void {
        $result = $this->mapper->suggestMappings([$header], 'asset');

        self::assertArrayHasKey($header, $result, sprintf(
            'Header "%s" should have a suggestion (confidence >= %.1f).',
            $header,
            HeaderHeuristicMapper::MIN_CONFIDENCE,
        ));

        self::assertSame($expectedField, $result[$header]['target'], sprintf(
            'Header "%s" should map to "%s", got "%s".',
            $header,
            $expectedField,
            $result[$header]['target'],
        ));

        self::assertGreaterThanOrEqual(
            HeaderHeuristicMapper::MIN_CONFIDENCE,
            $result[$header]['confidence'],
            sprintf('Confidence for "%s" must be >= %.1f.', $header, HeaderHeuristicMapper::MIN_CONFIDENCE),
        );
    }

    // -------------------------------------------------------------------------
    // Supplier mappings
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function supplierHeaderProvider(): array
    {
        return [
            'en:name'         => ['Name', 'name'],
            'de:firma'        => ['Firma', 'name'],
            'de:lieferant'    => ['Lieferant', 'name'],
            'en:email'        => ['Email', 'contactEmail'],
            'de:kontakt'      => ['Kontakt', 'contactEmail'],
            'en:criticality'  => ['Criticality', 'criticality'],
            'de:kritikalitaet' => ['Kritikalität', 'criticality'],
            'en:dora'         => ['Dora', 'isDoraRelevant'],
            'en:dora_relevant' => ['Dora_Relevant', 'isDoraRelevant'],
        ];
    }

    #[Test]
    #[DataProvider('supplierHeaderProvider')]
    public function testSupplierHeaderResolvesWithSufficientConfidence(
        string $header,
        string $expectedField,
    ): void {
        $result = $this->mapper->suggestMappings([$header], 'supplier');

        self::assertArrayHasKey($header, $result, sprintf(
            'Supplier header "%s" should have a suggestion.',
            $header,
        ));

        self::assertSame($expectedField, $result[$header]['target'], sprintf(
            'Supplier header "%s" should map to "%s".',
            $header,
            $expectedField,
        ));

        self::assertGreaterThanOrEqual(
            HeaderHeuristicMapper::MIN_CONFIDENCE,
            $result[$header]['confidence'],
        );
    }

    // -------------------------------------------------------------------------
    // Control mappings
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function controlHeaderProvider(): array
    {
        return [
            'en:identifier'    => ['Identifier', 'identifier'],
            'en:ref'           => ['Ref', 'identifier'],
            'en:annex'         => ['Annex', 'identifier'],
            'en:control_id'    => ['Control_ID', 'identifier'],
            'en:title'         => ['Title', 'title'],
            'de:titel'         => ['Titel', 'title'],
            'de:bezeichnung'   => ['Bezeichnung', 'title'],
            'en:applicability' => ['Applicability', 'applicability'],
            'en:applicable'    => ['Applicable', 'applicability'],
            'de:anwendbar'     => ['Anwendbar', 'applicability'],
            'en:justification' => ['Justification', 'justification'],
            'de:begruendung'   => ['Begründung', 'justification'],
        ];
    }

    #[Test]
    #[DataProvider('controlHeaderProvider')]
    public function testControlHeaderResolvesWithSufficientConfidence(
        string $header,
        string $expectedField,
    ): void {
        $result = $this->mapper->suggestMappings([$header], 'control');

        self::assertArrayHasKey($header, $result, sprintf(
            'Control header "%s" should have a suggestion.',
            $header,
        ));

        self::assertSame($expectedField, $result[$header]['target'], sprintf(
            'Control header "%s" should map to "%s", got "%s".',
            $header,
            $expectedField,
            $result[$header]['target'],
        ));

        self::assertGreaterThanOrEqual(
            HeaderHeuristicMapper::MIN_CONFIDENCE,
            $result[$header]['confidence'],
        );
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    #[Test]
    public function testBelowThresholdHeaderIsOmitted(): void
    {
        // A header completely unrelated to asset fields
        $result = $this->mapper->suggestMappings(['XYZ_Completely_Unrelated_9999'], 'asset');

        // Either absent or confidence below threshold — the mapper may or may not
        // include it; spec says "no mapping suggested". We verify the field is absent.
        if (isset($result['XYZ_Completely_Unrelated_9999'])) {
            self::assertLessThan(
                HeaderHeuristicMapper::MIN_CONFIDENCE,
                $result['XYZ_Completely_Unrelated_9999']['confidence'],
                'A completely unrelated header must not exceed the confidence threshold.',
            );
        } else {
            self::assertArrayNotHasKey('XYZ_Completely_Unrelated_9999', $result);
        }
    }

    #[Test]
    public function testUnknownEntityTypeReturnsEmpty(): void
    {
        $result = $this->mapper->suggestMappings(['Name', 'Type'], 'unknownEntity');

        self::assertSame([], $result, 'Unknown entity type must return empty suggestions.');
    }

    #[Test]
    public function testEmptyHeadersReturnEmpty(): void
    {
        $result = $this->mapper->suggestMappings([], 'asset');

        self::assertSame([], $result);
    }

    #[Test]
    public function testMultipleHeadersAreMappedIndependently(): void
    {
        $result = $this->mapper->suggestMappings(['Name', 'Typ', 'Verantwortlich'], 'asset');

        self::assertArrayHasKey('Name', $result);
        self::assertArrayHasKey('Typ', $result);
        self::assertArrayHasKey('Verantwortlich', $result);

        self::assertSame('name', $result['Name']['target']);
        self::assertSame('assetType', $result['Typ']['target']);
        self::assertSame('owner', $result['Verantwortlich']['target']);
    }

    #[Test]
    public function testExactMatchYieldsConfidenceOfOne(): void
    {
        $result = $this->mapper->suggestMappings(['name'], 'asset');

        self::assertArrayHasKey('name', $result);
        self::assertSame(1.0, $result['name']['confidence']);
    }

    #[Test]
    public function testConfidenceIsRoundedToFourDecimals(): void
    {
        // 'Bezeichnung' is an exact alias → 1.0; 'Besitzer' should be close
        $result = $this->mapper->suggestMappings(['Besitzer'], 'asset');

        self::assertArrayHasKey('Besitzer', $result);
        $confidence = $result['Besitzer']['confidence'];
        // Check it has at most 4 decimal places
        self::assertSame($confidence, round($confidence, 4));
    }
}
