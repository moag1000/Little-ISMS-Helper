<?php

declare(strict_types=1);

namespace App\Tests\Service\Kpi;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Enum\RiskStatus;
use App\Repository\RiskRepository;
use App\Service\Kpi\RiskExposure;
use App\Service\TenantRecords;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class RiskExposureTest extends TestCase
{
    private function risk(string $title, ?int $sle, ?float $aro, RiskStatus $status = RiskStatus::Assessed): Risk
    {
        return (new Risk())
            ->setTitle($title)
            ->setSingleLossExpectancy($sle)
            ->setAnnualRateOfOccurrence($aro)
            ->setStatus($status);
    }

    #[Test]
    public function sumsOpenQuantifiedRisksAndRanksTheTopFive(): void
    {
        $repo = $this->createMock(RiskRepository::class);
        $repo->method('findBy')->willReturn([
            $this->risk('Ransomware', 200000, 0.5),           // 100 000
            $this->risk('RZ-Ausfall', 50000, 2.0),            // 100 000
            $this->risk('Phishing', 10000, 3.0),              //  30 000
            $this->risk('Ohne Quant', null, null),            // open, not quantified
            $this->risk('Erledigt', 900000, 1.0, RiskStatus::Closed),   // closed: out
            $this->risk('Akzeptiert', 5000, 1.0, RiskStatus::Accepted), // carried loss: in
        ]);

        $result = (new RiskExposure($repo, new TenantRecords()))->forTenant($this->createMock(Tenant::class), 2);

        self::assertSame(235000, $result['total']);
        self::assertSame(4, $result['quantified']);
        self::assertSame(5, $result['open']);
        self::assertCount(2, $result['top']);
        self::assertSame(100000, $result['top'][0]['ale']);
        self::assertNotContains('Erledigt', array_column($result['top'], 'title'));
    }
}
