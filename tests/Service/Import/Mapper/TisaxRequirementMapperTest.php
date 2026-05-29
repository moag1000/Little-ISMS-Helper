<?php

declare(strict_types=1);

namespace App\Tests\Service\Import\Mapper;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Import\Mapper\TisaxRequirementMapper;
use App\Service\Tisax\Dto\VdaIsaControlRow;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TisaxRequirementMapper.
 *
 * Covers:
 *  - computeDelta() — correct add/update/skip categorisation
 *  - mapRows()      — persist ComplianceRequirement rows with tenant scope + uploadTenant
 *  - derivePriority() — maturity-target → priority mapping (via mapRows)
 *  - Edge cases: empty input, duplicate control IDs, missing optional fields
 */
#[AllowMockObjectsWithoutExpectations]
final class TisaxRequirementMapperTest extends TestCase
{
    private MockObject $em;
    private MockObject $requirementRepo;
    private TisaxRequirementMapper $mapper;
    private ComplianceFramework $framework;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em              = $this->createMock(EntityManagerInterface::class);
        $this->requirementRepo = $this->createMock(ComplianceRequirementRepository::class);

        $this->em->method('getRepository')
            ->willReturn($this->requirementRepo);

        $this->mapper = new TisaxRequirementMapper($this->em);

        $this->framework = new ComplianceFramework();
        $this->framework->setCode(TisaxRequirementMapper::FRAMEWORK_CODE);
        $this->framework->setName('TISAX VDA-ISA 6.0');

        $this->tenant = new Tenant();
        $this->tenant->setName('Test Tenant');
        $this->tenant->setCode('test_tenant');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // computeDelta() tests
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function compute_delta_returns_all_new_when_no_existing_rows(): void
    {
        $rows = [
            $this->makeRow('1.1.1', 'Title A'),
            $this->makeRow('1.1.2', 'Title B'),
            $this->makeRow('2.1.1', 'Title C'),
        ];

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $delta = $this->mapper->computeDelta($rows, $this->framework, $this->tenant);

        self::assertSame(3, $delta['total']);
        self::assertSame(3, $delta['new']);
        self::assertSame(0, $delta['existing']);
    }

    #[Test]
    public function compute_delta_counts_existing_rows_correctly(): void
    {
        $rows = [
            $this->makeRow('1.1.1', 'Title A'),
            $this->makeRow('1.1.2', 'Title B'),
            $this->makeRow('2.1.1', 'Title C'),
        ];

        $existingReq = new ComplianceRequirement();

        // '1.1.1' exists, others do not
        $this->requirementRepo
            ->method('findOneBy')
            ->willReturnCallback(static function (array $criteria) use ($existingReq): ?ComplianceRequirement {
                return $criteria['requirementId'] === '1.1.1' ? $existingReq : null;
            });

        $delta = $this->mapper->computeDelta($rows, $this->framework, $this->tenant);

        self::assertSame(3, $delta['total']);
        self::assertSame(2, $delta['new']);
        self::assertSame(1, $delta['existing']);
    }

    #[Test]
    public function compute_delta_with_empty_rows_returns_zero_totals(): void
    {
        $delta = $this->mapper->computeDelta([], $this->framework, $this->tenant);

        self::assertSame(0, $delta['total']);
        self::assertSame(0, $delta['new']);
        self::assertSame(0, $delta['existing']);
    }

    #[Test]
    public function compute_delta_counts_all_as_existing_when_all_present(): void
    {
        $rows = [
            $this->makeRow('1.1.1', 'Title A'),
            $this->makeRow('1.1.2', 'Title B'),
        ];

        $existingReq = new ComplianceRequirement();
        $this->requirementRepo->method('findOneBy')->willReturn($existingReq);

        $delta = $this->mapper->computeDelta($rows, $this->framework, $this->tenant);

        self::assertSame(2, $delta['total']);
        self::assertSame(0, $delta['new']);
        self::assertSame(2, $delta['existing']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // mapRows() tests
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function map_rows_creates_new_requirements_when_none_exist(): void
    {
        $rows = [
            $this->makeRow('1.1.1', 'Information Security Policy'),
            $this->makeRow('1.1.2', 'Roles and Responsibilities'),
        ];

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $persistedEntities = [];
        $this->em->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persistedEntities): void {
                $persistedEntities[] = $entity;
            });
        $this->em->expects(self::once())->method('flush');

        $result = $this->mapper->mapRows($rows, $this->framework, $this->tenant);

        self::assertSame(2, $result['created']);
        self::assertSame(0, $result['updated']);
        self::assertSame(0, $result['skipped']);
        self::assertCount(2, $result['entities']);
    }

    #[Test]
    public function map_rows_updates_existing_requirements(): void
    {
        $existing = new ComplianceRequirement();
        $existing->setRequirementId('1.1.1');
        $existing->setTitle('Old Title');

        $rows = [$this->makeRow('1.1.1', 'Updated Title')];

        $this->requirementRepo->method('findOneBy')->willReturn($existing);

        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $result = $this->mapper->mapRows($rows, $this->framework, $this->tenant);

        self::assertSame(0, $result['created']);
        self::assertSame(1, $result['updated']);
        self::assertSame('Updated Title', $existing->getTitle());
    }

    #[Test]
    public function map_rows_sets_requirement_source_to_tenant_upload(): void
    {
        $rows = [$this->makeRow('1.1.1', 'Test Requirement')];

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $createdEntity = null;
        $this->em->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$createdEntity): void {
                $createdEntity = $entity;
            });
        $this->em->method('flush');

        $this->mapper->mapRows($rows, $this->framework, $this->tenant);

        self::assertInstanceOf(ComplianceRequirement::class, $createdEntity);
        self::assertSame('tenant_upload', $createdEntity->getRequirementSource());
    }

    #[Test]
    public function map_rows_sets_upload_tenant_for_tenant_scoping(): void
    {
        $rows = [$this->makeRow('1.1.1', 'Test Requirement')];

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $createdEntity = null;
        $this->em->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$createdEntity): void {
                $createdEntity = $entity;
            });
        $this->em->method('flush');

        $this->mapper->mapRows($rows, $this->framework, $this->tenant);

        self::assertInstanceOf(ComplianceRequirement::class, $createdEntity);
        self::assertSame($this->tenant, $createdEntity->getUploadTenant());
    }

    #[Test]
    public function map_rows_sets_framework_on_created_requirement(): void
    {
        $rows = [$this->makeRow('2.1.1', 'Access Control')];

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $createdEntity = null;
        $this->em->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$createdEntity): void {
                $createdEntity = $entity;
            });
        $this->em->method('flush');

        $this->mapper->mapRows($rows, $this->framework, $this->tenant);

        self::assertInstanceOf(ComplianceRequirement::class, $createdEntity);
        self::assertSame($this->framework, $createdEntity->getFramework());
    }

    #[Test]
    public function map_rows_dry_run_does_not_persist_or_flush(): void
    {
        $rows = [
            $this->makeRow('1.1.1', 'Test'),
            $this->makeRow('1.1.2', 'Test 2'),
        ];

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');

        $result = $this->mapper->mapRows($rows, $this->framework, $this->tenant, dryRun: true);

        self::assertSame(2, $result['created']);
        self::assertSame(0, $result['updated']);
    }

    #[Test]
    public function map_rows_empty_input_returns_zero_counts_and_no_flush(): void
    {
        $this->em->expects(self::never())->method('flush');

        $result = $this->mapper->mapRows([], $this->framework, $this->tenant);

        self::assertSame(0, $result['created']);
        self::assertSame(0, $result['updated']);
        self::assertSame(0, $result['skipped']);
        self::assertCount(0, $result['entities']);
    }

    #[Test]
    public function map_rows_stores_iso27001_ref_in_data_source_mapping(): void
    {
        $row = $this->makeRow('1.1.1', 'Security Policy', iso27001Ref: 'A.5.1, A.5.2');

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $createdEntity = null;
        $this->em->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$createdEntity): void {
                $createdEntity = $entity;
            });
        $this->em->method('flush');

        $this->mapper->mapRows([$row], $this->framework, $this->tenant);

        self::assertInstanceOf(ComplianceRequirement::class, $createdEntity);
        $mapping = $createdEntity->getDataSourceMapping();
        self::assertIsArray($mapping);
        self::assertSame('A.5.1, A.5.2', $mapping['iso27001']);
    }

    #[Test]
    public function map_rows_creates_with_prefilled_reifegrad_mapped_to_level_string(): void
    {
        // Workbook Reifegrad 3 → 'established' on a freshly created requirement.
        $rows = [$this->makeRow('1.1.1', 'Prefilled Req', maturityCurrent: 3)];

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $createdEntity = null;
        $this->em->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$createdEntity): void {
                $createdEntity = $entity;
            });
        $this->em->method('flush');

        $this->mapper->mapRows($rows, $this->framework, $this->tenant);

        self::assertInstanceOf(ComplianceRequirement::class, $createdEntity);
        self::assertSame('established', $createdEntity->getMaturityCurrent());
    }

    #[Test]
    public function map_rows_fills_prefilled_reifegrad_on_existing_row_when_currently_empty(): void
    {
        // Regression: a row imported with the old (Reifegrad-blind) parser exists
        // with maturityCurrent = null. Re-import of the pre-filled workbook MUST
        // backfill the empty assessment — else the assess-page shows "Bitte wählen".
        $existing = new ComplianceRequirement();
        $existing->setRequirementId('1.1.1');
        $existing->setTitle('Old Title');
        self::assertNull($existing->getMaturityCurrent());

        $rows = [$this->makeRow('1.1.1', 'Updated Title', maturityCurrent: 2)];

        $this->requirementRepo->method('findOneBy')->willReturn($existing);
        $this->em->method('flush');

        $this->mapper->mapRows($rows, $this->framework, $this->tenant);

        self::assertSame('managed', $existing->getMaturityCurrent());
    }

    #[Test]
    public function map_rows_does_not_overwrite_existing_reifegrad_assessment(): void
    {
        // Assessor work guard: an existing real assessment must NOT be clobbered
        // by a re-import, even if the workbook carries a different Reifegrad.
        $existing = new ComplianceRequirement();
        $existing->setRequirementId('1.1.1');
        $existing->setTitle('Assessed');
        $existing->setMaturityCurrent('optimising'); // human-set level 5

        $rows = [$this->makeRow('1.1.1', 'Updated Title', maturityCurrent: 2)];

        $this->requirementRepo->method('findOneBy')->willReturn($existing);
        $this->em->method('flush');

        $this->mapper->mapRows($rows, $this->framework, $this->tenant);

        self::assertSame('optimising', $existing->getMaturityCurrent());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // computeMaturityDiff() tests
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function compute_maturity_diff_reports_changed_existing_rows_only(): void
    {
        // Existing 'established' (3); workbook says 2 ('managed') → a downward change.
        $existing = new ComplianceRequirement();
        $existing->setRequirementId('1.1.1');
        $existing->setTitle('Stored Title');
        $existing->setMaturityCurrent('established');

        $rows = [$this->makeRow('1.1.1', 'Workbook Title', maturityCurrent: 2)];

        $this->requirementRepo->method('findOneBy')->willReturn($existing);

        $diff = $this->mapper->computeMaturityDiff($rows, $this->framework, $this->tenant);

        self::assertCount(1, $diff);
        self::assertSame('1.1.1', $diff[0]['controlId']);
        self::assertSame('established', $diff[0]['currentLevel']);
        self::assertSame(3, $diff[0]['currentInt']);
        self::assertSame('managed', $diff[0]['workbookLevel']);
        self::assertSame(2, $diff[0]['workbookInt']);
        self::assertSame('down', $diff[0]['direction']);
        self::assertSame('Stored Title', $diff[0]['title']);
    }

    #[Test]
    public function compute_maturity_diff_excludes_empty_and_unchanged_and_new_rows(): void
    {
        $rows = [
            $this->makeRow('1.1.1', 'Empty existing', maturityCurrent: 3),   // existing empty → backfill, not a change
            $this->makeRow('1.2.1', 'Unchanged', maturityCurrent: 3),        // existing already 'established' → unchanged
            $this->makeRow('1.3.1', 'Brand new', maturityCurrent: 4),        // no existing row → create, not a change
        ];

        $emptyExisting = new ComplianceRequirement();
        $emptyExisting->setRequirementId('1.1.1');
        // maturityCurrent left null

        $sameExisting = new ComplianceRequirement();
        $sameExisting->setRequirementId('1.2.1');
        $sameExisting->setMaturityCurrent('established');

        $this->requirementRepo->method('findOneBy')
            ->willReturnCallback(static function (array $c) use ($emptyExisting, $sameExisting): ?ComplianceRequirement {
                return match ($c['requirementId']) {
                    '1.1.1' => $emptyExisting,
                    '1.2.1' => $sameExisting,
                    default => null,
                };
            });

        $diff = $this->mapper->computeMaturityDiff($rows, $this->framework, $this->tenant);

        self::assertSame([], $diff);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // derivePriority() — via mapRows (private method tested through public API)
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function derive_priority_returns_critical_when_very_high_level_set(): void
    {
        $row = $this->makeRow('1.1.1', 'Critical Req', veryHighLevel: 'level-3');

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $createdEntity = null;
        $this->em->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$createdEntity): void {
                $createdEntity = $entity;
            });
        $this->em->method('flush');

        $this->mapper->mapRows([$row], $this->framework, $this->tenant);

        self::assertInstanceOf(ComplianceRequirement::class, $createdEntity);
        self::assertSame('critical', $createdEntity->getPriority());
    }

    #[Test]
    public function derive_priority_returns_high_when_high_level_set_without_very_high(): void
    {
        $row = $this->makeRow('1.1.1', 'High Req', highLevel: 'level-2');

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $createdEntity = null;
        $this->em->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$createdEntity): void {
                $createdEntity = $entity;
            });
        $this->em->method('flush');

        $this->mapper->mapRows([$row], $this->framework, $this->tenant);

        self::assertInstanceOf(ComplianceRequirement::class, $createdEntity);
        self::assertSame('high', $createdEntity->getPriority());
    }

    #[Test]
    public function derive_priority_returns_medium_when_only_must_level_set(): void
    {
        $row = $this->makeRow('1.1.1', 'Must Req', mustLevel: 'level-1');

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $createdEntity = null;
        $this->em->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$createdEntity): void {
                $createdEntity = $entity;
            });
        $this->em->method('flush');

        $this->mapper->mapRows([$row], $this->framework, $this->tenant);

        self::assertInstanceOf(ComplianceRequirement::class, $createdEntity);
        self::assertSame('medium', $createdEntity->getPriority());
    }

    #[Test]
    public function derive_priority_returns_low_when_no_maturity_levels_set(): void
    {
        $row = $this->makeRow('1.1.1', 'Low Req');

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $createdEntity = null;
        $this->em->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$createdEntity): void {
                $createdEntity = $entity;
            });
        $this->em->method('flush');

        $this->mapper->mapRows([$row], $this->framework, $this->tenant);

        self::assertInstanceOf(ComplianceRequirement::class, $createdEntity);
        self::assertSame('low', $createdEntity->getPriority());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Edge cases
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function map_rows_with_duplicate_control_ids_processes_both(): void
    {
        // Duplicate control IDs in a real upload should both be processed
        // (the second will update the first if findOneBy returns the already-created entity).
        $rows = [
            $this->makeRow('1.1.1', 'First Entry'),
            $this->makeRow('1.1.1', 'Duplicate Entry'),
        ];

        // First call: no existing → creates new
        // Second call (same ID): still no existing in DB → creates another new
        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $persistCount = 0;
        $this->em->method('persist')
            ->willReturnCallback(static function () use (&$persistCount): void {
                $persistCount++;
            });
        $this->em->method('flush');

        $result = $this->mapper->mapRows($rows, $this->framework, $this->tenant);

        // Both processed (created), since DB has no existing row for either call
        self::assertSame(2, $result['created'] + $result['updated']);
    }

    #[Test]
    public function map_rows_handles_missing_optional_fields_gracefully(): void
    {
        // Row with only mandatory fields (all optionals null)
        $row = new VdaIsaControlRow(
            controlId:        '5.1.1',
            title:            'Minimal Requirement',
            titleEn:          null,
            description:      null,
            mustLevel:        null,
            shouldLevel:      null,
            highLevel:        null,
            veryHighLevel:    null,
            iso27001Ref:      null,
            auditEvidenceHint: null,
            rawRowIndex:      1,
        );

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $createdEntity = null;
        $this->em->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$createdEntity): void {
                $createdEntity = $entity;
            });
        $this->em->method('flush');

        $result = $this->mapper->mapRows([$row], $this->framework, $this->tenant);

        self::assertSame(1, $result['created']);
        self::assertInstanceOf(ComplianceRequirement::class, $createdEntity);
        // Falls back to title when description is null
        self::assertSame('Minimal Requirement', $createdEntity->getDescription());
        // No ISO mapping stored when null
        $mapping = $createdEntity->getDataSourceMapping();
        self::assertFalse(isset($mapping['iso27001']));
    }

    #[Test]
    public function map_rows_truncates_title_to_255_characters(): void
    {
        $longTitle = str_repeat('A', 300);
        $row       = $this->makeRow('1.1.1', $longTitle);

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $createdEntity = null;
        $this->em->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$createdEntity): void {
                $createdEntity = $entity;
            });
        $this->em->method('flush');

        $this->mapper->mapRows([$row], $this->framework, $this->tenant);

        self::assertInstanceOf(ComplianceRequirement::class, $createdEntity);
        self::assertSame(255, mb_strlen((string) $createdEntity->getTitle()));
    }

    #[Test]
    public function map_rows_stores_tisax_maturity_levels_in_data_source_mapping(): void
    {
        $row = $this->makeRow(
            controlId:    '1.1.1',
            title:        'Maturity Test',
            mustLevel:    'level-1',
            shouldLevel:  'level-2',
            highLevel:    'level-3',
            veryHighLevel: 'level-4',
        );

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $createdEntity = null;
        $this->em->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$createdEntity): void {
                $createdEntity = $entity;
            });
        $this->em->method('flush');

        $this->mapper->mapRows([$row], $this->framework, $this->tenant);

        self::assertInstanceOf(ComplianceRequirement::class, $createdEntity);
        $mapping = $createdEntity->getDataSourceMapping();
        self::assertIsArray($mapping);
        self::assertSame('level-1', $mapping['tisax_must']);
        self::assertSame('level-2', $mapping['tisax_should']);
        self::assertSame('level-3', $mapping['tisax_high']);
        self::assertSame('level-4', $mapping['tisax_veryHigh']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Tier / category assignment (via getTier() on VdaIsaControlRow)
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function map_rows_assigns_information_security_tier_for_domain_1_to_6(): void
    {
        $row = $this->makeRow('3.1.1', 'Network Control');

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $createdEntity = null;
        $this->em->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$createdEntity): void {
                $createdEntity = $entity;
            });
        $this->em->method('flush');

        $this->mapper->mapRows([$row], $this->framework, $this->tenant);

        self::assertInstanceOf(ComplianceRequirement::class, $createdEntity);
        self::assertSame('information_security', $createdEntity->getCategory());
    }

    #[Test]
    public function map_rows_assigns_prototype_protection_tier_for_domain_7_to_9(): void
    {
        $row = $this->makeRow('8.1.1', 'Prototype Handling');

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $createdEntity = null;
        $this->em->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$createdEntity): void {
                $createdEntity = $entity;
            });
        $this->em->method('flush');

        $this->mapper->mapRows([$row], $this->framework, $this->tenant);

        self::assertInstanceOf(ComplianceRequirement::class, $createdEntity);
        self::assertSame('prototype_protection', $createdEntity->getCategory());
    }

    #[Test]
    public function map_rows_assigns_data_protection_tier_for_domain_10_to_12(): void
    {
        $row = $this->makeRow('11.2.1', 'Data Subject Rights');

        $this->requirementRepo->method('findOneBy')->willReturn(null);

        $createdEntity = null;
        $this->em->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$createdEntity): void {
                $createdEntity = $entity;
            });
        $this->em->method('flush');

        $this->mapper->mapRows([$row], $this->framework, $this->tenant);

        self::assertInstanceOf(ComplianceRequirement::class, $createdEntity);
        self::assertSame('data_protection', $createdEntity->getCategory());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helper
    // ──────────────────────────────────────────────────────────────────────────

    private function makeRow(
        string  $controlId,
        string  $title,
        ?string $mustLevel     = null,
        ?string $shouldLevel   = null,
        ?string $highLevel     = null,
        ?string $veryHighLevel = null,
        ?string $iso27001Ref   = null,
        ?int    $maturityCurrent = null,
    ): VdaIsaControlRow {
        return new VdaIsaControlRow(
            controlId:         $controlId,
            title:             $title,
            titleEn:           null,
            description:       null,
            mustLevel:         $mustLevel,
            shouldLevel:       $shouldLevel,
            highLevel:         $highLevel,
            veryHighLevel:     $veryHighLevel,
            iso27001Ref:       $iso27001Ref,
            auditEvidenceHint: null,
            rawRowIndex:       1,
            maturityCurrent:   $maturityCurrent,
        );
    }
}
