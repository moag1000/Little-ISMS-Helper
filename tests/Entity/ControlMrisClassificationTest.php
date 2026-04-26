<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Control;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Stellt sicher, dass die MRIS-Klassifikation auf der Control-Entität
 * korrekt persistiert + gelesen wird (gem. MRIS v1.5 Anhang A).
 *
 * Quelle: Peddi, R. (2026). MRIS — Mythos-resistente Informationssicherheit, v1.5.
 * Lizenz: CC BY 4.0.
 */
final class ControlMrisClassificationTest extends TestCase
{
    public function testDefaultValuesAreNull(): void
    {
        $control = new Control();
        $this->assertNull($control->getMythosResilience());
        $this->assertNull($control->getMythosFlankingMhcs());
    }

    #[DataProvider('validCategoryProvider')]
    public function testValidMythosResilienceCategoriesAreAccepted(string $category): void
    {
        $control = new Control();
        $control->setMythosResilience($category);
        $this->assertSame($category, $control->getMythosResilience());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validCategoryProvider(): array
    {
        return [
            'standfest (S)'      => ['standfest'],
            'degradiert (T)'     => ['degradiert'],
            'reibung (R)'        => ['reibung'],
            'nicht_betroffen (N)' => ['nicht_betroffen'],
        ];
    }

    public function testFlankingMhcsAcceptsArrayOfStrings(): void
    {
        $control = new Control();
        $control->setMythosFlankingMhcs(['MHC-04', 'MHC-05']);
        $this->assertSame(['MHC-04', 'MHC-05'], $control->getMythosFlankingMhcs());
    }

    public function testFlankingMhcsAcceptsNullForControlsWithoutFlanking(): void
    {
        $control = new Control();
        $control->setMythosFlankingMhcs(['MHC-01']);
        $control->setMythosFlankingMhcs(null);
        $this->assertNull($control->getMythosFlankingMhcs());
    }

    public function testReineReibungControlsCarryFlankingMhcsPerMrisAnhangA(): void
    {
        // MRIS Anhang A: 4 "Reine Reibung"-Controls + ihre flankierenden MHCs
        $expectedFlanking = [
            'A.5.25' => ['MHC-11'],
            'A.5.36' => ['MHC-10'],
            'A.8.8'  => ['MHC-09'],
            'A.8.23' => ['MHC-03'],
        ];

        foreach ($expectedFlanking as $controlId => $flanking) {
            $control = new Control();
            $control->setControlId($controlId);
            $control->setMythosResilience('reibung');
            $control->setMythosFlankingMhcs($flanking);

            $this->assertSame('reibung', $control->getMythosResilience(), "Control $controlId");
            $this->assertSame($flanking, $control->getMythosFlankingMhcs(), "Control $controlId");
        }
    }

    public function testSetterIsFluent(): void
    {
        $control = new Control();
        $self1 = $control->setMythosResilience('standfest');
        $self2 = $control->setMythosFlankingMhcs(['MHC-01']);
        $this->assertSame($control, $self1);
        $this->assertSame($control, $self2);
    }
}
