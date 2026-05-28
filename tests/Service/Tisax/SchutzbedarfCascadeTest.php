<?php

declare(strict_types=1);

namespace App\Tests\Service\Tisax;

use App\Entity\ComplianceRequirement;
use App\Service\Tisax\RequirementLevelMetadataLoader;
use App\Service\Tisax\TisaxMaturityAssessmentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Schutzbedarf-Cascade × VDA-ISA control-addendum-presence tests.
 *
 * Verifies that TisaxMaturityAssessmentService correctly surfaces whether
 * a given ComplianceRequirement has High / Very-High protection-need addenda
 * in the ENX workbook, using the RequirementLevelMetadataLoader fixture.
 *
 * These tests verify presence-flag logic only — no ENX-licensed text is
 * read or asserted.
 */
class SchutzbedarfCascadeTest extends TestCase
{
    private RequirementLevelMetadataLoader $loader;
    private TisaxMaturityAssessmentService $service;

    protected function setUp(): void
    {
        $projectDir   = dirname(__DIR__, 3);
        $this->loader = new RequirementLevelMetadataLoader($projectDir);

        $em = $this->createStub(EntityManagerInterface::class);
        $this->service = new TisaxMaturityAssessmentService($em, $this->loader);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getApplicableAssessmentLevels
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function al1_level_for_any_control(): void
    {
        $req = $this->makeReq('1.1.1');
        $als  = $this->service->getApplicableAssessmentLevels($req);
        self::assertContains('AL1', $als);
    }

    #[Test]
    public function al3_level_for_high_protection_control(): void
    {
        // 1.1.1 has high_protection: true per fixture
        $req = $this->makeReq('1.1.1');
        $als  = $this->service->getApplicableAssessmentLevels($req);
        self::assertContains('AL3', $als);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function highProtectionProvider(): array
    {
        return [
            '1.1.1 has high addendum'   => ['1.1.1',  true],
            '1.2.2 no high addendum'    => ['1.2.2',  false],
            '4.1.1 has high addendum'   => ['4.1.1',  true],
            '5.2.7 no high addendum'    => ['5.2.7',  false],
        ];
    }

    #[Test]
    #[DataProvider('highProtectionProvider')]
    public function requires_high_protection_addendum_matches_fixture(string $controlId, bool $expected): void
    {
        $req = $this->makeReq($controlId);
        self::assertSame($expected, $this->service->requiresHighProtectionAddendum($req));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function veryHighProtectionProvider(): array
    {
        return [
            '1.4.1 has very-high addendum' => ['1.4.1',  true],
            '1.1.1 no very-high addendum'  => ['1.1.1',  false],
            '4.1.1 has very-high addendum' => ['4.1.1',  true],
            '1.2.2 no very-high addendum'  => ['1.2.2',  false],
        ];
    }

    #[Test]
    #[DataProvider('veryHighProtectionProvider')]
    public function requires_very_high_protection_addendum_matches_fixture(string $controlId, bool $expected): void
    {
        $req = $this->makeReq($controlId);
        self::assertSame($expected, $this->service->requiresVeryHighProtectionAddendum($req));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getLevelFlags
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function get_level_flags_returns_four_booleans(): void
    {
        $req   = $this->makeReq('1.1.1');
        $flags = $this->service->getLevelFlags($req);

        self::assertArrayHasKey('must', $flags);
        self::assertArrayHasKey('should', $flags);
        self::assertArrayHasKey('high_protection', $flags);
        self::assertArrayHasKey('very_high_protection', $flags);

        foreach ($flags as $flag) {
            self::assertIsBool($flag);
        }
    }

    #[Test]
    public function get_level_flags_for_known_high_control(): void
    {
        $req   = $this->makeReq('1.1.1');
        $flags = $this->service->getLevelFlags($req);

        self::assertTrue($flags['must'], '1.1.1 must have must=true');
        self::assertTrue($flags['should'], '1.1.1 must have should=true');
        self::assertTrue($flags['high_protection'], '1.1.1 must have high_protection=true');
        self::assertFalse($flags['very_high_protection'], '1.1.1 must have very_high_protection=false');
    }

    #[Test]
    public function get_level_flags_for_unknown_control_returns_all_false(): void
    {
        $req   = $this->makeReq('99.99.99');
        $flags = $this->service->getLevelFlags($req);

        self::assertFalse($flags['must']);
        self::assertFalse($flags['should']);
        self::assertFalse($flags['high_protection']);
        self::assertFalse($flags['very_high_protection']);
    }

    #[Test]
    public function get_applicable_assessment_levels_returns_empty_for_unknown(): void
    {
        $req = $this->makeReq('99.99.99');
        self::assertSame([], $this->service->getApplicableAssessmentLevels($req));
    }

    #[Test]
    public function no_metadata_loader_returns_safe_defaults(): void
    {
        $em      = $this->createStub(EntityManagerInterface::class);
        $service = new TisaxMaturityAssessmentService($em, null);
        $req     = $this->makeReq('1.1.1');

        self::assertSame([], $service->getApplicableAssessmentLevels($req));
        self::assertFalse($service->requiresHighProtectionAddendum($req));
        self::assertFalse($service->requiresVeryHighProtectionAddendum($req));

        $flags = $service->getLevelFlags($req);
        self::assertFalse($flags['must']);
        self::assertFalse($flags['high_protection']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Structural invariant: high controls count
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function fixture_has_reasonable_high_protection_count(): void
    {
        $highIds = $this->loader->controlIdsWithLevel('high_protection');
        // Based on pre-seeded structural data: expect 20+ controls with high addenda
        self::assertGreaterThan(20, count($highIds),
            'Expected at least 20 controls with high-protection addenda in the fixture');
    }

    #[Test]
    public function fixture_has_fewer_very_high_than_high_controls(): void
    {
        $high     = count($this->loader->controlIdsWithLevel('high_protection'));
        $veryHigh = count($this->loader->controlIdsWithLevel('very_high_protection'));
        self::assertLessThan($high, $veryHigh,
            'Very-high addendum controls must be fewer than high addendum controls');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function makeReq(string $controlId): ComplianceRequirement
    {
        $req = new ComplianceRequirement();
        $req->setRequirementId($controlId);
        return $req;
    }
}
