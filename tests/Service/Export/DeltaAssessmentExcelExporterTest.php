<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\FulfillmentInheritanceLog;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\FulfillmentInheritanceLogRepository;
use App\Service\CompliancePolicyService;
use App\Service\ExcelExportService;
use App\Service\Export\DeltaAssessmentExcelExporter;
use App\Service\GapEffortCalculator;
use App\Service\InheritanceMetricsService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Unit tests for the DeltaAssessmentExcelExporter (CM-2).
 *
 * Exercises the service against in-memory fixtures with stubbed repositories
 * to verify sheet structure, summary KPIs, and row counts without touching the
 * database.
 */
#[AllowMockObjectsWithoutExpectations]
final class DeltaAssessmentExcelExporterTest extends TestCase
{
    private ComplianceRequirementRepository $requirementRepository;
    private ComplianceRequirementFulfillmentRepository $fulfillmentRepository;
    private FulfillmentInheritanceLogRepository $logRepository;
    private ComplianceMappingRepository $mappingRepository;
    private ComplianceFrameworkRepository $frameworkRepository;
    private ExcelExportService $excelExportService;
    private InheritanceMetricsService $inheritanceMetrics;
    private GapEffortCalculator $gapEffortCalculator;
    private TranslatorInterface $translator;

    private Tenant $tenant;
    private ComplianceFramework $targetFramework;
    private ComplianceFramework $baselineFramework;

    protected function setUp(): void
    {
        $this->requirementRepository = $this->createMock(ComplianceRequirementRepository::class);
        $this->fulfillmentRepository = $this->createMock(ComplianceRequirementFulfillmentRepository::class);
        $this->logRepository = $this->createMock(FulfillmentInheritanceLogRepository::class);
        $this->mappingRepository = $this->createMock(ComplianceMappingRepository::class);
        $this->frameworkRepository = $this->createMock(ComplianceFrameworkRepository::class);
        $this->excelExportService = new ExcelExportService();
        $this->gapEffortCalculator = $this->createMock(GapEffortCalculator::class);
        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(
            static fn (string $id): string => $id,
        );

        $this->inheritanceMetrics = $this->createMock(InheritanceMetricsService::class);
        $this->inheritanceMetrics->method('fteSavedForFramework')->willReturn(12.0);

        $this->tenant = new Tenant();
        $this->tenant->setName('Test Tenant AG');
        $this->tenant->setCode('test-tenant');

        $this->targetFramework = $this->buildFramework('NIS2', 'Directive (EU) 2022/2555');
        $this->baselineFramework = $this->buildFramework('ISO27001', '2022');
    }

    public function testExportProducesThreeSheetsWithExpectedTitles(): void
    {
        $this->stubFixture();

        $spreadsheet = $this->exporter()->export(
            $this->tenant,
            $this->targetFramework,
            $this->baselineFramework,
        );

        self::assertSame(3, $spreadsheet->getSheetCount());
        // Titles are truncated to 31 chars by PhpSpreadsheet's validator (substr 0..31).
        self::assertSame('compliance_wizard.delta.sheet.s', $spreadsheet->getSheet(0)->getTitle());
        self::assertSame('compliance_wizard.delta.sheet.d', $spreadsheet->getSheet(1)->getTitle());
        self::assertSame('compliance_wizard.delta.sheet.m', $spreadsheet->getSheet(2)->getTitle());
    }

    public function testDetailSheetHasOneRowPerTargetRequirement(): void
    {
        $this->stubFixture();

        $spreadsheet = $this->exporter()->export(
            $this->tenant,
            $this->targetFramework,
            $this->baselineFramework,
        );

        $detail = $spreadsheet->getSheet(1);
        // Header row + 10 requirement rows.
        self::assertSame(11, $detail->getHighestDataRow());

        // Spot-check: first data row should be NIS2-1 with 100% fulfillment
        self::assertSame('NIS2-1', $detail->getCell('A2')->getValue());
        self::assertSame(100, $detail->getCell('E2')->getValue());
    }

    public function testMappingSheetHasOneRowPerMapping(): void
    {
        $this->stubFixture();

        $spreadsheet = $this->exporter()->export(
            $this->tenant,
            $this->targetFramework,
            $this->baselineFramework,
        );

        $mapping = $spreadsheet->getSheet(2);
        // 6 mappings in the fixture → 1 header + 6 data rows
        self::assertSame(7, $mapping->getHighestDataRow());
    }

    public function testSummaryContainsTenantAndFrameworkMetadata(): void
    {
        $this->stubFixture();

        $spreadsheet = $this->exporter()->export(
            $this->tenant,
            $this->targetFramework,
            $this->baselineFramework,
        );

        $summary = $spreadsheet->getSheet(0);
        $values = $this->flattenCellValues($summary);

        self::assertContains('Test Tenant AG', $values);
        self::assertContains('NIS2 Directive (EU) 2022/2555', $values);
        self::assertContains('ISO27001 2022', $values);
        self::assertContains('10', $values); // total requirements
    }

    public function testSummaryReflectsInheritancePreFillRate(): void
    {
        $this->stubFixture();

        $spreadsheet = $this->exporter()->export(
            $this->tenant,
            $this->targetFramework,
            $this->baselineFramework,
        );

        $summary = $spreadsheet->getSheet(0);
        $values = $this->flattenCellValues($summary);

        // 6 fulfillments inherited of 10 total → 60% → meets target
        self::assertContains('6 (60%)', $values);
    }

    public function testExportWithoutBaselineProducesEmptyMappingSheet(): void
    {
        $this->stubFixture(withBaselineMappings: false);

        $spreadsheet = $this->exporter()->export(
            $this->tenant,
            $this->targetFramework,
            null,
        );

        self::assertSame(3, $spreadsheet->getSheetCount());
        // Mapping sheet contains only the header row
        self::assertSame(1, $spreadsheet->getSheet(2)->getHighestDataRow());
    }

    // ─── Fixtures ───────────────────────────────────────────────────────────

    private function stubFixture(bool $withBaselineMappings = true): void
    {
        $targetRequirements = [];
        $fulfillments = [];
        $logs = [];
        $effortRows = [];

        for ($i = 1; $i <= 10; $i++) {
            $requirement = $this->buildRequirement($this->targetFramework, 'NIS2-' . $i, $i);
            $targetRequirements[] = $requirement;

            $fulfillment = new ComplianceRequirementFulfillment();
            $fulfillment->setTenant($this->tenant);
            $fulfillment->setRequirement($requirement);
            // First 5 requirements are fully fulfilled (baseline inheritance);
            // 6 has a pending inheritance (60% derived);
            // the rest are gaps.
            if ($i <= 5) {
                $fulfillment->setFulfillmentPercentage(100);
                $fulfillment->setStatus('verified');
            } elseif ($i === 6) {
                $fulfillment->setFulfillmentPercentage(60);
                $fulfillment->setStatus('in_progress');
            } else {
                $fulfillment->setFulfillmentPercentage(0);
                $fulfillment->setStatus('not_started');
            }
            $this->assignId($fulfillment, $i * 100);
            $fulfillments[] = $fulfillment;

            // 6 fulfillments carry an inheritance log (req 1..6)
            if ($i <= 6 && $withBaselineMappings) {
                $sourceRequirement = $this->buildRequirement(
                    $this->baselineFramework,
                    'ISO27001-A.' . $i,
                    500 + $i,
                );
                $mapping = $this->buildMapping($sourceRequirement, $requirement);
                $log = new FulfillmentInheritanceLog();
                $log->setTenant($this->tenant);
                $log->setFulfillment($fulfillment);
                $log->setDerivedFromMapping($mapping);
                $log->setSuggestedPercentage($i <= 5 ? 100 : 60);
                $log->setReviewStatus($i <= 5
                    ? FulfillmentInheritanceLog::STATUS_CONFIRMED
                    : FulfillmentInheritanceLog::STATUS_PENDING_REVIEW);
                $logs[] = $log;
            }

            $effortRows[] = [
                'requirement' => $requirement,
                'fulfillment' => $fulfillment,
                'fulfillment_percentage' => $fulfillment->getFulfillmentPercentage(),
                'base_effort_days' => 3,
                'adjusted_effort_days' => null,
                'effective_effort_days' => 3,
                'remaining_effort_days' => round(3 * (1 - $fulfillment->getFulfillmentPercentage() / 100), 2),
                'category' => 'core',
                'priority' => 'high',
                'is_estimated' => true,
                'is_quick_win' => false,
            ];
        }

        $this->requirementRepository
            ->method('findBy')
            ->willReturn($targetRequirements);

        $this->fulfillmentRepository
            ->method('findByFrameworkAndTenant')
            ->willReturn($fulfillments);

        $this->logRepository
            ->method('findForQueue')
            ->willReturn($logs);

        $mappings = [];
        if ($withBaselineMappings) {
            foreach ($logs as $log) {
                $mappings[] = $log->getDerivedFromMapping();
            }
        }
        $this->mappingRepository
            ->method('findCrossFrameworkMappings')
            ->willReturn($mappings);

        $this->gapEffortCalculator
            ->method('calculate')
            ->willReturn($effortRows);
    }

    private function exporter(): DeltaAssessmentExcelExporter
    {
        return new DeltaAssessmentExcelExporter(
            $this->requirementRepository,
            $this->fulfillmentRepository,
            $this->logRepository,
            $this->mappingRepository,
            $this->frameworkRepository,
            $this->excelExportService,
            $this->inheritanceMetrics,
            $this->gapEffortCalculator,
            $this->translator,
        );
    }

    private function buildFramework(string $code, string $version): ComplianceFramework
    {
        $framework = new ComplianceFramework();
        $framework->setCode($code);
        $framework->setName($code . ' ' . $version);
        $framework->setVersion($version);
        $framework->setApplicableIndustry('all');
        $framework->setRegulatoryBody('EU');
        $framework->setMandatory(true);
        $framework->setActive(true);
        return $framework;
    }

    private function buildRequirement(
        ComplianceFramework $framework,
        string $requirementId,
        int $pk,
    ): ComplianceRequirement {
        $requirement = new ComplianceRequirement();
        $requirement->setFramework($framework);
        $requirement->setRequirementId($requirementId);
        $requirement->setTitle('Requirement ' . $requirementId);
        $requirement->setDescription('Desc for ' . $requirementId);
        $requirement->setPriority('high');
        $requirement->setCategory('core');
        $requirement->setBaseEffortDays(3);
        $this->assignId($requirement, $pk);
        return $requirement;
    }

    private function buildMapping(
        ComplianceRequirement $source,
        ComplianceRequirement $target,
    ): ComplianceMapping {
        $mapping = new ComplianceMapping();
        $mapping->setSourceRequirement($source);
        $mapping->setTargetRequirement($target);
        $mapping->setMappingPercentage(80);
        $mapping->setConfidence('high');
        $mapping->setBidirectional(false);
        $mapping->setSource('algorithm_generated_v1.0');
        return $mapping;
    }

    /**
     * Reflection helper so fixtures get a stable numeric ID without persisting.
     */
    private function assignId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setValue($entity, $id);
    }

    /**
     * @return list<string>
     */
    private function flattenCellValues(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): array
    {
        $values = [];
        foreach ($sheet->toArray(null, true, false, false) as $row) {
            foreach ($row as $value) {
                if ($value === null) {
                    continue;
                }
                $values[] = (string) $value;
            }
        }
        return $values;
    }
}
