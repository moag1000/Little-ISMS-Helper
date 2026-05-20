<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\TenantBrandingRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\Export\CertificationBundleExporter;
use App\Service\PdfExportService;
use App\Service\PolicyWizard\Export\PolicyPdfExporter;
use App\Exception\InvalidArgument\InvalidArgumentException as AppInvalidArgumentException;
use App\Service\SoAReportService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Bundle\SecurityBundle\Security;
use ZipArchive;

/**
 * CertificationBundleExporter — Konzern (multi-tenant) export tests.
 *
 * Covers task #129 (Konzern-Cert-Bundle):
 *  - exportKonzern() throws not_a_holding when the tenant has no subsidiaries
 *  - single-subsidiary roll-up produces holding root + 1 child folder
 *  - multi-subsidiary roll-up aggregates every subsidiary's bundle and
 *    prepends tenant_* columns to the aggregated INDEX.csv
 *  - 00_KONZERN_OVERVIEW.csv carries one row per included tenant with
 *    per-framework coverage_pct columns
 */
#[AllowMockObjectsWithoutExpectations]
final class CertificationBundleExporterKonzernTest extends TestCase
{
    private function makeTenant(int $id, string $name, string $code): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($name);
        $tenant->setCode($code);
        $ref = new ReflectionProperty(Tenant::class, 'id');
        $ref->setValue($tenant, $id);
        return $tenant;
    }

    private function makeWizardDocument(Tenant $tenant, string $standard, string $topic, int $id): Document
    {
        $template = new PolicyTemplate();
        $template->setKey($standard . '.' . $topic);
        $template->setStandard($standard);
        $template->setTopic($topic);
        $template->setVersion(1);

        $doc = new Document();
        $doc->setTenant($tenant);
        $doc->setFilename('policy-' . $topic . '.md');
        $doc->setOriginalFilename(ucfirst(str_replace('_', ' ', $topic)));
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(100);
        $doc->setFilePath('virtual:policy-wizard/' . $standard . '/' . $topic);
        $doc->setCategory('policy');
        $doc->setStatus('approved');
        $doc->setIsArchived(false);
        $doc->setUploadedAt(new DateTimeImmutable('2026-05-01 09:00:00'));
        $doc->setSha256Hash(hash('sha256', $standard . ':' . $topic));
        $doc->setGeneratedFromTemplate($template);

        $ref = new ReflectionProperty(Document::class, 'id');
        $ref->setValue($doc, $id);
        return $doc;
    }

    /**
     * Build an exporter fixture wired to return per-tenant document maps.
     *
     * @param array<int, list<Document>> $documentsPerTenantId Tenant.id → its documents
     */
    private function makeExporter(array $documentsPerTenantId): CertificationBundleExporter
    {
        $documentRepo = $this->createMock(DocumentRepository::class);
        $documentRepo->method('findByTenant')->willReturnCallback(
            static function (Tenant $tenant) use ($documentsPerTenantId): array {
                return $documentsPerTenantId[$tenant->getId() ?? 0] ?? [];
            },
        );

        $framework = new ComplianceFramework();
        $reflFw = new ReflectionProperty(ComplianceFramework::class, 'id');
        $reflFw->setValue($framework, 1);
        $framework->setCode('ISO27001');
        $framework->setName('ISO/IEC 27001:2022');
        $framework->setVersion('2022');

        $frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $frameworkRepo->method('findActiveFrameworks')->willReturn([$framework]);
        $frameworkRepo->method('findOneBy')->willReturnCallback(
            static fn(array $criteria): ?ComplianceFramework => ($criteria['code'] ?? null) === 'ISO27001' ? $framework : null,
        );

        $reqRepo = $this->createMock(ComplianceRequirementRepository::class);
        $reqRepo->method('findByFramework')->willReturn([]);
        $reqRepo->method('getFrameworkStatisticsForTenant')->willReturn([
            'total' => 10,
            'applicable' => 8,
            'fulfilled' => 4,
            'critical_gaps' => 1,
        ]);

        $assetRepo = $this->createMock(AssetRepository::class);
        $assetRepo->method('findByTenant')->willReturn([]);

        $riskRepo = $this->createMock(RiskRepository::class);
        $riskRepo->method('findByTenant')->willReturn([]);

        $controlRepo = $this->createMock(ControlRepository::class);
        $controlRepo->method('getImplementationStats')->willReturn([
            'total' => 0,
            'implemented' => 0,
            'in_progress' => 0,
            'not_applicable' => 0,
        ]);

        $treatmentRepo = $this->createMock(RiskTreatmentPlanRepository::class);
        $treatmentRepo->method('findActiveForTenant')->willReturn([]);

        $pdfExporter = $this->createMock(PolicyPdfExporter::class);
        $pdfExporter->method('exportDocument')->willReturnCallback(
            static fn(Document $doc): string => 'PDFFAKE:' . ($doc->getId() ?? 0),
        );

        return new CertificationBundleExporter(
            $this->createMock(PdfExportService::class),
            $this->createMock(SoAReportService::class),
            $this->createMock(TenantContext::class),
            $this->createMock(Security::class),
            $assetRepo,
            $riskRepo,
            $controlRepo,
            $documentRepo,
            $reqRepo,
            $this->createMock(ComplianceRequirementFulfillmentRepository::class),
            $frameworkRepo,
            $treatmentRepo,
            $pdfExporter,
            null,
            $this->createMock(TenantBrandingRepository::class),
            null,
            $this->createMock(WorkflowInstanceRepository::class),
        );
    }

    /**
     * @return list<string>
     */
    private function listZipEntries(string $path): array
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name)) {
                $entries[] = $name;
            }
        }
        $zip->close();
        return $entries;
    }

    private function readZipEntry(string $path, string $entry): string
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $payload = $zip->getFromName($entry);
        $zip->close();
        return $payload === false ? '' : $payload;
    }

    // ─── Tests ─────────────────────────────────────────────────────────

    #[Test]
    public function testNotAHoldingThrows(): void
    {
        $standalone = $this->makeTenant(1, 'Standalone', 'standalone');
        $exporter = $this->makeExporter([1 => []]);

        $this->expectException(AppInvalidArgumentException::class);
        $this->expectExceptionMessage('not_a_holding');
        $exporter->exportKonzern($standalone, ['ISO27001']);
    }

    #[Test]
    public function testSingleSubsidiaryRollupProducesHoldingPlusChildFolder(): void
    {
        $holding = $this->makeTenant(10, 'Holding AG', 'holding-ag');
        $sub = $this->makeTenant(20, 'Sub Mueller GmbH', 'sub-mueller');
        $holding->addSubsidiary($sub);

        $exporter = $this->makeExporter([
            10 => [],
            20 => [$this->makeWizardDocument($sub, 'iso27001', 'access_control', 100)],
        ]);

        $result = $exporter->exportKonzern($holding, ['ISO27001']);

        self::assertArrayHasKey('path', $result);
        self::assertSame(2, $result['subsidiary_count'], 'Holding root + 1 sub = 2 included tenants');
        self::assertContains(10, $result['included_subsidiary_ids']);
        self::assertContains(20, $result['included_subsidiary_ids']);
        self::assertNotEmpty($result['sha256']);
        self::assertSame(1, $result['document_count']);

        $entries = $this->listZipEntries($result['path']);

        // Root-level holding artifacts.
        $hasOverview = false;
        $hasIndex = false;
        $hasRaci = false;
        $hasMetadata = false;
        $hasHoldingFolder = false;
        $hasSubFolder = false;
        foreach ($entries as $e) {
            if (str_contains($e, '/00_KONZERN_OVERVIEW.csv')) {
                $hasOverview = true;
            }
            if (str_ends_with($e, '/INDEX.csv') && substr_count($e, '/') === 1) {
                $hasIndex = true;
            }
            if (str_contains($e, '/00_KONZERN_RACI.md')) {
                $hasRaci = true;
            }
            if (str_contains($e, '/METADATA.json') && substr_count($e, '/') === 1) {
                $hasMetadata = true;
            }
            if (str_contains($e, '/holding-ag/')) {
                $hasHoldingFolder = true;
            }
            if (str_contains($e, '/sub-mueller/')) {
                $hasSubFolder = true;
            }
        }
        self::assertTrue($hasOverview, '00_KONZERN_OVERVIEW.csv missing');
        self::assertTrue($hasIndex, 'aggregated INDEX.csv missing at root');
        self::assertTrue($hasRaci, '00_KONZERN_RACI.md missing');
        self::assertTrue($hasMetadata, 'METADATA.json missing at root');
        self::assertTrue($hasHoldingFolder, 'holding sub-folder missing');
        self::assertTrue($hasSubFolder, 'subsidiary sub-folder missing');

        @unlink($result['path']);
    }

    #[Test]
    public function testMultiSubsidiaryAggregatedIndexCarriesTenantColumns(): void
    {
        $holding = $this->makeTenant(10, 'Holding AG', 'holding-ag');
        $subA = $this->makeTenant(20, 'Sub A', 'sub-a');
        $subB = $this->makeTenant(30, 'Sub B', 'sub-b');
        $holding->addSubsidiary($subA);
        $holding->addSubsidiary($subB);

        $exporter = $this->makeExporter([
            10 => [$this->makeWizardDocument($holding, 'iso27001', 'isms_charter', 1)],
            20 => [$this->makeWizardDocument($subA, 'iso27001', 'access_control', 2)],
            30 => [$this->makeWizardDocument($subB, 'iso27001', 'incident_response', 3)],
        ]);

        $result = $exporter->exportKonzern($holding, ['ISO27001']);

        self::assertSame(3, $result['subsidiary_count']);
        self::assertSame(3, $result['document_count']);

        // Pick up the aggregated root INDEX.csv. We don't know the date-based
        // root dir prefix at runtime, so locate it dynamically.
        $rootIndexPath = '';
        foreach ($this->listZipEntries($result['path']) as $entry) {
            if (str_ends_with($entry, '/INDEX.csv') && substr_count($entry, '/') === 1) {
                $rootIndexPath = $entry;
                break;
            }
        }
        self::assertNotSame('', $rootIndexPath, 'root INDEX.csv not located');

        $csv = $this->readZipEntry($result['path'], $rootIndexPath);
        self::assertNotSame('', $csv);

        // Header carries the tenant_* prepended columns.
        self::assertStringContainsString('tenant_id', $csv);
        self::assertStringContainsString('tenant_name', $csv);
        self::assertStringContainsString('tenant_slug', $csv);
        self::assertStringContainsString('approved_by_user_email', $csv);

        // Each subsidiary contributed at least one row identifiable by slug.
        self::assertStringContainsString('sub-a', $csv);
        self::assertStringContainsString('sub-b', $csv);
        self::assertStringContainsString('holding-ag', $csv);

        @unlink($result['path']);
    }

    #[Test]
    public function testKonzernOverviewCsvHasPerFrameworkCoverageColumns(): void
    {
        $holding = $this->makeTenant(10, 'Holding AG', 'holding-ag');
        $subA = $this->makeTenant(20, 'Sub A', 'sub-a');
        $holding->addSubsidiary($subA);

        $exporter = $this->makeExporter([10 => [], 20 => []]);

        $result = $exporter->exportKonzern($holding, ['ISO27001']);

        // Locate the konzern overview entry.
        $overviewPath = '';
        foreach ($this->listZipEntries($result['path']) as $entry) {
            if (str_ends_with($entry, '/00_KONZERN_OVERVIEW.csv')) {
                $overviewPath = $entry;
                break;
            }
        }
        self::assertNotSame('', $overviewPath, '00_KONZERN_OVERVIEW.csv missing');

        $csv = $this->readZipEntry($result['path'], $overviewPath);
        self::assertStringContainsString('coverage_pct_ISO27001', $csv);
        self::assertStringContainsString('policy_count', $csv);
        self::assertStringContainsString('wizard_runs_count', $csv);
        self::assertStringContainsString('holding_raci_role', $csv);

        // Holding row gets role A; subsidiary row gets R.
        self::assertMatchesRegularExpression('/Holding AG.*A\s*\r?\n/', $csv);
        self::assertMatchesRegularExpression('/Sub A.*R\s*\r?\n/', $csv);

        // 4/8 = 50.0
        self::assertStringContainsString('50', $csv, 'expected coverage % from stub stats');

        @unlink($result['path']);
    }
}
