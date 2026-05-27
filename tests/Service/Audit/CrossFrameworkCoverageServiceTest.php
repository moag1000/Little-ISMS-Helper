<?php

declare(strict_types=1);

namespace App\Tests\Service\Audit;

use App\Entity\AuditFinding;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\InternalAudit;
use App\Repository\ComplianceMappingRepository;
use App\Service\Audit\CoverageReport;
use App\Service\Audit\CrossFrameworkCoverageService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CrossFrameworkCoverageServiceTest extends TestCase
{
    #[Test]
    public function emptyAuditYieldsEmptyReport(): void
    {
        $audit = new InternalAudit();
        $service = new CrossFrameworkCoverageService($this->stubRepository());

        $report = $service->buildReport($audit);

        self::assertInstanceOf(CoverageReport::class, $report);
        self::assertTrue($report->isEmpty());
        self::assertFalse($report->isMultiFramework());
    }

    #[Test]
    public function singleFrameworkAuditIsNotMultiFramework(): void
    {
        $iso27001 = $this->framework(1, 'ISO 27001:2022');
        $req = $this->requirement(101, $iso27001, 'A.5.19');

        $finding = $this->finding(901, [$req]);
        $audit = $this->audit($iso27001, [], [$finding]);

        $report = (new CrossFrameworkCoverageService($this->stubRepository()))
            ->buildReport($audit);

        self::assertFalse($report->isMultiFramework());
        self::assertSame(1, $report->directCount($iso27001));
        self::assertSame(0, $report->transitiveOnlyCount($iso27001));
        self::assertSame(0, $report->estimatedFteDaySaving());
    }

    #[Test]
    public function multiFrameworkAuditWalksOutboundMappings(): void
    {
        $iso27001 = $this->framework(1, 'ISO 27001:2022');
        $iso9001 = $this->framework(2, 'ISO 9001:2015');
        $nis2 = $this->framework(3, 'NIS2');

        $isoReq = $this->requirement(101, $iso27001, 'A.5.19');
        $qmReq = $this->requirement(201, $iso9001, '8.4.1');
        $nis2Req = $this->requirement(301, $nis2, 'Art. 21(2)(d)');

        $mappingToQm = $this->mapping($isoReq, $qmReq, percentage: 85);
        $mappingToNis2 = $this->mapping($isoReq, $nis2Req, percentage: 70);

        $finding = $this->finding(901, [$isoReq]);
        $audit = $this->audit($iso27001, [$iso9001, $nis2], [$finding]);

        $repo = $this->stubRepository([
            101 => [$mappingToQm, $mappingToNis2],
        ]);

        $report = (new CrossFrameworkCoverageService($repo))->buildReport($audit);

        self::assertTrue($report->isMultiFramework());
        self::assertSame(1, $report->directCount($iso27001));
        self::assertSame(1, $report->transitiveOnlyCount($iso9001));
        self::assertSame(1, $report->transitiveOnlyCount($nis2));
        self::assertSame(0, $report->directCount($iso9001));
        self::assertGreaterThan(0, $report->estimatedFteDaySaving());

        $transitiveQm = $report->transitiveRowsFor($iso9001);
        self::assertCount(1, $transitiveQm);
        self::assertSame(85, $transitiveQm[0]['mapping_percentage']);
        self::assertSame($qmReq, $transitiveQm[0]['target_requirement']);
        self::assertSame($finding, $transitiveQm[0]['finding']);
    }

    #[Test]
    public function weakMappingsBelowThresholdAreIgnored(): void
    {
        $iso27001 = $this->framework(1, 'ISO 27001:2022');
        $iso9001 = $this->framework(2, 'ISO 9001:2015');

        $isoReq = $this->requirement(101, $iso27001, 'A.5.19');
        $qmReq = $this->requirement(201, $iso9001, '8.4.1');

        // 30% mapping — below MIN_MAPPING_PERCENTAGE (50)
        $weak = $this->mapping($isoReq, $qmReq, percentage: 30);

        $finding = $this->finding(901, [$isoReq]);
        $audit = $this->audit($iso27001, [$iso9001], [$finding]);

        $repo = $this->stubRepository([101 => [$weak]]);

        $report = (new CrossFrameworkCoverageService($repo))->buildReport($audit);

        self::assertTrue($report->isMultiFramework());
        self::assertSame(0, $report->transitiveOnlyCount($iso9001));
        self::assertSame(0, count($report->transitiveRowsFor($iso9001)));
    }

    #[Test]
    public function targetFrameworkOutOfScopeIsSkipped(): void
    {
        $iso27001 = $this->framework(1, 'ISO 27001:2022');
        $iso9001 = $this->framework(2, 'ISO 9001:2015');
        $dora = $this->framework(99, 'DORA'); // not in audit scope

        $isoReq = $this->requirement(101, $iso27001, 'A.5.19');
        $doraReq = $this->requirement(401, $dora, 'Art. 28');

        $mapping = $this->mapping($isoReq, $doraReq, percentage: 100);

        $finding = $this->finding(901, [$isoReq]);
        // audit only covers ISO 27001 + ISO 9001 (NOT DORA)
        $audit = $this->audit($iso27001, [$iso9001], [$finding]);

        $repo = $this->stubRepository([101 => [$mapping]]);

        $report = (new CrossFrameworkCoverageService($repo))->buildReport($audit);

        // DORA is not in scope, so its transitive coverage should not appear
        self::assertSame(0, $report->transitiveOnlyCount($iso9001));
        $summary = $report->summaryRows();
        $names = array_map(static fn(array $row): string => $row['framework']->getName(), $summary);
        self::assertNotContains('DORA', $names);
    }

    #[Test]
    public function bidirectionalInboundMappingFlowsCoverage(): void
    {
        $iso27001 = $this->framework(1, 'ISO 27001:2022');
        $iso9001 = $this->framework(2, 'ISO 9001:2015');

        $isoReq = $this->requirement(101, $iso27001, 'A.5.19');
        $qmReq = $this->requirement(201, $iso9001, '8.4.1');

        // Inbound bidirectional mapping: source=QM, target=ISO, bidirectional=true
        $bidirectional = $this->mapping($qmReq, $isoReq, percentage: 100, bidirectional: true);

        $finding = $this->finding(901, [$isoReq]);
        $audit = $this->audit($iso27001, [$iso9001], [$finding]);

        $repo = $this->stubRepository(
            outbound: [101 => []],
            inbound: [101 => [$bidirectional]],
        );

        $report = (new CrossFrameworkCoverageService($repo))->buildReport($audit);

        self::assertSame(1, $report->transitiveOnlyCount($iso9001));
    }

    #[Test]
    public function fteDaySavingScalesWithFrameworkCount(): void
    {
        $iso = $this->framework(1, 'ISO 27001');
        $qm = $this->framework(2, 'ISO 9001');
        $nis = $this->framework(3, 'NIS2');

        $audit = $this->audit($iso, [$qm, $nis], []);
        $report = (new CrossFrameworkCoverageService($this->stubRepository()))->buildReport($audit);

        // 3 frameworks, baseline 5 days each, overhead 20% → floor((3-1)*5*0.8) = 8
        self::assertSame(8, $report->estimatedFteDaySaving(5));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function framework(int $id, string $name): ComplianceFramework
    {
        $fw = new ComplianceFramework();
        $fw->id = $id;
        $fw->setName($name);
        return $fw;
    }

    private function requirement(int $id, ComplianceFramework $framework, string $requirementId): ComplianceRequirement
    {
        $req = new ComplianceRequirement();
        // ComplianceRequirement has private id — set via reflection
        $ref = new \ReflectionProperty(ComplianceRequirement::class, 'id');
        $ref->setValue($req, $id);
        $req->setFramework($framework);
        $req->setRequirementId($requirementId);
        $req->setTitle('Requirement ' . $requirementId);
        $req->setDescription('test');
        $req->setPriority('high');
        return $req;
    }

    /**
     * @param list<ComplianceRequirement> $linkedRequirements
     */
    private function finding(int $id, array $linkedRequirements): AuditFinding
    {
        $finding = new AuditFinding();
        $ref = new \ReflectionProperty(AuditFinding::class, 'id');
        $ref->setValue($finding, $id);
        $finding->setFindingNumber('F-' . $id);
        $finding->setTitle('Finding ' . $id);
        foreach ($linkedRequirements as $req) {
            $finding->addLinkedRequirement($req);
        }
        return $finding;
    }

    /**
     * @param list<ComplianceFramework> $additional
     * @param list<AuditFinding>        $findings
     */
    private function audit(ComplianceFramework $primary, array $additional, array $findings): InternalAudit
    {
        $audit = new InternalAudit();
        $audit->setScopedFramework($primary);
        foreach ($additional as $fw) {
            $audit->addAdditionalScopedFramework($fw);
        }
        foreach ($findings as $finding) {
            $audit->addStructuredFinding($finding);
        }
        return $audit;
    }

    private function mapping(
        ComplianceRequirement $source,
        ComplianceRequirement $target,
        int $percentage,
        bool $bidirectional = false,
    ): ComplianceMapping {
        $mapping = new ComplianceMapping();
        $mapping->setSourceRequirement($source);
        $mapping->setTargetRequirement($target);
        $mapping->setMappingPercentage($percentage);
        $mapping->setBidirectional($bidirectional);
        return $mapping;
    }

    /**
     * Stub repository that returns pre-canned mapping arrays keyed by
     * source-requirement id.
     *
     * @param array<int, list<ComplianceMapping>> $outbound
     * @param array<int, list<ComplianceMapping>> $inbound
     */
    private function stubRepository(array $outbound = [], array $inbound = []): ComplianceMappingRepository
    {
        return new class($outbound, $inbound) extends ComplianceMappingRepository {
            /**
             * @param array<int, list<ComplianceMapping>> $outbound
             * @param array<int, list<ComplianceMapping>> $inbound
             */
            public function __construct(
                private readonly array $outbound,
                private readonly array $inbound,
            ) {
                // intentionally skip parent::__construct() — repository methods
                // we use are isolated to the stubbed canned data below.
            }

            public function findMappingsFromRequirement(ComplianceRequirement $complianceRequirement): array
            {
                return $this->outbound[(int) $complianceRequirement->getId()] ?? [];
            }

            public function findMappingsToRequirement(ComplianceRequirement $complianceRequirement): array
            {
                return $this->inbound[(int) $complianceRequirement->getId()] ?? [];
            }

            public function findMappingsBySourceRequirements(array $requirements): array
            {
                $out = [];
                foreach ($requirements as $req) {
                    $id = (int) $req->getId();
                    if (isset($this->outbound[$id])) {
                        $out[$id] = $this->outbound[$id];
                    }
                }
                return $out;
            }

            public function findMappingsByTargetRequirements(array $requirements): array
            {
                $out = [];
                foreach ($requirements as $req) {
                    $id = (int) $req->getId();
                    if (isset($this->inbound[$id])) {
                        $out[$id] = $this->inbound[$id];
                    }
                }
                return $out;
            }
        };
    }
}
