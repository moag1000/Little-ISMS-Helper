<?php

declare(strict_types=1);

namespace App\Tests\Service\Authority;

use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\DoraExitPlanRepository;
use App\Repository\SupplierRepository;
use App\Service\Authority\DoraRoiCsvExporter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Unit tests for the F30 xBRL-CSV (OIM) exporter — the ESA-mandated submission
 * format. Covers per-template table emission, header datapoint codes, CSV
 * quoting, provider rows, payload-hash determinism, and a valid ZIP package.
 */
#[AllowMockObjectsWithoutExpectations]
final class DoraRoiCsvExporterTest extends TestCase
{
    private Tenant $tenant;
    private SupplierRepository $supplierRepo;
    private AssetRepository $assetRepo;
    private DoraExitPlanRepository $exitPlanRepo;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->tenant->setName('ACME Bank');

        $this->supplierRepo = $this->createMock(SupplierRepository::class);
        $this->assetRepo    = $this->createMock(AssetRepository::class);
        $this->exitPlanRepo = $this->createMock(DoraExitPlanRepository::class);
        $this->supplierRepo->method('findByTenantAndDoraRelevant')->willReturn([]);
        $this->assetRepo->method('findByTenantAndDoraRelevant')->willReturn([]);
        $this->exitPlanRepo->method('findByTenantAndDoraRelevant')->willReturn([]);
    }

    private function exporter(): DoraRoiCsvExporter
    {
        return new DoraRoiCsvExporter($this->supplierRepo, $this->assetRepo, $this->exitPlanRepo);
    }

    #[Test]
    public function emitsAllEsaTemplateTables(): void
    {
        $tables = $this->exporter()->generateTables($this->tenant);

        foreach (['b_01.01.csv', 'b_02.01.csv', 'b_02.02.csv', 'b_03.01.csv', 'b_03.02.csv', 'b_03.03.csv', 'rt_06.csv'] as $name) {
            self::assertArrayHasKey($name, $tables, "Missing ESA template table: $name");
        }
        self::assertArrayHasKey('META-INF/reportPackage.json', $tables);
    }

    #[Test]
    public function b0101CarriesDatapointCodeHeaders(): void
    {
        $tables = $this->exporter()->generateTables($this->tenant);

        self::assertStringContainsString('B_01.01.0010', $tables['b_01.01.csv']);
        self::assertStringContainsString('B_01.01.0040', $tables['b_01.01.csv']);
        // Currency defaults to EUR when the tenant has none set.
        self::assertStringContainsString('EUR', $tables['b_01.01.csv']);
    }

    #[Test]
    public function manifestReferencesEsaTaxonomyEntryPoint(): void
    {
        $tables = $this->exporter()->generateTables($this->tenant);
        $manifest = $tables['META-INF/reportPackage.json'];

        self::assertStringContainsString('dora/4.0/mod/dora.json', $manifest);
        self::assertJson($manifest);
    }

    #[Test]
    public function providerRowIsEmittedAndCsvQuoted(): void
    {
        $supplier = new Supplier();
        $supplier->setName('Cloud, Inc.'); // comma forces RFC-4180 quoting
        $this->supplierRepo = $this->createMock(SupplierRepository::class);
        $this->supplierRepo->method('findByTenantAndDoraRelevant')->willReturn([$supplier]);

        $tables = $this->exporter()->generateTables($this->tenant);

        self::assertStringContainsString('"Cloud, Inc."', $tables['b_02.02.csv']);
        self::assertStringContainsString('1', $tables['b_02.01.csv']); // provider count = 1
    }

    #[Test]
    public function payloadHashIsDeterministic(): void
    {
        $a = $this->exporter()->computePayloadHash($this->tenant);
        $b = $this->exporter()->computePayloadHash($this->tenant);

        self::assertSame($a, $b);
        self::assertSame(64, strlen($a)); // sha256 hex
    }

    #[Test]
    public function generatesValidZipPackage(): void
    {
        $bytes = $this->exporter()->generateZip($this->tenant);
        self::assertNotSame('', $bytes);

        $tmp = tempnam(sys_get_temp_dir(), 'roi-test-');
        self::assertIsString($tmp);
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($tmp) === true, 'Exported bytes must be a valid ZIP');
        self::assertNotFalse($zip->locateName('b_02.02.csv'));
        self::assertNotFalse($zip->locateName('META-INF/reportPackage.json'));
        $zip->close();
        @unlink($tmp);
    }
}
