<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\AuditLogger;
use App\Service\MrisBaselineService;
use App\Service\MrisMaturityService;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class MrisBaselineServiceTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = realpath(__DIR__ . '/../..') ?: '';
    }

    private function makeService(?ComplianceFramework $framework = null, array $requirements = []): MrisBaselineService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $frameworkRepo->method('findOneBy')->willReturn($framework);

        $reqRepo = $this->createMock(ComplianceRequirementRepository::class);
        $reqRepo->method('findOneBy')->willReturnCallback(
            static fn(array $criteria): ?ComplianceRequirement
                => $requirements[$criteria['requirementId'] ?? ''] ?? null,
        );

        $audit = $this->createMock(AuditLogger::class);
        $maturity = new MrisMaturityService($em, $audit);

        return new MrisBaselineService($em, $frameworkRepo, $reqRepo, $maturity, $this->projectDir);
    }

    public function testListBaselinesReturnsAllFourBranchProfiles(): void
    {
        $service = $this->makeService();
        $baselines = $service->listBaselines();

        self::assertGreaterThanOrEqual(4, count($baselines));
        $ids = array_column($baselines, 'id');
        self::assertContains('kritis-essential', $ids);
        self::assertContains('finance-dora', $ids);
        self::assertContains('automotive-tisax-al3', $ids);
        self::assertContains('saas-cra-2027', $ids);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function baselineIdsProvider(): array
    {
        return [
            'KRITIS by id'        => ['kritis-essential'],
            'KRITIS by filename'  => ['kritis'],
            'Finance by id'       => ['finance-dora'],
            'Finance by filename' => ['finance'],
            'Automotive'          => ['automotive_tisax'],
            'SaaS'                => ['saas_cra'],
        ];
    }

    #[DataProvider('baselineIdsProvider')]
    public function testLoadBaselineFindsByIdOrFilename(string $idOrFile): void
    {
        $service = $this->makeService();
        $baseline = $service->loadBaseline($idOrFile);

        self::assertArrayHasKey('id', $baseline);
        self::assertArrayHasKey('mhc_targets', $baseline);
        self::assertCount(13, $baseline['mhc_targets'], 'Jede Baseline muss alle 13 MHCs abdecken.');

        // Jeder MHC-Eintrag muss target und reason haben
        foreach ($baseline['mhc_targets'] as $mhcId => $config) {
            self::assertMatchesRegularExpression('/^MHC-\d{2}$/', $mhcId);
            self::assertArrayHasKey('target', $config);
            self::assertContains($config['target'], ['initial', 'defined', 'managed'], "Stage für $mhcId muss valid sein");
            self::assertArrayHasKey('reason', $config);
        }
    }

    public function testLoadBaselineThrowsForUnknownId(): void
    {
        $service = $this->makeService();
        $this->expectException(DomainException::class);
        $service->loadBaseline('nonexistent-baseline-xyz');
    }

    public function testApplyBaselineWithoutFrameworkThrows(): void
    {
        $service = $this->makeService(null);
        $this->expectException(DomainException::class);
        $service->applyBaseline(new Tenant(), 'kritis');
    }

    public function testApplyBaselineDryRunReportsAllAppliedAndZeroMissing(): void
    {
        $framework = new ComplianceFramework();
        $framework->setCode('MRIS-v1.5');

        $requirements = [];
        for ($i = 1; $i <= 13; $i++) {
            $req = new ComplianceRequirement();
            $req->setRequirementId(sprintf('MHC-%02d', $i));
            $requirements[sprintf('MHC-%02d', $i)] = $req;
        }

        $service = $this->makeService($framework, $requirements);
        $result = $service->applyBaseline(new Tenant(), 'kritis-essential', dryRun: true);

        self::assertSame('kritis-essential', $result['baseline']);
        self::assertSame(13, $result['applied']);
        self::assertSame(0, $result['skipped']);
        self::assertEmpty($result['missing_mhcs']);
    }

    public function testApplyBaselineReportsMissingMhcs(): void
    {
        $framework = new ComplianceFramework();
        $framework->setCode('MRIS-v1.5');

        $requirements = [];
        // Only 5 of 13 MHCs exist
        foreach (['MHC-01', 'MHC-02', 'MHC-03', 'MHC-08', 'MHC-11'] as $mhc) {
            $req = new ComplianceRequirement();
            $req->setRequirementId($mhc);
            $requirements[$mhc] = $req;
        }

        $service = $this->makeService($framework, $requirements);
        $result = $service->applyBaseline(new Tenant(), 'finance-dora', dryRun: true);

        self::assertSame(5, $result['applied']);
        self::assertCount(8, $result['missing_mhcs']);
    }

    public function testFinanceBaselineHasManagedTargetForTlpt(): void
    {
        $service = $this->makeService();
        $baseline = $service->loadBaseline('finance-dora');
        // DORA Art. 26/27 verlangt TLPT — MHC-12 muss Managed sein
        self::assertSame('managed', $baseline['mhc_targets']['MHC-12']['target']);
    }

    public function testSaasBaselineHasManagedTargetForSbom(): void
    {
        $service = $this->makeService();
        $baseline = $service->loadBaseline('saas-cra-2027');
        // CRA Annex I Teil II — SBOM-Pflicht → MHC-02 Managed
        self::assertSame('managed', $baseline['mhc_targets']['MHC-02']['target']);
    }
}
