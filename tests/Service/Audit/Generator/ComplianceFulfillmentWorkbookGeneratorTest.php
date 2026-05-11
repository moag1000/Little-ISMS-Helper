<?php

declare(strict_types=1);

namespace App\Tests\Service\Audit\Generator;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Service\Audit\Generator\ComplianceFulfillmentWorkbookGenerator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ComplianceFulfillmentWorkbookGeneratorTest extends TestCase
{
    #[Test]
    public function itSupportsOnlyComplianceFulfillmentType(): void
    {
        $generator = new ComplianceFulfillmentWorkbookGenerator(
            $this->createStub(ComplianceRequirementFulfillmentRepository::class)
        );

        self::assertTrue($generator->supportsExportType('compliance-fulfillment'));
        self::assertFalse($generator->supportsExportType('soa'));
        self::assertFalse($generator->supportsExportType('risk-register'));
    }

    #[Test]
    public function itReturnsNonEmptySpreadsheet(): void
    {
        $repo = $this->createStub(ComplianceRequirementFulfillmentRepository::class);
        $repo->method('findBy')->willReturn([]);

        $generator = new ComplianceFulfillmentWorkbookGenerator($repo);
        $result = $generator->generate($this->buildTenantStub());

        self::assertInstanceOf(Spreadsheet::class, $result);
    }

    #[Test]
    public function itProducesTwoSheets(): void
    {
        $repo = $this->createStub(ComplianceRequirementFulfillmentRepository::class);
        $repo->method('findBy')->willReturn([]);

        $generator = new ComplianceFulfillmentWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        self::assertCount(2, $spreadsheet->getAllSheets());
    }

    #[Test]
    public function itHasExpectedSheetNames(): void
    {
        $repo = $this->createStub(ComplianceRequirementFulfillmentRepository::class);
        $repo->method('findBy')->willReturn([]);

        $generator = new ComplianceFulfillmentWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        $names = array_map(fn($s): string => $s->getTitle(), $spreadsheet->getAllSheets());

        self::assertContains('Cover', $names);
        self::assertContains('Fulfillments', $names);
    }

    #[Test]
    public function fulfillmentsSheetContainsDataRowPerFulfillment(): void
    {
        $fulfillment = $this->buildFulfillmentStub('ISO/IEC 27001:2022', 'CL-5.1', 'Leadership');

        $repo = $this->createStub(ComplianceRequirementFulfillmentRepository::class);
        $repo->method('findBy')->willReturn([$fulfillment]);

        $generator = new ComplianceFulfillmentWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        $sheet = $spreadsheet->getSheetByName('Fulfillments');
        self::assertNotNull($sheet);

        // Row 1 = headers, Row 2 = first data row
        self::assertSame('ISO/IEC 27001:2022', $sheet->getCell('A2')->getValue());
        self::assertSame('CL-5.1', $sheet->getCell('B2')->getValue());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function buildTenantStub(): Tenant
    {
        $t = $this->createStub(Tenant::class);
        $t->method('getName')->willReturn('Compliance Test GmbH');
        return $t;
    }

    private function buildFulfillmentStub(
        string $frameworkName,
        string $requirementId,
        string $requirementTitle,
    ): ComplianceRequirementFulfillment {
        $framework = $this->createStub(ComplianceFramework::class);
        $framework->method('getName')->willReturn($frameworkName);

        $requirement = $this->createStub(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn($requirementId);
        $requirement->method('getTitle')->willReturn($requirementTitle);
        $requirement->method('getCategory')->willReturn('Governance');
        $requirement->method('getFramework')->willReturn($framework);

        $fulfillment = $this->createStub(ComplianceRequirementFulfillment::class);
        $fulfillment->method('getRequirement')->willReturn($requirement);
        $fulfillment->method('isApplicable')->willReturn(true);
        $fulfillment->method('getStatus')->willReturn('in_progress');
        $fulfillment->method('getFulfillmentPercentage')->willReturn(60);
        $fulfillment->method('getEvidenceDescription')->willReturn(null);
        $fulfillment->method('getLastReviewDate')->willReturn(null);
        $fulfillment->method('getNextReviewDate')->willReturn(null);
        $fulfillment->method('getEffectiveResponsiblePerson')->willReturn(null);

        return $fulfillment;
    }
}
