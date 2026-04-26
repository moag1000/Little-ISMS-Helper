<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Repository\BCExerciseRepository;
use App\Repository\IncidentRepository;
use App\Service\MrisKpiService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class MrisKpiServiceTest extends TestCase
{
    private function makeTenant(int $id = 1): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);
        return $tenant;
    }

    private function makeService(?Connection $conn = null): MrisKpiService
    {
        $conn ??= $this->createMock(Connection::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        return new MrisKpiService(
            $em,
            $this->createMock(IncidentRepository::class),
            $this->createMock(BCExerciseRepository::class),
        );
    }

    public function testComputeAllReturnsEightKpis(): void
    {
        $conn = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(0);
        $result->method('fetchAssociative')->willReturn(['total' => 0, 'passing' => 0]);
        $conn->method('executeQuery')->willReturn($result);

        $kpis = $this->makeService($conn)->computeAll($this->makeTenant());

        self::assertCount(8, $kpis);
        $ids = array_column($kpis, 'id');
        self::assertSame([
            'mttc',
            'phishing_resistant_mfa_share',
            'sbom_coverage',
            'kev_patch_latency',
            'restore_test_success_rate',
            'ccm_coverage',
            'crypto_inventory_coverage',
            'tlpt_findings_closure',
        ], $ids);
    }

    public function testEachKpiHasMandatoryFields(): void
    {
        $conn = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(0);
        $result->method('fetchAssociative')->willReturn(['total' => 0, 'passing' => 0]);
        $conn->method('executeQuery')->willReturn($result);

        $kpis = $this->makeService($conn)->computeAll($this->makeTenant());

        foreach ($kpis as $kpi) {
            self::assertArrayHasKey('id', $kpi);
            self::assertArrayHasKey('name', $kpi);
            self::assertArrayHasKey('value', $kpi);
            self::assertArrayHasKey('unit', $kpi);
            self::assertArrayHasKey('source', $kpi);
            self::assertArrayHasKey('computable', $kpi);
            self::assertArrayHasKey('mhc', $kpi);
            self::assertMatchesRegularExpression('/^MHC-\d{2}$/', $kpi['mhc']);
        }
    }

    public function testComputableKpisAreThree(): void
    {
        $conn = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(0);
        $result->method('fetchAssociative')->willReturn(['total' => 0, 'passing' => 0]);
        $conn->method('executeQuery')->willReturn($result);

        $kpis = $this->makeService($conn)->computeAll($this->makeTenant());
        $computable = array_filter($kpis, static fn(array $k): bool => $k['computable']);

        self::assertCount(3, $computable, 'Drei KPIs sollten automatisch berechnet werden: MTTC, MFA-Share, Restore-Test-Quote.');
    }

    public function testManualKpisCarrySourceHint(): void
    {
        $conn = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(0);
        $result->method('fetchAssociative')->willReturn(['total' => 0, 'passing' => 0]);
        $conn->method('executeQuery')->willReturn($result);

        $kpis = $this->makeService($conn)->computeAll($this->makeTenant());
        $manual = array_filter($kpis, static fn(array $k): bool => $k['computable'] === false);

        self::assertCount(5, $manual);
        foreach ($manual as $kpi) {
            self::assertNull($kpi['value']);
            self::assertStringContainsString('manuell', $kpi['source']);
        }
    }

    public function testMttcReturnsHoursWhenIncidentDataPresent(): void
    {
        $conn = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        // Returns 360 minutes = 6 hours
        $result->method('fetchOne')->willReturn(360);
        $result->method('fetchAssociative')->willReturn(['total' => 0, 'passing' => 0]);
        $conn->method('executeQuery')->willReturn($result);

        $kpis = $this->makeService($conn)->computeAll($this->makeTenant());
        $mttc = array_values(array_filter($kpis, static fn(array $k): bool => $k['id'] === 'mttc'))[0];

        self::assertSame(6.0, $mttc['value']);
        self::assertSame('Stunden', $mttc['unit']);
    }

    public function testRestoreTestRateComputesCorrectPercentage(): void
    {
        $conn = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(0);
        // 8 of 10 tests passed (rating>=4) → 80%
        $result->method('fetchAssociative')->willReturn(['total' => 10, 'passing' => 8]);
        $conn->method('executeQuery')->willReturn($result);

        $kpis = $this->makeService($conn)->computeAll($this->makeTenant());
        $rate = array_values(array_filter($kpis, static fn(array $k): bool => $k['id'] === 'restore_test_success_rate'))[0];

        self::assertSame(80.0, $rate['value']);
    }
}
