<?php

declare(strict_types=1);

namespace App\Tests\Service\Bsi;

use App\Service\Bsi\FrameworkPanelComposition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * P2 — FrameworkPanelComposition unit tests.
 *
 * Verifies:
 *  - ISO↔BSI         → [isms-specialist, bsi-specialist]
 *  - NIS2↔BSI        → [isms-specialist, bsi-specialist]
 *  - GDPR↔ISO27701   → [dpo-specialist, isms-specialist]
 *  - DORA↔ISO        → [isms-specialist, risk-management-specialist] (same-specialist fallback)
 *  - Cross-cutting lenses are always present
 *  - specialistFor() covers all documented framework patterns
 */
final class FrameworkPanelCompositionTest extends TestCase
{
    private FrameworkPanelComposition $composition;

    protected function setUp(): void
    {
        $this->composition = new FrameworkPanelComposition();
    }

    // ── compose() — documented framework pairs ────────────────────────────

    #[Test]
    public function isoBsiCompositionReturnsIsmsAndBsiSpecialists(): void
    {
        $result = $this->composition->compose('ISO27001', 'BSI_GRUNDSCHUTZ');

        self::assertCount(2, $result['experts']);
        self::assertSame('isms-specialist', $result['experts'][0]['skill']);
        self::assertSame('bsi-specialist',  $result['experts'][1]['skill']);
        $this->assertLensesPresent($result);
    }

    #[Test]
    public function nis2BsiCompositionReturnsIsmsAndBsiSpecialists(): void
    {
        $result = $this->composition->compose('NIS2', 'BSI_GRUNDSCHUTZ');

        self::assertCount(2, $result['experts']);
        self::assertSame('isms-specialist', $result['experts'][0]['skill']);
        self::assertSame('bsi-specialist',  $result['experts'][1]['skill']);
        $this->assertLensesPresent($result);
    }

    #[Test]
    public function gdprIso27701CompositionReturnsDpoAndIsmsSpecialists(): void
    {
        $result = $this->composition->compose('GDPR', 'ISO27701');

        self::assertCount(2, $result['experts']);
        self::assertSame('dpo-specialist',  $result['experts'][0]['skill']);
        self::assertSame('isms-specialist', $result['experts'][1]['skill']);
        $this->assertLensesPresent($result);
    }

    #[Test]
    public function doraIsoCompositionUsesSameSpecialistFallback(): void
    {
        // DORA → isms-specialist, ISO → isms-specialist (same!) → fallback to risk-management-specialist
        $result = $this->composition->compose('DORA', 'ISO27001');

        self::assertCount(2, $result['experts']);
        self::assertSame('isms-specialist',          $result['experts'][0]['skill']);
        self::assertSame('risk-management-specialist', $result['experts'][1]['skill'],
            'When both frameworks map to isms-specialist, second expert must fall back to risk-management-specialist');
        $this->assertLensesPresent($result);
    }

    #[Test]
    public function iso27701GdprCompositionReturnsDpoAndIsmsFallback(): void
    {
        // ISO27701 → dpo-specialist, GDPR → dpo-specialist (same!) → fallback = isms-specialist
        $result = $this->composition->compose('ISO27701', 'GDPR');

        self::assertCount(2, $result['experts']);
        self::assertSame('dpo-specialist',  $result['experts'][0]['skill']);
        self::assertSame('isms-specialist', $result['experts'][1]['skill'],
            'dpo+dpo same-specialist fallback must produce isms-specialist');
        $this->assertLensesPresent($result);
    }

    #[Test]
    public function iso27005Iso31000CompositionUsesFallbackForBothRiskFrameworks(): void
    {
        // ISO27005 → risk-management-specialist, ISO31000 → risk-management-specialist (same!)
        // → fallback for risk+risk = persona-compliance-manager
        $result = $this->composition->compose('ISO27005', 'ISO31000');

        self::assertCount(2, $result['experts']);
        self::assertSame('risk-management-specialist', $result['experts'][0]['skill']);
        self::assertSame('persona-compliance-manager', $result['experts'][1]['skill']);
        $this->assertLensesPresent($result);
    }

    // ── compose() — refs paths ────────────────────────────────────────────

    #[Test]
    public function expertRefsPathFollowsSkillsDirectoryConvention(): void
    {
        $result = $this->composition->compose('ISO27001', 'BSI_GRUNDSCHUTZ');

        self::assertSame('.claude/skills/isms-specialist/references/', $result['experts'][0]['refs']);
        self::assertSame('.claude/skills/bsi-specialist/references/',  $result['experts'][1]['refs']);
    }

    // ── lenses — always present ───────────────────────────────────────────

    #[Test]
    public function lensesAlwaysIncludeConsultantSeniorAndAuditorExternal(): void
    {
        $pairs = [
            ['ISO27001',  'BSI_GRUNDSCHUTZ'],
            ['NIS2',      'BSI_GRUNDSCHUTZ'],
            ['GDPR',      'ISO27701'],
            ['DORA',      'ISO27001'],
            ['ISO22301',  'ISO27001'],
            ['TISAX',     'ISO27001'],
        ];

        foreach ($pairs as [$src, $tgt]) {
            $result = $this->composition->compose($src, $tgt);
            self::assertContains('persona-consultant-senior',  $result['lenses'], "$src↔$tgt missing consultant lens");
            self::assertContains('persona-auditor-external',   $result['lenses'], "$src↔$tgt missing auditor lens");
        }
    }

    // ── specialistFor() — all documented framework patterns ──────────────

    #[Test]
    #[DataProvider('specialistForProvider')]
    public function specialistForReturnsExpectedSkill(string $code, string $expectedSkill): void
    {
        self::assertSame(
            $expectedSkill,
            $this->composition->specialistFor($code),
            "specialistFor('$code') should return '$expectedSkill'",
        );
    }

    /**
     * @return list<array{string, string}>
     */
    public static function specialistForProvider(): array
    {
        return [
            // ISO family
            ['ISO27001',      'isms-specialist'],
            ['ISO27001-2022', 'isms-specialist'],
            ['ISO_27001',     'isms-specialist'],
            // Cloud/regulatory
            ['EUCS',          'isms-specialist'],
            ['NIST',          'isms-specialist'],
            ['NIS2',          'isms-specialist'],
            ['DORA',          'isms-specialist'],
            ['BaFin',         'isms-specialist'],
            // BSI family
            ['BSI_GRUNDSCHUTZ', 'bsi-specialist'],
            ['BSI',           'bsi-specialist'],
            ['TISAX',         'bsi-specialist'],
            // Privacy
            ['GDPR',          'dpo-specialist'],
            ['ISO27701',      'dpo-specialist'],
            ['ISO27018',      'dpo-specialist'],
            // Risk
            ['ISO27005',      'risk-management-specialist'],
            ['ISO31000',      'risk-management-specialist'],
            // BCM
            ['ISO22301',      'bcm-specialist'],
            ['BCM',           'bcm-specialist'],
            // Unknown → default
            ['UNKNOWN_FW',    'isms-specialist'],
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @param array{experts: list<array{skill: string, refs: string}>, lenses: list<string>} $result
     */
    private function assertLensesPresent(array $result): void
    {
        self::assertArrayHasKey('lenses', $result, 'Result must have lenses key');
        self::assertContains('persona-consultant-senior', $result['lenses']);
        self::assertContains('persona-auditor-external',  $result['lenses']);
    }
}
