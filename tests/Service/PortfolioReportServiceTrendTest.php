<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\PortfolioSnapshot;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\PortfolioSnapshotRepository;
use App\Service\PortfolioReportService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Trend-delta coverage for PortfolioReportService::buildMatrixWithTrend()
 * introduced as part of CM-3.
 *
 * The old buildMatrix() kept its signature (used by the Excel export) and
 * still returns delta=0 (placeholder) — the new method overlays a real
 * integer delta when a previous-period snapshot exists, and null otherwise.
 */
class PortfolioReportServiceTrendTest extends TestCase
{
    private Tenant $tenant;
    private ComplianceFramework $framework;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->framework = new ComplianceFramework();
        $this->framework->setCode('ISO27001');
        $this->framework->setName('ISO/IEC 27001:2022');
    }

    #[Test]
    public function testDeltaIsNullWhenNoSnapshotRepository(): void
    {
        $frameworkRepo = $this->createStub(ComplianceFrameworkRepository::class);
        $frameworkRepo->method('findActiveFrameworks')->willReturn([$this->framework]);

        $fulfillmentRepo = $this->createStub(ComplianceRequirementFulfillmentRepository::class);
        $fulfillmentRepo->method('findByFrameworkAndTenant')->willReturn(
            $this->fulfillmentsInCategory('Protect', [80, 70, 90]),
        );

        $service = new PortfolioReportService($frameworkRepo, $fulfillmentRepo, null);

        $matrix = $service->buildMatrixWithTrend(
            $this->tenant,
            new DateTimeImmutable('2026-04-17'),
            new DateTimeImmutable('2026-04-10'),
        );

        $protectRow = $this->findRow($matrix, 'Protect');
        self::assertNotNull($protectRow);
        self::assertNull($protectRow['cells']['ISO27001']['delta']);
    }

    #[Test]
    public function testDeltaIsNullWhenNoHistoricalSnapshot(): void
    {
        $frameworkRepo = $this->createStub(ComplianceFrameworkRepository::class);
        $frameworkRepo->method('findActiveFrameworks')->willReturn([$this->framework]);

        $fulfillmentRepo = $this->createStub(ComplianceRequirementFulfillmentRepository::class);
        $fulfillmentRepo->method('findByFrameworkAndTenant')->willReturn(
            $this->fulfillmentsInCategory('Protect', [80, 70, 90]),
        );

        $snapshotRepo = $this->createStub(PortfolioSnapshotRepository::class);
        $snapshotRepo->method('findClosestCellOnOrBefore')->willReturn(null);

        $service = new PortfolioReportService($frameworkRepo, $fulfillmentRepo, $snapshotRepo);

        $matrix = $service->buildMatrixWithTrend(
            $this->tenant,
            new DateTimeImmutable('2026-04-17'),
            new DateTimeImmutable('2026-04-10'),
        );

        $protectRow = $this->findRow($matrix, 'Protect');
        self::assertNotNull($protectRow);
        self::assertNull($protectRow['cells']['ISO27001']['delta']);
    }

    #[Test]
    public function testDeltaIsComputedAgainstHistoricalSnapshot(): void
    {
        $frameworkRepo = $this->createStub(ComplianceFrameworkRepository::class);
        $frameworkRepo->method('findActiveFrameworks')->willReturn([$this->framework]);

        // Current avg = (80+70+90)/3 = 80
        $fulfillmentRepo = $this->createStub(ComplianceRequirementFulfillmentRepository::class);
        $fulfillmentRepo->method('findByFrameworkAndTenant')->willReturn(
            $this->fulfillmentsInCategory('Protect', [80, 70, 90]),
        );

        // Historical snapshot: 65% for Protect/ISO27001 → delta = 80-65 = +15
        $previous = new PortfolioSnapshot();
        $previous->setTenant($this->tenant);
        $previous->setSnapshotDate(new DateTimeImmutable('2026-04-01'));
        $previous->setFrameworkCode('ISO27001');
        $previous->setNistCsfCategory('Protect');
        $previous->setFulfillmentPercentage(65);
        $previous->setRequirementCount(3);
        $previous->setGapCount(2);

        $snapshotRepo = $this->createStub(PortfolioSnapshotRepository::class);
        $snapshotRepo->method('findClosestCellOnOrBefore')->willReturnCallback(
            fn(Tenant $t, \DateTimeInterface $d, string $fw, string $cat): ?PortfolioSnapshot =>
                ($fw === 'ISO27001' && $cat === 'Protect') ? $previous : null
        );

        $service = new PortfolioReportService($frameworkRepo, $fulfillmentRepo, $snapshotRepo);

        $matrix = $service->buildMatrixWithTrend(
            $this->tenant,
            new DateTimeImmutable('2026-04-17'),
            new DateTimeImmutable('2026-04-10'),
        );

        $protectRow = $this->findRow($matrix, 'Protect');
        self::assertNotNull($protectRow);
        self::assertSame(80, $protectRow['cells']['ISO27001']['pct']);
        self::assertSame(15, $protectRow['cells']['ISO27001']['delta']);
    }

    #[Test]
    public function testDeltaIsNullWhenCellIsEmpty(): void
    {
        $frameworkRepo = $this->createStub(ComplianceFrameworkRepository::class);
        $frameworkRepo->method('findActiveFrameworks')->willReturn([$this->framework]);

        // No fulfillments at all → count = 0 for every category
        $fulfillmentRepo = $this->createStub(ComplianceRequirementFulfillmentRepository::class);
        $fulfillmentRepo->method('findByFrameworkAndTenant')->willReturn([]);

        $snapshotRepo = $this->createStub(PortfolioSnapshotRepository::class);
        // Should never be called for empty cells — but make it safe:
        $snapshotRepo->method('findClosestCellOnOrBefore')->willReturn(null);

        $service = new PortfolioReportService($frameworkRepo, $fulfillmentRepo, $snapshotRepo);

        $matrix = $service->buildMatrixWithTrend(
            $this->tenant,
            new DateTimeImmutable('2026-04-17'),
            new DateTimeImmutable('2026-04-10'),
        );

        foreach ($matrix['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                self::assertSame(0, $cell['count']);
                self::assertNull($cell['delta']);
            }
        }
    }

    #[Test]
    public function testBuildMatrixLegacyShapeIsUntouched(): void
    {
        $frameworkRepo = $this->createStub(ComplianceFrameworkRepository::class);
        $frameworkRepo->method('findActiveFrameworks')->willReturn([$this->framework]);
        $fulfillmentRepo = $this->createStub(ComplianceRequirementFulfillmentRepository::class);
        $fulfillmentRepo->method('findByFrameworkAndTenant')->willReturn([]);

        $service = new PortfolioReportService($frameworkRepo, $fulfillmentRepo, null);

        // buildMatrix() still exists with its old contract (Excel export relies on it).
        $matrix = $service->buildMatrix(
            $this->tenant,
            new DateTimeImmutable('2026-04-17'),
        );

        self::assertArrayHasKey('rows', $matrix);
        self::assertArrayHasKey('frameworks', $matrix);
        foreach ($matrix['rows'] as $row) {
            foreach ($row['cells'] as $cell) {
                // Legacy cells always expose delta as an int (0 placeholder).
                self::assertIsInt($cell['delta']);
                self::assertSame(0, $cell['delta']);
            }
        }
    }

    /**
     * Build a list of fulfillments whose requirements reliably map to the given
     * NIST CSF category via PortfolioReportService::mapRequirementToCategory().
     *
     * @param list<int> $percentages
     * @return list<ComplianceRequirementFulfillment>
     */
    private function fulfillmentsInCategory(string $category, array $percentages): array
    {
        // Use category keywords that hit the target bucket first.
        $categoryHint = match ($category) {
            'Protect' => 'access',
            'Identify' => 'risk',
            'Detect' => 'monitoring',
            'Respond' => 'incident',
            'Recover' => 'recovery',
            'Govern' => 'policy',
            default => 'asset',
        };

        $out = [];
        foreach ($percentages as $idx => $pct) {
            $requirement = new ComplianceRequirement();
            $requirement->setRequirementId('REQ-' . $idx);
            $requirement->setTitle('Dummy ' . $categoryHint . ' requirement');
            $requirement->setCategory($categoryHint);

            $fulfillment = new ComplianceRequirementFulfillment();
            $fulfillment->setRequirement($requirement);
            $fulfillment->setApplicable(true);
            $fulfillment->setFulfillmentPercentage($pct);

            $out[] = $fulfillment;
        }
        return $out;
    }

    /**
     * @param array{rows: list<array{category: string, cells: array}>} $matrix
     */
    private function findRow(array $matrix, string $category): ?array
    {
        foreach ($matrix['rows'] as $row) {
            if ($row['category'] === $category) {
                return $row;
            }
        }
        return null;
    }
}
