<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Entity\InternalAudit;
use App\Entity\KpiSnapshot;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\KpiSnapshotRepository;
use App\Service\PostureSnapshotService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * F43 Trust-Center — unit tests for PostureSnapshotService.
 *
 * Verifies the TENANT-DISCLOSURE-SAFE contract:
 *   1. DTO has exactly the §4 allowed keys.
 *   2. Forbidden keys (risk, asset, user, incident, monetary, findings,
 *      DPO, evidence) are NEVER present.
 *   3. Framework names and compliance % surface correctly.
 *   4. lastAuditDate only exposes Y-m-d (no audit title or detail).
 *   5. overallControlPct is pulled from KpiSnapshot.kpiData['control_compliance'].
 */
final class PostureSnapshotServiceTest extends TestCase
{
    /** @var ComplianceFrameworkRepository&MockObject */
    private MockObject $frameworkRepo;

    /** @var ComplianceRequirementRepository&MockObject */
    private MockObject $requirementRepo;

    /** @var KpiSnapshotRepository&MockObject */
    private MockObject $kpiRepo;

    /** @var InternalAuditRepository&MockObject */
    private MockObject $auditRepo;

    private PostureSnapshotService $service;

    protected function setUp(): void
    {
        $this->frameworkRepo   = $this->createMock(ComplianceFrameworkRepository::class);
        $this->requirementRepo = $this->createMock(ComplianceRequirementRepository::class);
        $this->kpiRepo         = $this->createMock(KpiSnapshotRepository::class);
        $this->auditRepo       = $this->createMock(InternalAuditRepository::class);

        $this->service = new PostureSnapshotService(
            $this->frameworkRepo,
            $this->requirementRepo,
            $this->kpiRepo,
            $this->auditRepo,
        );
    }

    #[Test]
    public function snapshotContainsExactlyAllowedTopLevelKeys(): void
    {
        $tenant = $this->buildTenant();

        $this->frameworkRepo->method('findActiveFrameworks')->willReturn([]);
        $this->kpiRepo->method('findClosestBefore')->willReturn(null);
        $this->auditRepo->method('findBy')->willReturn([]);

        $snapshot = $this->service->getSnapshot($tenant);

        // §4 allowed keys — exactly these five top-level keys, nothing more
        $allowedKeys = ['tenantName', 'frameworks', 'frameworkCompliance', 'overallControlPct', 'lastAuditDate', 'snapshotAt'];
        sort($allowedKeys);

        $actualKeys = array_keys($snapshot);
        sort($actualKeys);

        self::assertSame($allowedKeys, $actualKeys, 'DTO must contain exactly the §4 allowed keys — no additions.');
    }

    #[Test]
    public function forbiddenKeysAreNeverPresentInSnapshot(): void
    {
        $tenant = $this->buildTenant();

        $this->frameworkRepo->method('findActiveFrameworks')->willReturn([]);
        $this->kpiRepo->method('findClosestBefore')->willReturn(null);
        $this->auditRepo->method('findBy')->willReturn([]);

        $snapshot = $this->service->getSnapshot($tenant);

        // Flatten snapshot to all string keys (including nested arrays)
        $allKeys = $this->flattenKeys($snapshot);

        $forbidden = ['risk', 'asset', 'user', 'incident', 'finding', 'dpo', 'evidence',
                      'personal', 'eur', 'usd', 'chf', 'monetary', 'password', 'token',
                      'secret', 'email', 'phone', 'address'];

        foreach ($forbidden as $bad) {
            foreach ($allKeys as $key) {
                self::assertStringNotContainsStringIgnoringCase(
                    $bad,
                    $key,
                    "Forbidden key fragment '$bad' must not appear in posture DTO (found in key '$key')."
                );
            }
        }
    }

    #[Test]
    public function frameworkNamesAndCodesAreExposed(): void
    {
        $tenant = $this->buildTenant();

        $fw = $this->buildFramework('ISO27001', 'ISO/IEC 27001:2022');

        $this->frameworkRepo->method('findActiveFrameworks')->willReturn([$fw]);
        $this->requirementRepo->method('getFrameworkStatisticsForTenant')->willReturn([
            'total' => 93, 'applicable' => 80, 'fulfilled' => 60, 'critical_gaps' => 5,
        ]);
        $this->kpiRepo->method('findClosestBefore')->willReturn(null);
        $this->auditRepo->method('findBy')->willReturn([]);

        $snapshot = $this->service->getSnapshot($tenant);

        self::assertCount(1, $snapshot['frameworks']);
        self::assertSame('ISO27001', $snapshot['frameworks'][0]['code']);
        self::assertSame('ISO/IEC 27001:2022', $snapshot['frameworks'][0]['name']);
    }

    #[Test]
    public function frameworkCompliancePercentIsCalculatedCorrectly(): void
    {
        $tenant = $this->buildTenant();
        $fw     = $this->buildFramework('ISO27001', 'ISO/IEC 27001:2022');

        $this->frameworkRepo->method('findActiveFrameworks')->willReturn([$fw]);
        $this->requirementRepo->method('getFrameworkStatisticsForTenant')->willReturn([
            'total' => 93, 'applicable' => 80, 'fulfilled' => 60, 'critical_gaps' => 0,
        ]);
        $this->kpiRepo->method('findClosestBefore')->willReturn(null);
        $this->auditRepo->method('findBy')->willReturn([]);

        $snapshot = $this->service->getSnapshot($tenant);

        $fc = $snapshot['frameworkCompliance'][0];
        self::assertSame(60, $fc['fulfilled']);
        self::assertSame(80, $fc['applicable']);
        self::assertSame(75.0, $fc['percent']);  // 60/80 * 100 = 75.0
    }

    #[Test]
    public function overallControlPctComeFromKpiSnapshot(): void
    {
        $tenant = $this->buildTenant();

        $kpi = $this->createMock(KpiSnapshot::class);
        $kpi->method('getKpiData')->willReturn(['control_compliance' => 82]);

        $this->frameworkRepo->method('findActiveFrameworks')->willReturn([]);
        $this->kpiRepo->method('findClosestBefore')->willReturn($kpi);
        $this->auditRepo->method('findBy')->willReturn([]);

        $snapshot = $this->service->getSnapshot($tenant);

        self::assertSame(82.0, $snapshot['overallControlPct']);
    }

    #[Test]
    public function overallControlPctIsNullWhenNoKpiSnapshot(): void
    {
        $tenant = $this->buildTenant();

        $this->frameworkRepo->method('findActiveFrameworks')->willReturn([]);
        $this->kpiRepo->method('findClosestBefore')->willReturn(null);
        $this->auditRepo->method('findBy')->willReturn([]);

        $snapshot = $this->service->getSnapshot($tenant);

        self::assertNull($snapshot['overallControlPct']);
    }

    #[Test]
    public function lastAuditDateOnlyExposesDatePart(): void
    {
        $tenant = $this->buildTenant();

        $audit = $this->createMock(InternalAudit::class);
        $audit->method('getStatus')->willReturn('completed');
        $audit->method('getActualDate')->willReturn(new DateTimeImmutable('2026-03-15 14:30:00'));

        $this->frameworkRepo->method('findActiveFrameworks')->willReturn([]);
        $this->kpiRepo->method('findClosestBefore')->willReturn(null);
        $this->auditRepo->method('findBy')->willReturn([$audit]);

        $snapshot = $this->service->getSnapshot($tenant);

        // Must be Y-m-d only — no time component, no audit title or detail
        self::assertSame('2026-03-15', $snapshot['lastAuditDate']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $snapshot['lastAuditDate']);
    }

    #[Test]
    public function lastAuditDateIsNullWhenNoCompletedAudit(): void
    {
        $tenant = $this->buildTenant();

        $audit = $this->createMock(InternalAudit::class);
        $audit->method('getStatus')->willReturn('planned');

        $this->frameworkRepo->method('findActiveFrameworks')->willReturn([]);
        $this->kpiRepo->method('findClosestBefore')->willReturn(null);
        $this->auditRepo->method('findBy')->willReturn([$audit]);

        $snapshot = $this->service->getSnapshot($tenant);

        self::assertNull($snapshot['lastAuditDate']);
    }

    #[Test]
    public function snapshotAtIsCurrentDateTimeImmutable(): void
    {
        $tenant = $this->buildTenant();
        $before = new DateTimeImmutable('-1 second');

        $this->frameworkRepo->method('findActiveFrameworks')->willReturn([]);
        $this->kpiRepo->method('findClosestBefore')->willReturn(null);
        $this->auditRepo->method('findBy')->willReturn([]);

        $snapshot = $this->service->getSnapshot($tenant);

        self::assertInstanceOf(DateTimeImmutable::class, $snapshot['snapshotAt']);
        self::assertGreaterThanOrEqual($before, $snapshot['snapshotAt']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('ACME GmbH');
        $tenant->setCode('acme');
        return $tenant;
    }

    private function buildFramework(string $code, string $name): ComplianceFramework
    {
        $fw = new ComplianceFramework();
        $fw->setCode($code);
        $fw->setName($name);
        $fw->setVersion('2022');
        $fw->setApplicableIndustry('all');
        $fw->setRegulatoryBody('ISO');
        return $fw;
    }

    /**
     * Recursively collect all string keys from a nested array.
     *
     * @param array<mixed> $data
     * @return list<string>
     */
    private function flattenKeys(array $data, string $prefix = ''): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix !== '' ? $prefix . '.' . $key : (string) $key;
            $keys[]  = $fullKey;
            if (is_array($value)) {
                array_push($keys, ...$this->flattenKeys($value, $fullKey));
            }
        }
        return $keys;
    }
}
