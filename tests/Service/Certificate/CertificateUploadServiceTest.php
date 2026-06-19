<?php

declare(strict_types=1);

namespace App\Tests\Service\Certificate;

use App\Entity\ComplianceCertificate;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\Certificate\CertificateUploadService;
use App\Service\FileUploadSecurityService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Unit test for {@see CertificateUploadService}.
 *
 * The file upload is exercised against a real temp file (so move() + sha256 run
 * for real against an isolated temp upload dir); FileUploadSecurityService,
 * EntityManagerInterface and AuditLogger are mocked.
 */
#[AllowMockObjectsWithoutExpectations]
class CertificateUploadServiceTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir() . '/cert_upload_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        // Clean up the isolated upload dir.
        if (is_dir($this->uploadDir . '/public/uploads/documents')) {
            foreach (glob($this->uploadDir . '/public/uploads/documents/*') ?: [] as $f) {
                @unlink($f);
            }
        }
    }

    private function makeUploadedFile(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cert_src_');
        file_put_contents($tmp, '%PDF-1.4 dummy certificate content');

        return new UploadedFile(
            $tmp,
            'my-cert.pdf',
            'application/pdf',
            null,
            true, // test mode → bypasses is_uploaded_file()
        );
    }

    #[Test]
    public function createFromUploadValidatesFileAndMapsFields(): void
    {
        $file = $this->makeUploadedFile();

        $fileSecurity = $this->createMock(FileUploadSecurityService::class);
        // ASSERT: the file is validated exactly once with the uploaded file.
        $fileSecurity->expects(self::once())
            ->method('validateUploadedFile')
            ->with($file);
        $fileSecurity->method('generateSafeFilename')
            ->willReturn('safe-cert-name.pdf');

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($e) use (&$persisted): void {
            $persisted[] = $e;
        });
        $em->expects(self::once())->method('flush');

        $auditLogger = $this->createMock(AuditLogger::class);
        // ASSERT: a single audit entry is written.
        $auditLogger->expects(self::once())->method('log');

        $service = new CertificateUploadService(
            $fileSecurity,
            $em,
            $auditLogger,
            $this->uploadDir,
        );

        $tenant = $this->createMock(Tenant::class);
        $user = $this->createMock(User::class);

        $fields = [
            'frameworkCode' => 'iso27001',
            'certBody' => 'Acme CB',
            'certNumber' => 'CERT-9000',
            'scopeText' => 'Global ISMS scope',
            'scopeTags' => ['cloud', 'de'],
            'certClass' => 'TypeII',
            'issueDate' => new \DateTimeImmutable('2025-01-01'),
            'validUntil' => new \DateTimeImmutable('2028-01-01'),
            'holder' => 'Acme Corp',
        ];

        $cert = $service->createFromUpload($file, $fields, $tenant, $user);

        self::assertInstanceOf(ComplianceCertificate::class, $cert);
        self::assertSame('iso27001', $cert->getFrameworkCode());
        self::assertSame('Acme CB', $cert->getCertBody());
        self::assertSame('CERT-9000', $cert->getCertNumber());
        self::assertSame('Global ISMS scope', $cert->getScopeText());
        self::assertSame(['cloud', 'de'], $cert->getScopeTags());
        self::assertSame('TypeII', $cert->getCertClass());
        self::assertSame('2025-01-01', $cert->getIssueDate()?->format('Y-m-d'));
        self::assertSame('2028-01-01', $cert->getValidUntil()?->format('Y-m-d'));
        self::assertSame('Acme Corp', $cert->getHolder());
        self::assertSame($tenant, $cert->getTenant());
        self::assertSame($user, $cert->getUploadedBy());
        self::assertSame('manual', $cert->getExtractionSource());
        self::assertSame('active', $cert->getStatus());

        // Document was created + linked with sha256 + proper metadata.
        $doc = $cert->getCertificateDocument();
        self::assertInstanceOf(Document::class, $doc);
        self::assertSame('safe-cert-name.pdf', $doc->getFilename());
        self::assertSame('my-cert.pdf', $doc->getOriginalFilename());
        self::assertSame('/uploads/documents/safe-cert-name.pdf', $doc->getFilePath());
        self::assertNotNull($doc->getSha256Hash());
        self::assertSame(64, strlen((string) $doc->getSha256Hash()));

        // Both Document and Certificate were persisted.
        self::assertContains($doc, $persisted);
        self::assertContains($cert, $persisted);

        // The file actually landed in the isolated upload dir.
        self::assertFileExists($this->uploadDir . '/public/uploads/documents/safe-cert-name.pdf');
    }
}
