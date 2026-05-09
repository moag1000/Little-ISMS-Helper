<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Export;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Repository\AuditLogRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\TenantBrandingRepository;
use App\Repository\WizardRunRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\PolicyWizard\Export\ExportOptions;
use App\Service\PolicyWizard\Export\PolicyPdfExporter;
use App\Service\PolicyWizard\Export\PolicyZipExporter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use ZipArchive;

/**
 * Policy-Wizard W7-A — PolicyZipExporter unit tests.
 *
 * Verifies the auditor audit-pack ZIP layout against:
 *  - per-standard folder grouping (default = all 5 standards)
 *  - the includeStandards filter actually trims the ZIP contents
 *  - evidence/ folder presence when includeEvidence=true (default)
 *  - manifest.json carries SHA-256 hashes per policies/* entry
 *  - README.md lists every emitted policy
 *
 * Uses a fake PolicyPdfExporter that returns deterministic bytes so the
 * SHA-256 assertions stay stable.
 */
#[AllowMockObjectsWithoutExpectations]
final class PolicyZipExporterTest extends TestCase
{
    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 4) . '/templates');
        $this->twig = new TwigEnvironment($loader, ['cache' => false, 'strict_variables' => false]);
    }

    private function makeTenant(int $id = 11): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getLegalName')->willReturn('TestCo GmbH');
        $stub->method('getName')->willReturn('TestCo');
        $stub->method('getCode')->willReturn('TC');
        return $stub;
    }

    private function makeDocument(
        Tenant $tenant,
        string $standard,
        string $topic,
        int $id = 1,
        bool $archived = false,
    ): Document {
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
        $doc->setFilePath('virtual:test');
        $doc->setCategory('policy');
        $doc->setDescription('Body for ' . $topic);
        $doc->setStatus($archived ? 'archived' : 'approved');
        $doc->setIsArchived($archived);
        $doc->setUploadedAt(new DateTimeImmutable('2026-05-01 09:00:00'));
        $doc->setSha256Hash(hash('sha256', $standard . ':' . $topic));
        $doc->setGeneratedFromTemplate($template);

        $ref = new ReflectionProperty(Document::class, 'id');
        $ref->setValue($doc, $id);
        return $doc;
    }

    /**
     * @param list<Document> $documents
     */
    private function makeExporter(array $documents): PolicyZipExporter
    {
        $documentRepo = $this->createMock(DocumentRepository::class);
        $documentRepo->method('findBy')->willReturnCallback(
            static function (array $criteria) use ($documents): array {
                $includeArchived = !array_key_exists('isArchived', $criteria);
                $out = [];
                foreach ($documents as $doc) {
                    if (!$includeArchived && $doc->isArchived()) {
                        continue;
                    }
                    $out[] = $doc;
                }
                return $out;
            },
        );

        // Mock PDF exporter — returns deterministic content for hashing.
        $pdfExporter = $this->createMock(PolicyPdfExporter::class);
        $pdfExporter->method('exportDocument')->willReturnCallback(
            static fn (Document $doc): string => 'PDFFAKE:' . ($doc->getId() ?? 0) . ':' . ($doc->getOriginalFilename() ?? ''),
        );

        return new PolicyZipExporter(
            $pdfExporter,
            $documentRepo,
            $this->twig,
            $this->createMock(TenantBrandingRepository::class),
            $this->createMock(AuditLogRepository::class),
            $this->createMock(ControlRepository::class),
            $this->createMock(PolicyAcknowledgementRepository::class),
            $this->createMock(WorkflowInstanceRepository::class),
            $this->createMock(WizardRunRepository::class),
        );
    }

    /**
     * Open the ZIP from an in-memory string by writing it to a temp
     * file (ZipArchive cannot read php://memory streams).
     *
     * @return list<string>
     */
    private function listEntries(string $binary): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zip-test-');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, $binary);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($tmp) === true);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name)) {
                $entries[] = $name;
            }
        }
        $zip->close();
        @unlink($tmp);
        return $entries;
    }

    private function readEntry(string $binary, string $name): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zip-test-');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, $binary);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($tmp) === true);
        $payload = $zip->getFromName($name);
        $zip->close();
        @unlink($tmp);
        return $payload === false ? '' : $payload;
    }

    #[Test]
    public function testZipContainsAllStandardsByDefault(): void
    {
        $tenant = $this->makeTenant();
        $documents = [
            $this->makeDocument($tenant, 'iso27001', 'access_control', 1),
            $this->makeDocument($tenant, 'bsi', 'isms_concept', 2),
            $this->makeDocument($tenant, 'dora', 'ict_risk_management_framework', 3),
            $this->makeDocument($tenant, 'bcm', 'bcms_top_level', 4),
            $this->makeDocument($tenant, 'gdpr', 'privacy_policy', 5),
        ];
        $exporter = $this->makeExporter($documents);

        $zip = $exporter->exportTenantPolicySet($tenant, new ExportOptions());

        $entries = $this->listEntries($zip);
        self::assertContains('policies/iso27001/access_control.pdf', $entries);
        self::assertContains('policies/bsi/isms_concept.pdf', $entries);
        self::assertContains('policies/dora/ict_risk_management_framework.pdf', $entries);
        self::assertContains('policies/bcm/bcms_top_level.pdf', $entries);
        self::assertContains('policies/gdpr/privacy_policy.pdf', $entries);
        self::assertContains('README.md', $entries);
        self::assertContains('manifest.json', $entries);
    }

    #[Test]
    public function testZipFiltersByStandards(): void
    {
        $tenant = $this->makeTenant();
        $documents = [
            $this->makeDocument($tenant, 'iso27001', 'access_control', 1),
            $this->makeDocument($tenant, 'bsi', 'isms_concept', 2),
            $this->makeDocument($tenant, 'dora', 'ict_risk_management_framework', 3),
            $this->makeDocument($tenant, 'gdpr', 'privacy_policy', 4),
        ];
        $exporter = $this->makeExporter($documents);

        $zip = $exporter->exportTenantPolicySet(
            $tenant,
            new ExportOptions(includeStandards: ['iso27001', 'gdpr']),
        );

        $entries = $this->listEntries($zip);
        self::assertContains('policies/iso27001/access_control.pdf', $entries);
        self::assertContains('policies/gdpr/privacy_policy.pdf', $entries);
        self::assertNotContains('policies/bsi/isms_concept.pdf', $entries);
        self::assertNotContains('policies/dora/ict_risk_management_framework.pdf', $entries);
    }

    #[Test]
    public function testZipIncludesEvidenceWhenRequested(): void
    {
        $tenant = $this->makeTenant();
        $documents = [
            $this->makeDocument($tenant, 'iso27001', 'access_control', 1),
        ];
        $exporter = $this->makeExporter($documents);

        $zipWithEvidence = $exporter->exportTenantPolicySet(
            $tenant,
            new ExportOptions(includeEvidence: true),
        );
        $entries = $this->listEntries($zipWithEvidence);
        self::assertContains('evidence/audit-trail.csv', $entries);
        self::assertContains('evidence/soa.csv', $entries);
        self::assertContains('evidence/acknowledgements.csv', $entries);
        self::assertContains('evidence/workflow-instances.csv', $entries);

        $zipWithoutEvidence = $exporter->exportTenantPolicySet(
            $tenant,
            new ExportOptions(includeEvidence: false),
        );
        $entries = $this->listEntries($zipWithoutEvidence);
        self::assertNotContains('evidence/audit-trail.csv', $entries);
        self::assertNotContains('evidence/soa.csv', $entries);
        self::assertNotContains('evidence/acknowledgements.csv', $entries);
        self::assertNotContains('evidence/workflow-instances.csv', $entries);
    }

    #[Test]
    public function testZipManifestContainsHashes(): void
    {
        $tenant = $this->makeTenant();
        $documents = [
            $this->makeDocument($tenant, 'iso27001', 'access_control', 1),
            $this->makeDocument($tenant, 'bsi', 'isms_concept', 2),
        ];
        $exporter = $this->makeExporter($documents);

        $zip = $exporter->exportTenantPolicySet($tenant, new ExportOptions());
        $manifest = json_decode($this->readEntry($zip, 'manifest.json'), true);

        self::assertIsArray($manifest);
        self::assertArrayHasKey('documents', $manifest);
        self::assertCount(2, $manifest['documents']);
        foreach ($manifest['documents'] as $entry) {
            self::assertArrayHasKey('sha256', $entry);
            self::assertSame(64, strlen($entry['sha256']), 'Hash should be SHA-256 hex (64 chars).');
            self::assertArrayHasKey('path', $entry);
            self::assertArrayHasKey('bytes', $entry);
            self::assertGreaterThan(0, $entry['bytes']);
        }
    }

    #[Test]
    public function testZipReadmeListsAllPolicies(): void
    {
        $tenant = $this->makeTenant();
        $documents = [
            $this->makeDocument($tenant, 'iso27001', 'access_control', 1),
            $this->makeDocument($tenant, 'gdpr', 'privacy_policy', 2),
        ];
        $exporter = $this->makeExporter($documents);

        $zip = $exporter->exportTenantPolicySet($tenant, new ExportOptions());
        $readme = $this->readEntry($zip, 'README.md');

        self::assertNotSame('', $readme);
        self::assertStringContainsString('TestCo GmbH', $readme);
        self::assertStringContainsString('access_control', $readme);
        self::assertStringContainsString('privacy_policy', $readme);
        self::assertStringContainsString('manifest.json', $readme);
    }
}
