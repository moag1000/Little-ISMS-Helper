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
use App\Service\AuditLogger;
use App\Service\Export\CertificationBundleExporter;
use App\Service\PdfExportService;
use App\Service\PolicyWizard\Export\PolicyPdfExporter;
use App\Service\SoAReportService;
use App\Service\TenantContext;
use App\Entity\User;
use App\Entity\WorkflowInstance;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Bundle\SecurityBundle\Security;
use ZipArchive;

/**
 * CertificationBundleExporter — Policy-Wizard integration tests.
 *
 * Targets the three bugs reported by the user:
 *   1) Wizard-generated Documents missing from the certification ZIP.
 *   2) Archived (replaced) Documents still included.
 *   3) Virtual filePath causing MISSING.txt placeholders.
 *
 * Tests exercise the private methods `addPolicyWizardDocuments()` and
 * `addEvidenceDocuments()` through reflection so we do not have to
 * stand up the full PDF render pipeline (PdfExportService /
 * SoAReportService).
 */
#[AllowMockObjectsWithoutExpectations]
final class CertificationBundleExporterPolicyWizardTest extends TestCase
{
    private function makeTenant(int $id = 7): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getName')->willReturn('TestCo');
        return $stub;
    }

    private function makeWizardDocument(
        Tenant $tenant,
        string $standard,
        string $topic,
        int $id,
        bool $archived = false,
        ?string $status = null,
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
        // Wizard docs use a virtual filePath — no file on disk.
        $doc->setFilePath('virtual:policy-wizard/' . $standard . '/' . $topic);
        $doc->setCategory('policy');
        $doc->setDescription('Body for ' . $topic);
        $doc->setStatus($status ?? ($archived ? 'archived' : 'approved'));
        $doc->setIsArchived($archived);
        $doc->setUploadedAt(new DateTimeImmutable('2026-05-01 09:00:00'));
        $doc->setSha256Hash(hash('sha256', $standard . ':' . $topic));
        $doc->setGeneratedFromTemplate($template);

        $ref = new ReflectionProperty(Document::class, 'id');
        $ref->setValue($doc, $id);
        return $doc;
    }

    private function makeLegacyDocument(
        Tenant $tenant,
        int $id,
        bool $archived = false,
    ): Document {
        $doc = new Document();
        $doc->setTenant($tenant);
        $doc->setFilename('legacy-policy-' . $id . '.pdf');
        $doc->setOriginalFilename('Legacy Policy ' . $id);
        $doc->setMimeType('application/pdf');
        $doc->setFileSize(2048);
        $doc->setFilePath('/non/existent/path/' . $id . '.pdf');
        $doc->setCategory('policy');
        $doc->setStatus($archived ? 'archived' : 'approved');
        $doc->setIsArchived($archived);
        $doc->setUploadedAt(new DateTimeImmutable('2025-12-01'));

        $ref = new ReflectionProperty(Document::class, 'id');
        $ref->setValue($doc, $id);
        return $doc;
    }

    /**
     * @param list<Document> $allDocs                 Returned by DocumentRepository::findByTenant
     * @param array<string, list<Document>> $evidenceByRequirement Map requirementId → evidence Documents
     */
    private function makeExporter(
        array $allDocs,
        array $evidenceByRequirement = [],
        ?PolicyPdfExporter $pdfExporter = null,
        ?WorkflowInstanceRepository $workflowInstanceRepository = null,
        ?ComplianceFrameworkRepository $frameworkRepoOverride = null,
        ?ComplianceRequirementRepository $reqRepoOverride = null,
    ): CertificationBundleExporter {
        $documentRepo = $this->createMock(DocumentRepository::class);
        $documentRepo->method('findByTenant')->willReturn($allDocs);

        $framework = new ComplianceFramework();
        $reflFw = new ReflectionProperty(ComplianceFramework::class, 'id');
        $reflFw->setValue($framework, 1);
        $framework->setCode('ISO27001');
        $framework->setName('ISO/IEC 27001:2022');
        $framework->setVersion('2022');

        $requirements = [];
        foreach ($evidenceByRequirement as $reqId => $docs) {
            $req = new ComplianceRequirement();
            $req->setRequirementId($reqId);
            $req->setTitle('Requirement ' . $reqId);
            $req->setCategory('cat-' . $reqId);

            $coll = new ArrayCollection($docs);
            $reflEv = new ReflectionProperty(ComplianceRequirement::class, 'evidenceDocuments');
            $reflEv->setValue($req, $coll);

            $requirements[] = $req;
        }

        $frameworkRepo = $frameworkRepoOverride ?? $this->createMock(ComplianceFrameworkRepository::class);
        if ($frameworkRepoOverride === null) {
            $frameworkRepo->method('findActiveFrameworks')->willReturn([$framework]);
        }

        $reqRepo = $reqRepoOverride ?? $this->createMock(ComplianceRequirementRepository::class);
        if ($reqRepoOverride === null) {
            $reqRepo->method('findByFramework')->willReturn($requirements);
        }

        $pdfExporter ??= $this->createMock(PolicyPdfExporter::class);
        if ($pdfExporter instanceof \PHPUnit\Framework\MockObject\MockObject) {
            $pdfExporter->method('exportDocument')->willReturnCallback(
                static fn (Document $doc): string => 'PDFFAKE:' . ($doc->getId() ?? 0),
            );
        }

        return new CertificationBundleExporter(
            $this->createMock(PdfExportService::class),
            $this->createMock(SoAReportService::class),
            $this->createMock(TenantContext::class),
            $this->createMock(Security::class),
            $this->createMock(AssetRepository::class),
            $this->createMock(RiskRepository::class),
            $this->createMock(ControlRepository::class),
            $documentRepo,
            $reqRepo,
            $this->createMock(ComplianceRequirementFulfillmentRepository::class),
            $frameworkRepo,
            $this->createMock(RiskTreatmentPlanRepository::class),
            $pdfExporter,
            null,
            $this->createMock(TenantBrandingRepository::class),
            null,
            $workflowInstanceRepository,
        );
    }

    private function makeUser(int $id, string $email): User
    {
        $user = new User();
        $refId = new ReflectionProperty(User::class, 'id');
        $refId->setValue($user, $id);
        $user->setEmail($email);
        return $user;
    }

    private function makeApprovedWorkflow(
        int $documentId,
        User $approver,
        DateTimeImmutable $completedAt,
    ): WorkflowInstance {
        $instance = new WorkflowInstance();
        $instance->setEntityType('Document');
        $instance->setEntityId($documentId);
        $instance->setStatus('approved');
        $instance->setInitiatedBy($approver);
        $instance->setCompletedAt($completedAt);
        return $instance;
    }

    /**
     * @return array{0: ZipArchive, 1: string}
     */
    private function openZip(): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cb-test-');
        self::assertNotFalse($tmp);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        return [$zip, $tmp];
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

    private function callPolicyWizard(CertificationBundleExporter $exporter, ZipArchive $zip, Tenant $tenant): array
    {
        $m = new ReflectionMethod(CertificationBundleExporter::class, 'addPolicyWizardDocuments');
        return $m->invoke($exporter, $zip, 'BUNDLE', $tenant);
    }

    private function callEvidence(CertificationBundleExporter $exporter, ZipArchive $zip, Tenant $tenant): array
    {
        $m = new ReflectionMethod(CertificationBundleExporter::class, 'addEvidenceDocuments');
        return $m->invoke($exporter, $zip, 'BUNDLE', $tenant);
    }

    #[Test]
    public function testWizardDocsAddedToPoliciesDir(): void
    {
        $tenant = $this->makeTenant();
        $documents = [
            $this->makeWizardDocument($tenant, 'iso27001', 'access_control', 1),
            $this->makeWizardDocument($tenant, 'gdpr', 'privacy_policy', 2),
        ];
        $exporter = $this->makeExporter($documents);

        [$zip, $tmp] = $this->openZip();
        $result = $this->callPolicyWizard($exporter, $zip, $tenant);
        $zip->close();

        self::assertSame(2, $result['document_count']);
        $entries = $this->listZipEntries($tmp);
        self::assertContains('BUNDLE/01_POLICIES/iso27001/Access_control.pdf', $entries);
        self::assertContains('BUNDLE/01_POLICIES/gdpr/Privacy_policy.pdf', $entries);
        self::assertContains('BUNDLE/01_POLICIES/INDEX.csv', $entries);
        @unlink($tmp);
    }

    #[Test]
    public function testArchivedDocsSkipped(): void
    {
        $tenant = $this->makeTenant();
        // Bestandsaufnahme replace scenario: legacy doc archived,
        // wizard-generated successor live.
        $documents = [
            $this->makeWizardDocument($tenant, 'iso27001', 'access_control', 1, archived: true),
            $this->makeWizardDocument($tenant, 'iso27001', 'access_control', 2),
        ];
        $exporter = $this->makeExporter($documents);

        [$zip, $tmp] = $this->openZip();
        $result = $this->callPolicyWizard($exporter, $zip, $tenant);
        $zip->close();

        self::assertSame(1, $result['document_count'], 'Archived wizard doc must be skipped.');
        $entries = $this->listZipEntries($tmp);
        // Only one PDF in the policies dir + the INDEX.csv.
        $pdfs = array_filter($entries, static fn (string $e): bool => str_ends_with($e, '.pdf'));
        self::assertCount(1, $pdfs);
        @unlink($tmp);
    }

    #[Test]
    public function testIndexCsvIncludesAllStandards(): void
    {
        $tenant = $this->makeTenant();
        $documents = [
            $this->makeWizardDocument($tenant, 'iso27001', 'access_control', 1),
            $this->makeWizardDocument($tenant, 'bsi', 'isms_concept', 2),
            $this->makeWizardDocument($tenant, 'gdpr', 'privacy_policy', 3),
        ];
        $exporter = $this->makeExporter($documents);

        [$zip, $tmp] = $this->openZip();
        $this->callPolicyWizard($exporter, $zip, $tenant);
        $zip->close();

        $csv = $this->readZipEntry($tmp, 'BUNDLE/01_POLICIES/INDEX.csv');
        self::assertNotSame('', $csv);
        self::assertStringContainsString('iso27001', $csv);
        self::assertStringContainsString('bsi', $csv);
        self::assertStringContainsString('gdpr', $csv);
        self::assertStringContainsString('access_control', $csv);
        self::assertStringContainsString('isms_concept', $csv);
        self::assertStringContainsString('privacy_policy', $csv);
        // Header row present.
        self::assertStringContainsString('drift_flag', $csv);
        @unlink($tmp);
    }

    #[Test]
    public function testEvidenceDocsSkipWizardDuplicates(): void
    {
        $tenant = $this->makeTenant();
        $wizardDoc = $this->makeWizardDocument($tenant, 'iso27001', 'access_control', 1);
        $legacyDoc = $this->makeLegacyDocument($tenant, 99);

        // Both linked as evidence to the same requirement — but the wizard
        // doc must NOT be packed into the evidence dir (it's covered by
        // addPolicyWizardDocuments).
        $exporter = $this->makeExporter(
            [$wizardDoc, $legacyDoc],
            ['A.5.15' => [$wizardDoc, $legacyDoc]],
        );

        [$zip, $tmp] = $this->openZip();
        $result = $this->callEvidence($exporter, $zip, $tenant);
        $zip->close();

        // Only the legacy doc should have been emitted (as a MISSING.txt
        // placeholder because the disk path doesn't exist — that's fine).
        self::assertSame(1, $result['document_count']);
        $entries = $this->listZipEntries($tmp);
        $missingCount = count(array_filter(
            $entries,
            static fn (string $e): bool => str_contains($e, '.MISSING.txt'),
        ));
        self::assertSame(
            1,
            $missingCount,
            'Wizard doc must NOT produce a MISSING.txt; only the legacy doc should.',
        );
        @unlink($tmp);
    }

    #[Test]
    public function testEvidenceLoopSkipsArchivedLegacyDocs(): void
    {
        $tenant = $this->makeTenant();
        $live = $this->makeLegacyDocument($tenant, 50);
        $archived = $this->makeLegacyDocument($tenant, 51, archived: true);

        $exporter = $this->makeExporter(
            [$live, $archived],
            ['A.5.1' => [$live, $archived]],
        );

        [$zip, $tmp] = $this->openZip();
        $result = $this->callEvidence($exporter, $zip, $tenant);
        $zip->close();

        self::assertSame(1, $result['document_count'], 'Archived legacy doc must be filtered out.');
        @unlink($tmp);
    }

    #[Test]
    public function testGracefulSkipOnPdfExportError(): void
    {
        $tenant = $this->makeTenant();
        $documents = [
            $this->makeWizardDocument($tenant, 'iso27001', 'access_control', 1),
            $this->makeWizardDocument($tenant, 'gdpr', 'privacy_policy', 2),
        ];

        // PDF exporter throws on the second doc.
        $pdfExporter = $this->createMock(PolicyPdfExporter::class);
        $pdfExporter->method('exportDocument')->willReturnCallback(
            static function (Document $doc): string {
                if ($doc->getId() === 2) {
                    throw new \RuntimeException('Dompdf blew up');
                }
                return 'PDFFAKE:' . ($doc->getId() ?? 0);
            },
        );

        $exporter = $this->makeExporter($documents, [], $pdfExporter);

        [$zip, $tmp] = $this->openZip();
        $result = $this->callPolicyWizard($exporter, $zip, $tenant);
        $zip->close();

        self::assertSame(1, $result['document_count'], 'Failing doc must be skipped, not abort the whole bundle.');
        $entries = $this->listZipEntries($tmp);
        self::assertContains('BUNDLE/01_POLICIES/iso27001/Access_control.pdf', $entries);
        self::assertNotContains('BUNDLE/01_POLICIES/gdpr/Privacy_policy.pdf', $entries);
        @unlink($tmp);
    }

    // ─── Task #122: INDEX.csv approver enrichment + multi-framework ────

    #[Test]
    public function testWizardIndexCsvHasApproverColumns(): void
    {
        $tenant = $this->makeTenant();
        $documents = [
            $this->makeWizardDocument($tenant, 'iso27001', 'access_control', 1),
        ];
        $exporter = $this->makeExporter($documents);

        [$zip, $tmp] = $this->openZip();
        $this->callPolicyWizard($exporter, $zip, $tenant);
        $zip->close();

        $csv = $this->readZipEntry($tmp, 'BUNDLE/01_POLICIES/INDEX.csv');
        self::assertNotSame('', $csv);
        // Header columns required by Auditor MINOR-NC.
        self::assertStringContainsString('approved_by_user_email', $csv);
        self::assertStringContainsString('approved_by_user_id', $csv);
        self::assertStringContainsString('approved_at', $csv);
        self::assertStringContainsString('wizard_run_id', $csv);
        self::assertStringContainsString('template_version', $csv);
        self::assertStringContainsString('sha256', $csv);
        @unlink($tmp);
    }

    #[Test]
    public function testWizardIndexPopulatesShaAndTemplateVersion(): void
    {
        $tenant = $this->makeTenant();
        $documents = [
            $this->makeWizardDocument($tenant, 'iso27001', 'access_control', 1),
        ];
        $exporter = $this->makeExporter($documents);

        [$zip, $tmp] = $this->openZip();
        $this->callPolicyWizard($exporter, $zip, $tenant);
        $zip->close();

        $csv = $this->readZipEntry($tmp, 'BUNDLE/01_POLICIES/INDEX.csv');
        // sha256 of 'PDFFAKE:1' as produced by the mock pdfExporter.
        $expectedSha = hash('sha256', 'PDFFAKE:1');
        self::assertStringContainsString($expectedSha, $csv);
        // Template version is set to 1 in makeWizardDocument.
        self::assertMatchesRegularExpression('/,1,[^,]+\r?\n?$/m', $csv);
        @unlink($tmp);
    }

    #[Test]
    public function testEvidenceIndexCsvHasApproverColumns(): void
    {
        $tenant = $this->makeTenant();
        $legacy = $this->makeLegacyDocument($tenant, 99);
        $exporter = $this->makeExporter([$legacy], ['A.5.1' => [$legacy]]);

        [$zip, $tmp] = $this->openZip();
        $this->callEvidence($exporter, $zip, $tenant);
        $zip->close();

        $csv = $this->readZipEntry($tmp, 'BUNDLE/04_EVIDENCE/INDEX.csv');
        self::assertNotSame('', $csv);
        self::assertStringContainsString('approved_by_user_email', $csv);
        self::assertStringContainsString('approved_by_user_id', $csv);
        self::assertStringContainsString('approved_at', $csv);
        self::assertStringContainsString('sha256', $csv);
        @unlink($tmp);
    }

    #[Test]
    public function testApproverResolvedFromWorkflowInstance(): void
    {
        $tenant = $this->makeTenant();
        $doc = $this->makeWizardDocument($tenant, 'iso27001', 'access_control', 42);

        $approver = $this->makeUser(7, 'ciso@example.test');
        $instance = $this->makeApprovedWorkflow(42, $approver, new DateTimeImmutable('2026-05-09 10:00:00'));

        $workflowRepo = $this->createMock(WorkflowInstanceRepository::class);
        $workflowRepo->method('findByEntity')->willReturn([$instance]);

        $exporter = $this->makeExporter([$doc], [], null, $workflowRepo);

        [$zip, $tmp] = $this->openZip();
        $this->callPolicyWizard($exporter, $zip, $tenant);
        $zip->close();

        $csv = $this->readZipEntry($tmp, 'BUNDLE/01_POLICIES/INDEX.csv');
        self::assertStringContainsString('ciso@example.test', $csv, 'Approver email must come from WorkflowInstance.');
        self::assertStringContainsString('2026-05-09', $csv, 'approved_at column must be populated.');
        @unlink($tmp);
    }

    #[Test]
    public function testApproverEmptyWhenNoWorkflowAndNoUploader(): void
    {
        $tenant = $this->makeTenant();
        // Wizard doc without uploader, without workflow, status approved.
        $doc = $this->makeWizardDocument($tenant, 'iso27001', 'no_owner', 99);
        $exporter = $this->makeExporter([$doc]);

        [$zip, $tmp] = $this->openZip();
        $this->callPolicyWizard($exporter, $zip, $tenant);
        $zip->close();

        $csv = $this->readZipEntry($tmp, 'BUNDLE/01_POLICIES/INDEX.csv');
        // Should contain the doc row but the approver columns blank.
        self::assertStringContainsString('No owner', $csv);
        // Auditor sees the gap explicitly — three consecutive empty fields
        // (approved_by_user_email, approved_by_user_id, approved_at).
        self::assertMatchesRegularExpression('/,99,.*?,,,/', $csv);
        @unlink($tmp);
    }

    #[Test]
    public function testMultiFrameworkBundleAccumulator(): void
    {
        $tenant = $this->makeTenant();
        $exporter = $this->makeExporter([]);

        $exporter->addFrameworkBundle('DORA');
        $exporter->addFrameworkBundle('NIS2');
        // Duplicate must be ignored.
        $exporter->addFrameworkBundle('DORA');

        // Use reflection to peek at the accumulator before export() consumes it.
        $ref = new ReflectionProperty(CertificationBundleExporter::class, 'frameworkBundles');
        $accumulated = $ref->getValue($exporter);

        self::assertSame(['DORA', 'NIS2'], $accumulated);
    }

    #[Test]
    public function testFrameworkCoverageCsvForUnknownCode(): void
    {
        $tenant = $this->makeTenant();
        $frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $frameworkRepo->method('findOneBy')->willReturn(null);
        $frameworkRepo->method('findActiveFrameworks')->willReturn([]);

        $exporter = $this->makeExporter([], [], null, null, $frameworkRepo);

        $m = new ReflectionMethod(CertificationBundleExporter::class, 'generateFrameworkCoverageCsv');
        $result = $m->invoke($exporter, $tenant, 'TOTALLY_UNKNOWN_FRAMEWORK');

        self::assertIsArray($result);
        self::assertSame(0, $result['row_count']);
        self::assertStringContainsString('unknown_framework', $result['csv']);
    }
}
