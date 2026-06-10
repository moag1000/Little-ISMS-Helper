<?php

declare(strict_types=1);

namespace App\Tests\Service\Bsi;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Bsi\IsoToBsiGapService;
use App\Service\Bsi\MappingCorroborationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * WS-5b Stage 1 — MappingCorroborationService unit tests.
 *
 * Covers:
 *  - A heuristic mapping whose (Baustein, isoControl) IS in the CRT →
 *    marked as crt_corroborated → trustOf() returns amtlich_gestuetzt.
 *  - A heuristic mapping NOT in the CRT → stays residual / heuristisch.
 *  - Official CRT rows are never modified.
 *  - Idempotency: a row already at crt_corroborated is not re-written.
 *  - trustOf() covers all trust tiers.
 *  - bausteinCodeFrom() parses both category-prefix and requirementId-prefix.
 */
#[AllowMockObjectsWithoutExpectations]
final class MappingCorroborationServiceTest extends TestCase
{
    private ComplianceMappingRepository&MockObject $mappingRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private MappingCorroborationService $service;
    private IsoToBsiGapService $trustService;

    private ComplianceFramework $iso;
    private ComplianceFramework $bsi;

    protected function setUp(): void
    {
        $this->mappingRepository = $this->createMock(ComplianceMappingRepository::class);
        $this->entityManager     = $this->createMock(EntityManagerInterface::class);
        $this->service           = new MappingCorroborationService(
            $this->mappingRepository,
            $this->entityManager,
        );
        // IsoToBsiGapService requires 3 repo args; pass minimal mocks since only
        // trustOf()/requiresReview()/bausteinCodeFrom() are exercised here.
        $this->trustService = new IsoToBsiGapService(
            $this->createMock(ComplianceRequirementRepository::class),
            $this->createMock(ComplianceMappingRepository::class),
            $this->createMock(ComplianceRequirementFulfillmentRepository::class),
        );

        // Create framework stubs with deterministic IDs
        $this->iso = $this->makeFramework(1, 'ISO27001');
        $this->bsi = $this->makeFramework(2, 'BSI_GRUNDSCHUTZ');
    }

    // ── corroborate() ──────────────────────────────────────────────────────

    #[Test]
    public function corroboratedMappingIsElevatedToAmtlichGestuetzt(): void
    {
        // Official CRT: SYS.1.2 ↔ A.8.9
        $crtMapping = $this->makeCrtMapping('A.8.9', 'SYS.1.2.A3', 'SYS.1.2 Windows Server');

        // Heuristic: same Baustein + ISO control → should be elevated
        $heuristicMapping = $this->makeHeuristicMapping('A.8.9', 'SYS.1.2.A5', 'SYS.1.2 Windows Server');

        $this->mappingRepository
            ->method('findAllGlobal')
            ->willReturn([$crtMapping, $heuristicMapping]);

        // Expect flush once
        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->service->corroborate($this->iso, $this->bsi, dryRun: false);

        self::assertSame(1, $result['corroborated']);
        self::assertSame(0, $result['residual']);
        self::assertSame(1, $result['already_official']);
        // The heuristic mapping must have been elevated
        self::assertSame(
            IsoToBsiGapService::PROVENANCE_CRT_CORROBORATED,
            $heuristicMapping->getProvenanceSource(),
        );
    }

    #[Test]
    public function residualMappingIsNotElevated(): void
    {
        // Official CRT: SYS.1.2 ↔ A.8.9
        $crtMapping = $this->makeCrtMapping('A.8.9', 'SYS.1.2.A3', 'SYS.1.2 Windows Server');

        // Heuristic mapping for a DIFFERENT ISO control → residual
        $heuristicMapping = $this->makeHeuristicMapping('A.8.10', 'SYS.1.2.A5', 'SYS.1.2 Windows Server');

        $this->mappingRepository
            ->method('findAllGlobal')
            ->willReturn([$crtMapping, $heuristicMapping]);

        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->service->corroborate($this->iso, $this->bsi, dryRun: false);

        self::assertSame(0, $result['corroborated']);
        self::assertSame(1, $result['residual']);
        // provenanceSource must NOT be changed
        self::assertNull($heuristicMapping->getProvenanceSource());
    }

    #[Test]
    public function dryRunDoesNotFlushOrMutate(): void
    {
        $crtMapping       = $this->makeCrtMapping('A.8.9', 'SYS.1.2.A3', 'SYS.1.2 Windows Server');
        $heuristicMapping = $this->makeHeuristicMapping('A.8.9', 'SYS.1.2.A5', 'SYS.1.2 Windows Server');

        $this->mappingRepository
            ->method('findAllGlobal')
            ->willReturn([$crtMapping, $heuristicMapping]);

        // No flush in dry-run
        $this->entityManager->expects(self::never())->method('flush');

        $result = $this->service->corroborate($this->iso, $this->bsi, dryRun: true);

        self::assertSame(1, $result['corroborated']);
        // provenanceSource must NOT have been changed in dry-run
        self::assertNull($heuristicMapping->getProvenanceSource());
    }

    #[Test]
    public function idempotency_alreadyElevatedMappingIsNotRewritten(): void
    {
        $crtMapping = $this->makeCrtMapping('A.8.9', 'SYS.1.2.A3', 'SYS.1.2 Windows Server');

        // Mapping already elevated from a previous run
        $alreadyElevated = $this->makeHeuristicMapping('A.8.9', 'SYS.1.2.A5', 'SYS.1.2 Windows Server');
        $alreadyElevated->setProvenanceSource(IsoToBsiGapService::PROVENANCE_CRT_CORROBORATED);

        $this->mappingRepository
            ->method('findAllGlobal')
            ->willReturn([$crtMapping, $alreadyElevated]);

        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->service->corroborate($this->iso, $this->bsi, dryRun: false);

        // Still counted as corroborated
        self::assertSame(1, $result['corroborated']);
        self::assertSame(0, $result['residual']);

        // The detail entry should show was_elevated=false (no-op)
        self::assertCount(1, $result['details']);
        self::assertFalse($result['details'][0]['was_elevated']);

        // Value should remain unchanged
        self::assertSame(
            IsoToBsiGapService::PROVENANCE_CRT_CORROBORATED,
            $alreadyElevated->getProvenanceSource(),
        );
    }

    #[Test]
    public function officialCrtRowsAreNeverModified(): void
    {
        $crtMapping = $this->makeCrtMapping('A.5.1', 'ISMS.1.A1', 'ISMS.1 Security Management');

        $this->mappingRepository
            ->method('findAllGlobal')
            ->willReturn([$crtMapping]);

        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->service->corroborate($this->iso, $this->bsi, dryRun: false);

        self::assertSame(0, $result['corroborated']);
        self::assertSame(0, $result['residual']);
        self::assertSame(1, $result['already_official']);
        // provenanceSource must remain official_bsi_crosswalk
        self::assertSame(
            IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT,
            $crtMapping->getProvenanceSource(),
        );
    }

    #[Test]
    public function categoryPrefixTakesPrecedenceOverRequirementIdForBaustein(): void
    {
        // CRT row using category field (canonical)
        $crtMapping = $this->makeCrtMapping('A.8.9', 'SYS.1.2.A3', 'SYS.1.2 Windows Server');

        // Heuristic mapping that has the correct category
        $heuristic = $this->makeHeuristicMapping('A.8.9', 'SYS.1.2.A7', 'SYS.1.2 Windows Server');

        $this->mappingRepository->method('findAllGlobal')->willReturn([$crtMapping, $heuristic]);
        $this->entityManager->method('flush');

        $result = $this->service->corroborate($this->iso, $this->bsi, dryRun: false);

        self::assertSame(1, $result['corroborated'], 'Category-prefix Baustein extraction must work');
    }

    #[Test]
    public function requirementIdPrefixFallbackExtractsBaustein(): void
    {
        // CRT row with no category — Baustein derived from requirementId prefix
        $crtMapping = $this->makeCrtMappingNoCategory('A.8.9', 'SYS.1.2.A3');
        $heuristic  = $this->makeHeuristicMappingNoCategory('A.8.9', 'SYS.1.2.A7');

        $this->mappingRepository->method('findAllGlobal')->willReturn([$crtMapping, $heuristic]);
        $this->entityManager->method('flush');

        $result = $this->service->corroborate($this->iso, $this->bsi, dryRun: false);

        self::assertSame(1, $result['corroborated'], 'requirementId-prefix fallback must yield same Baustein');
    }

    // ── IsoToBsiGapService::trustOf() ──────────────────────────────────────

    #[Test]
    public function trustOfReturnsAmtlichForOfficialCrt(): void
    {
        $m = new ComplianceMapping();
        $m->setProvenanceSource(IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT);

        self::assertSame(IsoToBsiGapService::TIER_AMTLICH, $this->trustService->trustOf($m));
    }

    #[Test]
    public function trustOfReturnsAmtlichGestuetztForCrtCorroborated(): void
    {
        $m = new ComplianceMapping();
        $m->setProvenanceSource(IsoToBsiGapService::PROVENANCE_CRT_CORROBORATED);

        self::assertSame(IsoToBsiGapService::TIER_AMTLICH_GESTUETZT, $this->trustService->trustOf($m));
    }

    #[Test]
    public function trustOfReturnsKiValidiertForApprovedPanel(): void
    {
        $m = new ComplianceMapping();
        $m->setProvenanceSource('panel');
        $m->setReviewStatus('approved');

        self::assertSame(IsoToBsiGapService::TIER_KI_VALIDIERT, $this->trustService->trustOf($m));
    }

    #[Test]
    public function trustOfReturnsBestaetigtForManualProvenance(): void
    {
        $m = new ComplianceMapping();
        $m->setProvenanceSource('manual');

        self::assertSame(IsoToBsiGapService::TIER_BESTAETIGT, $this->trustService->trustOf($m));
    }

    #[Test]
    public function trustOfReturnsBestaetigtForConfirmedReviewStatus(): void
    {
        $m = new ComplianceMapping();
        $m->setReviewStatus('confirmed');

        self::assertSame(IsoToBsiGapService::TIER_BESTAETIGT, $this->trustService->trustOf($m));
    }

    #[Test]
    public function trustOfReturnsHeuristischByDefault(): void
    {
        $m = new ComplianceMapping();
        // no provenanceSource, default reviewStatus = 'unreviewed'

        self::assertSame(IsoToBsiGapService::TIER_HEURISTISCH, $this->trustService->trustOf($m));
    }

    #[Test]
    public function corroboratedMappingIsTrustedAndNotInPruefenBucket(): void
    {
        $m = new ComplianceMapping();
        $m->setProvenanceSource(IsoToBsiGapService::PROVENANCE_CRT_CORROBORATED);

        self::assertFalse(
            $this->trustService->requiresReview($m),
            'amtlich_gestuetzt tier must NOT land in the prüfen bucket',
        );
    }

    #[Test]
    public function heuristischMappingIsInPruefenBucket(): void
    {
        $m = new ComplianceMapping();

        self::assertTrue(
            $this->trustService->requiresReview($m),
            'heuristisch tier MUST land in the prüfen bucket',
        );
    }

    // ── IsoToBsiGapService::bausteinCodeFrom() ─────────────────────────────

    #[Test]
    public function bausteinCodeFromCategoryPrefix(): void
    {
        self::assertSame('SYS.1.2', IsoToBsiGapService::bausteinCodeFrom('SYS.1.2 Windows Server', null));
        self::assertSame('ORP.4', IsoToBsiGapService::bausteinCodeFrom('ORP.4 Identity Management', null));
    }

    #[Test]
    public function bausteinCodeFromRequirementIdPrefix(): void
    {
        self::assertSame('SYS.1.2', IsoToBsiGapService::bausteinCodeFrom(null, 'SYS.1.2.A3'));
        self::assertSame('ISMS.1', IsoToBsiGapService::bausteinCodeFrom(null, 'ISMS.1.A1'));
        self::assertSame('NET.1.1', IsoToBsiGapService::bausteinCodeFrom(null, 'NET.1.1.A15'));
    }

    #[Test]
    public function bausteinCodeFromEmptyInputsReturnsEmptyString(): void
    {
        self::assertSame('', IsoToBsiGapService::bausteinCodeFrom(null, null));
        self::assertSame('', IsoToBsiGapService::bausteinCodeFrom('', ''));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeFramework(int $id, string $code): ComplianceFramework
    {
        $fw = $this->createMock(ComplianceFramework::class);
        $fw->method('getId')->willReturn($id);
        $fw->method('getCode')->willReturn($code);
        return $fw;
    }

    /**
     * Build a real ComplianceMapping (not a mock) so setProvenanceSource() mutations work.
     */
    private function buildMapping(
        string $isoControlId,
        string $bsiRequirementId,
        ?string $bsiCategory,
        ?string $provenanceSource,
    ): ComplianceMapping {
        $isoReq = $this->createMock(ComplianceRequirement::class);
        $isoReq->method('getRequirementId')->willReturn($isoControlId);
        $isoReq->method('getFramework')->willReturn($this->iso);

        $bsiReq = $this->createMock(ComplianceRequirement::class);
        $bsiReq->method('getRequirementId')->willReturn($bsiRequirementId);
        $bsiReq->method('getCategory')->willReturn($bsiCategory);
        $bsiReq->method('getFramework')->willReturn($this->bsi);

        $mapping = new ComplianceMapping();
        // Bypass the private collection init by using setters
        $mapping->setSourceRequirement($isoReq);
        $mapping->setTargetRequirement($bsiReq);
        if ($provenanceSource !== null) {
            $mapping->setProvenanceSource($provenanceSource);
        }
        return $mapping;
    }

    private function makeCrtMapping(
        string $isoControlId,
        string $bsiRequirementId,
        string $bsiCategory,
    ): ComplianceMapping {
        return $this->buildMapping(
            $isoControlId,
            $bsiRequirementId,
            $bsiCategory,
            IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT,
        );
    }

    private function makeCrtMappingNoCategory(string $isoControlId, string $bsiRequirementId): ComplianceMapping
    {
        return $this->buildMapping($isoControlId, $bsiRequirementId, null, IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT);
    }

    private function makeHeuristicMapping(
        string $isoControlId,
        string $bsiRequirementId,
        string $bsiCategory,
    ): ComplianceMapping {
        return $this->buildMapping($isoControlId, $bsiRequirementId, $bsiCategory, null);
    }

    private function makeHeuristicMappingNoCategory(string $isoControlId, string $bsiRequirementId): ComplianceMapping
    {
        return $this->buildMapping($isoControlId, $bsiRequirementId, null, null);
    }
}
