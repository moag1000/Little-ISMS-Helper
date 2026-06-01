<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Repository\PortfolioSnapshotRepository;
use App\Repository\RiskRepository;
use App\Service\AuditFreezeSnapshotBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the TISAX Reifegrad portion of the audit-freeze snapshot (B2,
 * spec §9.1 / §9.4). Asserts that a frozen snapshot carries the per-requirement
 * Reifegrad-Stand (current/target/reviewedAt + dimension + DP tristate + tiers)
 * keyed by the canonical VDA-ISA control number.
 */
#[AllowMockObjectsWithoutExpectations]
final class AuditFreezeSnapshotBuilderTest extends TestCase
{
    private ComplianceFrameworkRepository $frameworkRepo;
    private ComplianceRequirementFulfillmentRepository $fulfillmentRepo;
    private ComplianceRequirementRepository $requirementRepo;
    private ControlRepository $controlRepo;
    private RiskRepository $riskRepo;
    private PortfolioSnapshotRepository $portfolioRepo;
    private AuditFreezeSnapshotBuilder $builder;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->frameworkRepo   = $this->createMock(ComplianceFrameworkRepository::class);
        $this->fulfillmentRepo = $this->createMock(ComplianceRequirementFulfillmentRepository::class);
        $this->requirementRepo = $this->createMock(ComplianceRequirementRepository::class);
        $this->controlRepo     = $this->createMock(ControlRepository::class);
        $this->riskRepo        = $this->createMock(RiskRepository::class);
        $this->portfolioRepo   = $this->createMock(PortfolioSnapshotRepository::class);

        // Neutral stubs for the non-TISAX sections.
        $this->controlRepo->method('findByTenant')->willReturn([]);
        $this->riskRepo->method('findByTenant')->willReturn([]);
        $this->fulfillmentRepo->method('findByFrameworkAndTenant')->willReturn([]);
        $this->fulfillmentRepo->method('getAverageFulfillmentPercentage')->willReturn(0.0);
        $this->portfolioRepo->method('findBy')->willReturn([]);

        $this->builder = new AuditFreezeSnapshotBuilder(
            $this->frameworkRepo,
            $this->fulfillmentRepo,
            $this->requirementRepo,
            $this->controlRepo,
            $this->riskRepo,
            $this->portfolioRepo,
        );

        $this->tenant = new Tenant();
        $this->tenant->setName('Test Tenant');
        $this->tenant->setCode('test_tenant');
    }

    #[Test]
    public function freeze_snapshot_captures_tisax_reifegrad_row(): void
    {
        $framework = new ComplianceFramework();
        $framework->setCode('TISAX');
        $framework->setName('TISAX VDA-ISA 6.0');

        $reviewedAt = new DateTimeImmutable('2026-05-15T10:00:00+00:00');

        $req = new ComplianceRequirement();
        $req->setRequirementId('1.1.1');
        $req->setTitle('Information Security Policy');
        $req->setCategory('information_security');
        $req->setMaturityCurrent('managed');
        $req->setMaturityTarget('established');
        $req->setMaturityReviewedAt($reviewedAt);
        $req->setDataSourceMapping([
            'tisax_must'   => 'fully',
            'tisax_should' => 'partially',
            'tisax_high'   => 'fully',
            'maturityRaw'  => '2',
        ]);

        $this->frameworkRepo->method('findOneBy')
            ->willReturnCallback(static function (array $criteria) use ($framework): ?ComplianceFramework {
                return $criteria['code'] === 'TISAX' ? $framework : null;
            });

        $this->requirementRepo->expects(self::once())
            ->method('findTisaxAssessedByFrameworkAndTenant')
            ->with($framework, $this->tenant)
            ->willReturn([$req]);

        $snapshot = $this->builder->build($this->tenant, new DateTimeImmutable('2026-05-31'), ['TISAX']);

        self::assertArrayHasKey('tisax_maturity', $snapshot);
        self::assertCount(1, $snapshot['tisax_maturity']);

        $row = $snapshot['tisax_maturity'][0];
        self::assertSame('TISAX', $row['framework']);
        self::assertSame('1.1.1', $row['requirement_id']);
        self::assertSame('information_security', $row['category']);
        self::assertSame('managed', $row['maturity_current']);
        self::assertSame('established', $row['maturity_target']);
        self::assertSame('2026-05-15T10:00:00+00:00', $row['maturity_reviewed_at']);
        self::assertSame('2', $row['maturity_raw']);
        self::assertSame(
            ['must' => 'fully', 'should' => 'partially', 'high' => 'fully'],
            $row['tiers'],
        );
    }

    #[Test]
    public function freeze_snapshot_captures_data_protection_tristate(): void
    {
        $framework = new ComplianceFramework();
        $framework->setCode('TISAX');
        $framework->setName('TISAX VDA-ISA 6.0');

        $req = new ComplianceRequirement();
        $req->setRequirementId('9.1.1');
        $req->setTitle('Data Subject Rights');
        $req->setCategory('data_protection');
        $req->setAssessmentStateDp('compliant');
        $req->setMaturityReviewedAt(new DateTimeImmutable('2026-05-20'));
        $req->setDataSourceMapping(['maturityRaw' => 'OK']);

        $this->frameworkRepo->method('findOneBy')->willReturn($framework);
        $this->requirementRepo->method('findTisaxAssessedByFrameworkAndTenant')->willReturn([$req]);

        $snapshot = $this->builder->build($this->tenant, new DateTimeImmutable('2026-05-31'), ['TISAX']);

        $row = $snapshot['tisax_maturity'][0];
        self::assertSame('data_protection', $row['category']);
        self::assertSame('compliant', $row['assessment_state_dp']);
        self::assertSame('OK', $row['maturity_raw']);
    }

    #[Test]
    public function freeze_snapshot_skips_non_tisax_frameworks(): void
    {
        $iso = new ComplianceFramework();
        $iso->setCode('ISO27001');
        $iso->setName('ISO 27001');

        $this->frameworkRepo->method('findOneBy')->willReturn($iso);
        $this->requirementRepo->expects(self::never())
            ->method('findTisaxAssessedByFrameworkAndTenant');

        $snapshot = $this->builder->build($this->tenant, new DateTimeImmutable('2026-05-31'), ['ISO27001']);

        self::assertSame([], $snapshot['tisax_maturity']);
    }
}
