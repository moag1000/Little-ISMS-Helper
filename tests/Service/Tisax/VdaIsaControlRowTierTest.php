<?php

declare(strict_types=1);

namespace App\Tests\Service\Tisax;

use App\Service\Tisax\Dto\VdaIsaControlRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VdaIsaControlRow::getTier() domain-to-tier mapping.
 *
 * VDA-ISA 6.x official chapter structure:
 *   Information Security: domains 1-6
 *   Prototype Protection: domains 7-9
 *   Data Protection:      domains 10-12
 */
class VdaIsaControlRowTierTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function tierMappingProvider(): array
    {
        return [
            // Information Security — domains 1-6
            'domain 1 → information_security' => ['1.1.1', 'information_security'],
            'domain 2 → information_security' => ['2.1.1', 'information_security'],
            'domain 3 → information_security' => ['3.2.5', 'information_security'],
            'domain 4 → information_security' => ['4.1.1', 'information_security'],
            'domain 5 → information_security' => ['5.3.2', 'information_security'],
            'domain 6 → information_security' => ['6.1.1', 'information_security'],
            // Prototype Protection — domains 7-9
            'domain 7 → prototype_protection' => ['7.1.1', 'prototype_protection'],
            'domain 8 → prototype_protection' => ['8.2.3', 'prototype_protection'],
            'domain 9 → prototype_protection' => ['9.1.1', 'prototype_protection'],
            // Data Protection — domains 10-12
            'domain 10 → data_protection'     => ['10.1.1', 'data_protection'],
            'domain 11 → data_protection'     => ['11.2.1', 'data_protection'],
            'domain 12 → data_protection'     => ['12.1.1', 'data_protection'],
        ];
    }

    #[Test]
    #[DataProvider('tierMappingProvider')]
    public function getTier_returns_correct_tier_per_domain(string $controlId, string $expectedTier): void
    {
        $row = $this->makeRow($controlId);
        self::assertSame($expectedTier, $row->getTier(), sprintf(
            'Control ID "%s" (domain %s) should map to tier "%s"',
            $controlId,
            $row->getDomainPrefix(),
            $expectedTier,
        ));
    }

    #[Test]
    public function getDomainPrefix_extracts_leading_number_correctly(): void
    {
        self::assertSame('1', $this->makeRow('1.2.3')->getDomainPrefix());
        self::assertSame('10', $this->makeRow('10.1.1')->getDomainPrefix());
        self::assertSame('12', $this->makeRow('12.3.1')->getDomainPrefix());
    }

    #[Test]
    public function unrecognised_domain_falls_back_to_information_security(): void
    {
        // domain 0 or domain > 12 are not valid VDA-ISA chapters; must not throw
        self::assertSame('information_security', $this->makeRow('0.1.1')->getTier());
        self::assertSame('information_security', $this->makeRow('13.1.1')->getTier());
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function makeRow(string $controlId): VdaIsaControlRow
    {
        return new VdaIsaControlRow(
            controlId: $controlId,
            title: 'Test control',
            titleEn: null,
            description: null,
            mustLevel: null,
            shouldLevel: null,
            highLevel: null,
            veryHighLevel: null,
            iso27001Ref: null,
            auditEvidenceHint: null,
            rawRowIndex: 1,
        );
    }
}
