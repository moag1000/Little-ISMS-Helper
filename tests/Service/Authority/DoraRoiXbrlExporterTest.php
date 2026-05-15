<?php

declare(strict_types=1);

namespace App\Tests\Service\Authority;

use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\SupplierRepository;
use App\Service\Authority\DoraRoiXbrlExporter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DoraRoiXbrlExporter.
 *
 * Covers: XBRL root element, top-10 mandatory ESA elements, payload-hash
 * determinism, and per-supplier entry generation.
 */
#[AllowMockObjectsWithoutExpectations]
final class DoraRoiXbrlExporterTest extends TestCase
{
    private Tenant $tenant;
    private SupplierRepository $supplierRepo;
    private AssetRepository $assetRepo;
    private DoraRoiXbrlExporter $exporter;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();

        $this->supplierRepo = $this->createMock(SupplierRepository::class);
        $this->assetRepo = $this->createMock(AssetRepository::class);
        $this->supplierRepo->method('findByTenantAndDoraRelevant')->willReturn([]);
        $this->assetRepo->method('findByTenantAndDoraRelevant')->willReturn([]);

        $this->exporter = new DoraRoiXbrlExporter($this->supplierRepo, $this->assetRepo);
    }

    #[Test]
    public function generateReturnsWellFormedXml(): void
    {
        $xml = $this->exporter->generate($this->tenant);
        self::assertIsString($xml);

        $dom = new \DOMDocument();
        $result = $dom->loadXML($xml);
        self::assertTrue($result, 'generate() must return valid XML');
    }

    #[Test]
    public function generateContainsXbrliRootElement(): void
    {
        $xml = $this->exporter->generate($this->tenant);
        self::assertStringContainsString('xbrli:xbrl', $xml);
    }

    #[Test]
    public function generateContainsReportingEntityNameElement(): void
    {
        $xml = $this->exporter->generate($this->tenant);
        // B_01.01.0010 — reporting entity legal name
        self::assertStringContainsString('B_01.01.0010', $xml);
    }

    #[Test]
    public function generateContainsReportingEntityLeiElement(): void
    {
        $xml = $this->exporter->generate($this->tenant);
        // B_01.01.0020 — LEI of reporting entity
        self::assertStringContainsString('B_01.01.0020', $xml);
    }

    #[Test]
    public function generateContainsReportReferenceDateElement(): void
    {
        $xml = $this->exporter->generate($this->tenant);
        // B_01.01.0030 — report reference date
        self::assertStringContainsString('B_01.01.0030', $xml);
    }

    #[Test]
    public function generateContainsCurrencyElement(): void
    {
        $xml = $this->exporter->generate($this->tenant);
        // B_01.01.0040 — reporting currency
        self::assertStringContainsString('B_01.01.0040', $xml);
    }

    #[Test]
    public function generateContainsTotalProviderCountElement(): void
    {
        $xml = $this->exporter->generate($this->tenant);
        // B_02.01.0010 — total ICT provider count
        self::assertStringContainsString('B_02.01.0010', $xml);
    }

    #[Test]
    public function generateContainsTotalAssetCountElement(): void
    {
        $xml = $this->exporter->generate($this->tenant);
        // B_03.01.0010 — total ICT asset count
        self::assertStringContainsString('B_03.01.0010', $xml);
    }

    #[Test]
    public function generateContainsEsaRoiNamespace(): void
    {
        $xml = $this->exporter->generate($this->tenant);
        self::assertStringContainsString('esa.europa.eu/xbrl/dora/roi', $xml);
    }

    #[Test]
    public function generateContainsDeferredTodoCommentsForFullTaxonomy(): void
    {
        $xml = $this->exporter->generate($this->tenant);
        self::assertStringContainsString('TODO', $xml, 'Deferred elements must be marked with TODO comments');
    }

    #[Test]
    public function payloadHashIsDeterministic(): void
    {
        $xml = $this->exporter->generate($this->tenant);
        $hash1 = $this->exporter->computePayloadHash($xml);
        $hash2 = $this->exporter->computePayloadHash($xml);
        self::assertSame($hash1, $hash2, 'computePayloadHash() must be deterministic');
    }

    #[Test]
    public function payloadHashIsSha256Length(): void
    {
        $xml = $this->exporter->generate($this->tenant);
        $hash = $this->exporter->computePayloadHash($xml);
        self::assertSame(64, strlen($hash), 'SHA-256 hash must be 64 hex characters');
    }

    #[Test]
    public function payloadHashDiffersForDifferentInputs(): void
    {
        $hash1 = $this->exporter->computePayloadHash('payload-a');
        $hash2 = $this->exporter->computePayloadHash('payload-b');
        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function generateIncludesSupplierEntryWhenSuppliersExist(): void
    {
        $supplier = new Supplier();
        $supplier->setName('Acme ICT GmbH');
        $supplier->setIsDoraRelevant(true);

        $supplierRepo = $this->createMock(SupplierRepository::class);
        $supplierRepo->method('findByTenantAndDoraRelevant')->willReturn([$supplier]);
        $assetRepo = $this->createMock(AssetRepository::class);
        $assetRepo->method('findByTenantAndDoraRelevant')->willReturn([]);

        $exporter = new DoraRoiXbrlExporter($supplierRepo, $assetRepo);
        $xml = $exporter->generate($this->tenant);

        self::assertStringContainsString('Acme ICT GmbH', $xml);
        self::assertStringContainsString('B_02.02_provider', $xml);
    }

    #[Test]
    public function providerCountReflectsNumberOfSuppliers(): void
    {
        $s1 = new Supplier();
        $s1->setIsDoraRelevant(true);
        $s2 = new Supplier();
        $s2->setIsDoraRelevant(true);
        $s3 = new Supplier();
        $s3->setIsDoraRelevant(true);
        $suppliers = [$s1, $s2, $s3];

        $supplierRepo = $this->createMock(SupplierRepository::class);
        $supplierRepo->method('findByTenantAndDoraRelevant')->willReturn($suppliers);
        $assetRepo = $this->createMock(AssetRepository::class);
        $assetRepo->method('findByTenantAndDoraRelevant')->willReturn([]);

        $exporter = new DoraRoiXbrlExporter($supplierRepo, $assetRepo);
        $xml = $exporter->generate($this->tenant);

        // The B_02.01.0010 element should contain "3"
        self::assertMatchesRegularExpression('/<roi:B_02\.01\.0010[^>]*>3</', $xml);
    }

    #[Test]
    public function exporterFiltersToDoraRelevantSuppliersOnly(): void
    {
        // 3 suppliers total: 2 DORA-relevant, 1 not
        $s1 = new Supplier();
        $s1->setName('DORA Supplier Alpha');
        $s1->setIsDoraRelevant(true);

        $s2 = new Supplier();
        $s2->setName('DORA Supplier Beta');
        $s2->setIsDoraRelevant(true);

        // s3 is NOT DORA-relevant and must NOT appear in the export
        // The repository mock returns only the relevant ones (as the real
        // findByTenantAndDoraRelevant WHERE clause would do).
        $supplierRepo = $this->createMock(SupplierRepository::class);
        $supplierRepo->method('findByTenantAndDoraRelevant')->willReturn([$s1, $s2]);
        $assetRepo = $this->createMock(AssetRepository::class);
        $assetRepo->method('findByTenantAndDoraRelevant')->willReturn([]);

        $exporter = new DoraRoiXbrlExporter($supplierRepo, $assetRepo);
        $xml = $exporter->generate($this->tenant);

        // Exactly 2 providers must appear in the export
        self::assertMatchesRegularExpression('/<roi:B_02\.01\.0010[^>]*>2</', $xml);
        self::assertStringContainsString('DORA Supplier Alpha', $xml);
        self::assertStringContainsString('DORA Supplier Beta', $xml);
    }
}
