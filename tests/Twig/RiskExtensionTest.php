<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\RiskExtension;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Junior-ISB-Audit-2026-05-22 M-03 — Twig presentation helpers for the
 * risk matrix.
 *
 *  - risk_threshold(level)   → score range string ("20–25", "12–19", …)
 *  - risk_level_label(level) → localised band label, single source of truth
 *  - risk_level_band(score)  → classify a score into a band identifier
 */
#[AllowMockObjectsWithoutExpectations]
final class RiskExtensionTest extends TestCase
{
    private function makeExtension(): RiskExtension
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $key): string => 'translated:' . $key
        );
        return new RiskExtension($translator);
    }

    #[Test]
    public function riskThresholdReturnsRangeForKnownBand(): void
    {
        $ext = $this->makeExtension();
        self::assertSame('20–25', $ext->riskThreshold('critical'));
        self::assertSame('12–19', $ext->riskThreshold('high'));
        self::assertSame('6–11', $ext->riskThreshold('medium'));
        self::assertSame('1–5', $ext->riskThreshold('low'));
    }

    #[Test]
    public function riskThresholdReturnsEmptyForUnknownBand(): void
    {
        $ext = $this->makeExtension();
        self::assertSame('', $ext->riskThreshold('moderate'));
        self::assertSame('', $ext->riskThreshold(''));
    }

    #[Test]
    public function riskLevelLabelDelegatesToTranslatorForKnownBand(): void
    {
        $ext = $this->makeExtension();
        self::assertSame('translated:risk.level.critical', $ext->riskLevelLabel('critical'));
        self::assertSame('translated:risk.level.low', $ext->riskLevelLabel('low'));
    }

    #[Test]
    public function riskLevelLabelReturnsEmptyForUnknownBand(): void
    {
        $ext = $this->makeExtension();
        self::assertSame('', $ext->riskLevelLabel('moderate'));
    }

    #[Test]
    public function riskLevelBandClassifiesScoresPerSsotMatrix(): void
    {
        $ext = $this->makeExtension();
        self::assertSame('critical', $ext->riskLevelBand(25));
        self::assertSame('critical', $ext->riskLevelBand(20));
        self::assertSame('high', $ext->riskLevelBand(19));
        self::assertSame('high', $ext->riskLevelBand(12));
        self::assertSame('medium', $ext->riskLevelBand(11));
        self::assertSame('medium', $ext->riskLevelBand(6));
        self::assertSame('low', $ext->riskLevelBand(5));
        self::assertSame('low', $ext->riskLevelBand(1));
    }
}
