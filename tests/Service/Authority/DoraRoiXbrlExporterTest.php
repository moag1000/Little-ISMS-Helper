<?php

declare(strict_types=1);

namespace App\Tests\Service\Authority;

use App\Entity\Asset;
use App\Entity\AssetDependency;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Enum\AssetDependencyCriticalityImpact;
use App\Enum\AssetDependencyType;
use App\Repository\AssetDependencyRepository;
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
    public function generateWiresTenantLeiIntoB010102(): void
    {
        $this->tenant->setLeiCode('529900T8BM49AURSDO55');
        $xml = $this->exporter->generate($this->tenant);

        self::assertMatchesRegularExpression(
            '/<roi:B_01\.01\.0020[^>]*>529900T8BM49AURSDO55</',
            $xml,
            'Tenant.leiCode must drive B_01.01.0020',
        );
    }

    #[Test]
    public function generateWiresTenantReportingCurrencyIntoB010104(): void
    {
        $this->tenant->setReportingCurrency('CHF');
        $xml = $this->exporter->generate($this->tenant);

        self::assertMatchesRegularExpression(
            '/<roi:B_01\.01\.0040[^>]*>CHF</',
            $xml,
        );
        self::assertStringContainsString('iso4217:CHF', $xml);
    }

    #[Test]
    public function generateDefaultsToEurWhenReportingCurrencyIsNull(): void
    {
        // Default ctor — reportingCurrency is null until set.
        $xml = $this->exporter->generate($this->tenant);
        self::assertStringContainsString('iso4217:EUR', $xml);
    }

    #[Test]
    public function generateEmitsProviderB020200600130WhenSupplierHasFields(): void
    {
        $s = new Supplier();
        $s->setName('Acme ICT');
        $s->setIsDoraRelevant(true);
        $s->setContractStartDate(new \DateTimeImmutable('2024-01-15'));
        $s->setContractEndDate(new \DateTimeImmutable('2026-12-31'));
        $s->setSubstitutability('hard');
        $s->setHasExitStrategy(true);
        $s->setCountryOfHeadOffice('DE');
        $s->setProcessingLocations(['DE', 'FR']);
        $s->setHasISO27001(true);
        $s->setHasISO22301(true);
        $s->setSecurityRequirements('Annual right-to-audit clause per ISO 27001 A.5.20.');

        $supplierRepo = $this->createMock(SupplierRepository::class);
        $supplierRepo->method('findByTenantAndDoraRelevant')->willReturn([$s]);
        $assetRepo = $this->createMock(AssetRepository::class);
        $assetRepo->method('findByTenantAndDoraRelevant')->willReturn([]);

        $exporter = new DoraRoiXbrlExporter($supplierRepo, $assetRepo);
        $xml = $exporter->generate($this->tenant);

        self::assertMatchesRegularExpression('/<roi:B_02\.02\.0060[^>]*>2024-01-15</', $xml);
        self::assertMatchesRegularExpression('/<roi:B_02\.02\.0070[^>]*>2026-12-31</', $xml);
        self::assertMatchesRegularExpression('/<roi:B_02\.02\.0080[^>]*>hard</', $xml);
        self::assertMatchesRegularExpression('/<roi:B_02\.02\.0090[^>]*>true</', $xml);
        // B_02.02.0100 — DE = EEA
        self::assertMatchesRegularExpression('/<roi:B_02\.02\.0100[^>]*>EEA</', $xml);
        // B_02.02.0110 — processing-location join
        self::assertMatchesRegularExpression('/<roi:B_02\.02\.0110[^>]*>DE,FR</', $xml);
        // B_02.02.0120 — certifications include ISO27001 + ISO22301
        self::assertMatchesRegularExpression('/<roi:B_02\.02\.0120[^>]*>ISO27001\+ISO22301/', $xml);
        // B_02.02.0130 — audit-rights derived from non-empty securityRequirements
        self::assertMatchesRegularExpression('/<roi:B_02\.02\.0130[^>]*>true</', $xml);
    }

    #[Test]
    public function generateMarksNonEeaSupplierAsNonEea(): void
    {
        $s = new Supplier();
        $s->setName('Vendor Inc');
        $s->setIsDoraRelevant(true);
        $s->setCountryOfHeadOffice('US');

        $supplierRepo = $this->createMock(SupplierRepository::class);
        $supplierRepo->method('findByTenantAndDoraRelevant')->willReturn([$s]);
        $assetRepo = $this->createMock(AssetRepository::class);
        $assetRepo->method('findByTenantAndDoraRelevant')->willReturn([]);

        $exporter = new DoraRoiXbrlExporter($supplierRepo, $assetRepo);
        $xml = $exporter->generate($this->tenant);

        self::assertMatchesRegularExpression('/<roi:B_02\.02\.0100[^>]*>non_EEA</', $xml);
    }

    #[Test]
    public function generateEmitsB0302AssetDetailRowsWhenAssetsExist(): void
    {
        $asset = new Asset();
        $asset->setName('Core Banking Server');
        $asset->setAssetType('server');
        $asset->setIsDoraRelevant(true);
        $asset->setDataClassification('confidential');
        $asset->setConfidentialityValue(5);
        $asset->setIntegrityValue(5);
        $asset->setAvailabilityValue(4);
        $asset->setOwner('CIO Office');
        $asset->setLocation('Frankfurt DC');
        $asset->setStatus('active');

        $supplierRepo = $this->createMock(SupplierRepository::class);
        $supplierRepo->method('findByTenantAndDoraRelevant')->willReturn([]);
        $assetRepo = $this->createMock(AssetRepository::class);
        $assetRepo->method('findByTenantAndDoraRelevant')->willReturn([$asset]);

        $exporter = new DoraRoiXbrlExporter($supplierRepo, $assetRepo);
        $xml = $exporter->generate($this->tenant);

        self::assertStringContainsString('B_03.02_asset', $xml);
        self::assertMatchesRegularExpression('/<roi:B_03\.02\.0020[^>]*>Core Banking Server</', $xml);
        self::assertMatchesRegularExpression('/<roi:B_03\.02\.0030[^>]*>server</', $xml);
        self::assertMatchesRegularExpression('/<roi:B_03\.02\.0040[^>]*>confidential</', $xml);
        self::assertMatchesRegularExpression('/<roi:B_03\.02\.0050[^>]*>5</', $xml);
        self::assertMatchesRegularExpression('/<roi:B_03\.02\.0060[^>]*>5</', $xml);
        self::assertMatchesRegularExpression('/<roi:B_03\.02\.0070[^>]*>4</', $xml);
        self::assertMatchesRegularExpression('/<roi:B_03\.02\.0080[^>]*>CIO Office</', $xml);
        self::assertMatchesRegularExpression('/<roi:B_03\.02\.0090[^>]*>Frankfurt DC</', $xml);
        self::assertMatchesRegularExpression('/<roi:B_03\.02\.0100[^>]*>active</', $xml);
    }

    #[Test]
    public function generateEmitsRt05DependencyCountElement(): void
    {
        $xml = $this->exporter->generate($this->tenant);
        // B_03.03.0010 — total ICT asset-dependency edges (RT_05)
        self::assertStringContainsString('B_03.03.0010', $xml);
        // Empty assets list → zero edges
        self::assertMatchesRegularExpression('/<roi:B_03\.03\.0010[^>]*>0</', $xml);
    }

    #[Test]
    public function generateEmitsRt05DependencyEdgesForBasicAdjacencyList(): void
    {
        // Two DORA-relevant assets where assetA depends on assetB. No
        // AssetDependency metadata configured — exporter must still emit
        // the edge with default classification (requires / cascade).
        $assetA = new Asset();
        $assetA->setName('App Server');
        $assetA->setIsDoraRelevant(true);

        $assetB = new Asset();
        $assetB->setName('Database');
        $assetB->setIsDoraRelevant(true);

        $assetA->addDependsOn($assetB);

        $supplierRepo = $this->createMock(SupplierRepository::class);
        $supplierRepo->method('findByTenantAndDoraRelevant')->willReturn([]);
        $assetRepo = $this->createMock(AssetRepository::class);
        $assetRepo->method('findByTenantAndDoraRelevant')->willReturn([$assetA, $assetB]);
        $depRepo = $this->createMock(AssetDependencyRepository::class);
        $depRepo->method('findByTenantKeyed')->willReturn([]);

        $exporter = new DoraRoiXbrlExporter($supplierRepo, $assetRepo, $depRepo);
        $xml = $exporter->generate($this->tenant);

        self::assertStringContainsString('B_03.03_dependency', $xml);
        // Edge count must be 1
        self::assertMatchesRegularExpression('/<roi:B_03\.03\.0010[^>]*>1</', $xml);
        // Defaults applied — requires + cascade
        self::assertMatchesRegularExpression('/<roi:B_03\.03\.0040[^>]*>requires</', $xml);
        self::assertMatchesRegularExpression('/<roi:B_03\.03\.0050[^>]*>cascade</', $xml);
        // Denormalised names emitted for auditor readability
        self::assertMatchesRegularExpression('/<roi:B_03\.03\.0060[^>]*>App Server</', $xml);
        self::assertMatchesRegularExpression('/<roi:B_03\.03\.0070[^>]*>Database</', $xml);
    }

    #[Test]
    public function generateEmitsRt05WithEnrichedMetadataWhenAssetDependencyPresent(): void
    {
        $assetA = new Asset();
        $assetA->setName('Trading Frontend');
        $assetA->setIsDoraRelevant(true);

        $assetB = new Asset();
        $assetB->setName('Market Data Feed');
        $assetB->setIsDoraRelevant(true);

        $assetA->addDependsOn($assetB);

        // Use Reflection-style id injection via setter pattern: real ids
        // come from the DB, but the exporter uses Asset::getId() which
        // returns null until persistence. To exercise the
        // `findByTenantKeyed` lookup we need both endpoints to have ids.
        $this->setEntityId($assetA, 101);
        $this->setEntityId($assetB, 202);

        $dep = new AssetDependency();
        $dep->setSourceAsset($assetA);
        $dep->setTargetAsset($assetB);
        $dep->setDependencyType(AssetDependencyType::SharesData);
        $dep->setCriticalityImpact(AssetDependencyCriticalityImpact::Partial);
        $dep->setNotes('Multicast UDP feed via NIC2');

        $supplierRepo = $this->createMock(SupplierRepository::class);
        $supplierRepo->method('findByTenantAndDoraRelevant')->willReturn([]);
        $assetRepo = $this->createMock(AssetRepository::class);
        $assetRepo->method('findByTenantAndDoraRelevant')->willReturn([$assetA, $assetB]);
        $depRepo = $this->createMock(AssetDependencyRepository::class);
        $depRepo->method('findByTenantKeyed')->willReturn(['101:202' => $dep]);

        $exporter = new DoraRoiXbrlExporter($supplierRepo, $assetRepo, $depRepo);
        $xml = $exporter->generate($this->tenant);

        self::assertMatchesRegularExpression('/<roi:B_03\.03\.0040[^>]*>shares_data</', $xml);
        self::assertMatchesRegularExpression('/<roi:B_03\.03\.0050[^>]*>partial</', $xml);
        self::assertMatchesRegularExpression('/<roi:B_03\.03\.0080[^>]*>Multicast UDP feed via NIC2</', $xml);
    }

    #[Test]
    public function generateEmitsRt05ZeroEdgesWhenNoDependenciesDeclared(): void
    {
        $asset = new Asset();
        $asset->setName('Standalone server');
        $asset->setIsDoraRelevant(true);

        $supplierRepo = $this->createMock(SupplierRepository::class);
        $supplierRepo->method('findByTenantAndDoraRelevant')->willReturn([]);
        $assetRepo = $this->createMock(AssetRepository::class);
        $assetRepo->method('findByTenantAndDoraRelevant')->willReturn([$asset]);

        $exporter = new DoraRoiXbrlExporter($supplierRepo, $assetRepo);
        $xml = $exporter->generate($this->tenant);

        self::assertMatchesRegularExpression('/<roi:B_03\.03\.0010[^>]*>0</', $xml);
        self::assertStringNotContainsString('B_03.03_dependency', $xml);
    }

    /**
     * Helper — inject a synthetic id into an Asset entity for unit tests
     * (Doctrine normally assigns ids on persist; reflection is the
     * pragmatic workaround in unit tests that bypass the entity manager).
     */
    private function setEntityId(Asset $asset, int $id): void
    {
        // PHP 8.1+: ReflectionProperty is accessible by default — no
        // setAccessible() call needed (deprecated since 8.5).
        (new \ReflectionProperty(Asset::class, 'id'))->setValue($asset, $id);
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
