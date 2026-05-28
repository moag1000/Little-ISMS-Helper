<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Nis2Art21CoverageService;
use App\Service\Nis2ComplianceService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Nis2Art21CoverageService unit tests.
 *
 * Verifies that LETTER_KEY_MAP maps NIS2-ART21-A through NIS2-ART21-J to the
 * directive-correct 21.2.a through 21.2.j keys (1:1, no legacy scrambling).
 * Also verifies there is no reference to the non-existent 21.2.k.
 */
class Nis2Art21CoverageServiceTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function directiveMappingProvider(): array
    {
        return [
            'A → a (risk management)' => ['NIS2-ART21-A', '21.2.a'],
            'B → b (incident handling)' => ['NIS2-ART21-B', '21.2.b'],
            'C → c (BCM / business continuity)' => ['NIS2-ART21-C', '21.2.c'],
            'D → d (supply chain security)' => ['NIS2-ART21-D', '21.2.d'],
            'E → e (secure development)' => ['NIS2-ART21-E', '21.2.e'],
            'F → f (effectiveness assessment)' => ['NIS2-ART21-F', '21.2.f'],
            'G → g (cyber hygiene + training)' => ['NIS2-ART21-G', '21.2.g'],
            'H → h (cryptography)' => ['NIS2-ART21-H', '21.2.h'],
            'I → i (HR security + access + asset)' => ['NIS2-ART21-I', '21.2.i'],
            'J → j (MFA + secure comms)' => ['NIS2-ART21-J', '21.2.j'],
        ];
    }

    #[Test]
    #[DataProvider('directiveMappingProvider')]
    public function letterKeyMapMapsDirectiveControlToCorrectLetter(string $controlId, string $expectedLetterKey): void
    {
        $map = $this->extractLetterKeyMap();

        $this->assertArrayHasKey($controlId, $map, "$controlId must be present in LETTER_KEY_MAP");
        $this->assertSame($expectedLetterKey, $map[$controlId],
            "$controlId should map to $expectedLetterKey (directive-correct), got '$map[$controlId]' instead");
    }

    #[Test]
    public function letterKeyMapHasExactlyTenEntries(): void
    {
        $map = $this->extractLetterKeyMap();

        $this->assertCount(10, $map, 'LETTER_KEY_MAP must have exactly 10 entries (a..j)');
    }

    #[Test]
    public function letterKeyMapDoesNotContainLegacyK(): void
    {
        $map = $this->extractLetterKeyMap();

        foreach ($map as $controlId => $letterKey) {
            $this->assertNotSame('21.2.k', $letterKey,
                "21.2.k does not exist in the NIS2 Directive — found as value for $controlId");
        }
    }

    #[Test]
    public function letterKeyMapDoesNotHaveScrambledAssignments(): void
    {
        $map = $this->extractLetterKeyMap();

        // Explicit regression guards for the known legacy scrambling
        // C was wrongly mapped to 21.2.j (BCM), should be 21.2.c
        if (isset($map['NIS2-ART21-C'])) {
            $this->assertNotSame('21.2.j', $map['NIS2-ART21-C'], 'NIS2-ART21-C (BCM) was wrongly mapped to 21.2.j in legacy grid');
        }
        // D was wrongly mapped to 21.2.f (supply chain), should be 21.2.d
        if (isset($map['NIS2-ART21-D'])) {
            $this->assertNotSame('21.2.f', $map['NIS2-ART21-D'], 'NIS2-ART21-D (supply chain) was wrongly mapped to 21.2.f in legacy grid');
        }
        // H was wrongly mapped to 21.2.k (crypto), should be 21.2.h
        if (isset($map['NIS2-ART21-H'])) {
            $this->assertNotSame('21.2.k', $map['NIS2-ART21-H'], 'NIS2-ART21-H (crypto) was wrongly mapped to 21.2.k in legacy grid');
        }
        // I was wrongly mapped to 21.2.h (access control only), should be 21.2.i
        if (isset($map['NIS2-ART21-I'])) {
            $this->assertNotSame('21.2.h', $map['NIS2-ART21-I'], 'NIS2-ART21-I (HR+access+asset) was wrongly mapped to 21.2.h in legacy grid');
        }
        // J was wrongly mapped to 21.2.b (MFA), should be 21.2.j
        if (isset($map['NIS2-ART21-J'])) {
            $this->assertNotSame('21.2.b', $map['NIS2-ART21-J'], 'NIS2-ART21-J (MFA+comms) was wrongly mapped to 21.2.b in legacy grid');
        }
    }

    #[Test]
    public function getCoverageRollupReturnsTenRequirements(): void
    {
        $nis2ComplianceService = $this->buildNis2ComplianceServiceStub();
        $frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $frameworkRepo->method('findOneBy')->willReturn(null);
        $requirementRepo = $this->createMock(ComplianceRequirementRepository::class);
        $requirementRepo->method('findByFramework')->willReturn([]);

        $service = new Nis2Art21CoverageService($nis2ComplianceService, $frameworkRepo, $requirementRepo);
        $rollup = $service->getCoverageRollup();

        $this->assertCount(10, $rollup, 'getCoverageRollup() must return exactly 10 items');
    }

    #[Test]
    public function getCoverageRollupHasNoNullMetricForDirectiveCorrectKeys(): void
    {
        // Build a compliance service that returns all 10 directive-correct letters
        $nis2ComplianceService = $this->buildNis2ComplianceServiceStub();
        $frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $frameworkRepo->method('findOneBy')->willReturn(null);
        $requirementRepo = $this->createMock(ComplianceRequirementRepository::class);
        $requirementRepo->method('findByFramework')->willReturn([]);

        $service = new Nis2Art21CoverageService($nis2ComplianceService, $frameworkRepo, $requirementRepo);
        $rollup = $service->getCoverageRollup();

        // Every item should have a non-null metric since the stub returns all 10 letters
        foreach ($rollup as $item) {
            $this->assertNotNull($item['metric'],
                "getCoverageRollup() item {$item['controlId']} has null metric — LETTER_KEY_MAP entry missing or wrong key");
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Extract the private LETTER_KEY_MAP constant via reflection.
     *
     * @return array<string, string>
     */
    private function extractLetterKeyMap(): array
    {
        $rc = new ReflectionClass(Nis2Art21CoverageService::class);
        /** @var array<string, string> $map */
        $map = $rc->getConstant('LETTER_KEY_MAP');
        return $map;
    }

    /**
     * Build a Nis2ComplianceService stub that returns a minimal but correct
     * 10-letter payload (all letters a..j present, status 'info').
     */
    private function buildNis2ComplianceServiceStub(): Nis2ComplianceService
    {
        $stub = $this->createMock(Nis2ComplianceService::class);

        $letters = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'] as $l) {
            $key = "21.2.$l";
            $letters[$key] = [
                'letter' => $key,
                'title' => "Measure $l",
                'value' => 50.0,
                'unit' => '%',
                'status' => 'info',
                'details' => [],
            ];
        }

        $stub->method('getDashboardPayload')->willReturn([
            'letters' => $letters,
            'article23' => ['letter' => '23', 'status' => 'info'],
            'overall' => ['score' => 50.0, 'weighted' => [], 'applicable_count' => 10],
        ]);

        return $stub;
    }
}
