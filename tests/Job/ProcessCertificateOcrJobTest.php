<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Entity\ComplianceCertificate;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Exception\Module\ModuleNotActiveException;
use App\Job\JobContext;
use App\Job\ProcessCertificateOcrJob;
use App\Repository\ComplianceCertificateRepository;
use App\Service\Certificate\CertificateFieldExtractor;
use App\Service\Certificate\OcrCapabilityDetector;
use App\Service\Certificate\PdfTextExtractor;
use App\Service\Job\JobStatusService;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for {@see ProcessCertificateOcrJob}.
 *
 * Requires a real database (APP_ENV=test). The pdf→text step is stubbed via a
 * fake {@see PdfTextExtractor} so the test needs neither real binaries nor a
 * real PDF file on disk. The {@see OcrCapabilityDetector} is stubbed via its
 * `$binaryResolver` closure + a real ModuleConfigurationService so isAvailable()
 * is deterministic.
 */
#[Group('integration')]
class ProcessCertificateOcrJobTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private const SAMPLE_CERT_TEXT = <<<'TXT'
        TÜV SÜD Management Service GmbH

        CERTIFICATE

        This is to certify that the management system of

        issued to Beispiel AG

        has been assessed and complies with the requirements of

        ISO/IEC 27001:2022

        Certificate No.: 12 345 6789 0
        Date of issue: 2024-03-14
        Valid until: 2027-03-13
        TXT;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    #[Test]
    public function runWritesDraftFieldsWhenOcrAvailable(): void
    {
        $cert = $this->createCertificate();
        $this->em->flush();
        $certId = $cert->getId();
        self::assertNotNull($certId);

        $job = $this->makeJob(
            ocrAvailable: true,
            pdfText: self::SAMPLE_CERT_TEXT,
        );

        $job->run($this->makeContext(['certificateId' => $certId]));

        $this->em->clear();

        $reloaded = self::getContainer()
            ->get(ComplianceCertificateRepository::class)
            ->find($certId);

        self::assertInstanceOf(ComplianceCertificate::class, $reloaded);
        self::assertSame('ocr', $reloaded->getExtractionSource());
        self::assertNotNull($reloaded->getExtractionConfidence());
        self::assertGreaterThan(0.0, $reloaded->getExtractionConfidence());
        self::assertSame('ISO27001', $reloaded->getFrameworkCode());
        self::assertSame('TÜV SÜD', $reloaded->getCertBody());
        self::assertSame('12 345 6789 0', $reloaded->getCertNumber());
        self::assertSame('Beispiel AG', $reloaded->getHolder());
        self::assertNotNull($reloaded->getValidUntil());
        self::assertSame('2027-03-13', $reloaded->getValidUntil()->format('Y-m-d'));
        self::assertNotNull($reloaded->getIssueDate());
        self::assertSame('2024-03-14', $reloaded->getIssueDate()->format('Y-m-d'));
    }

    #[Test]
    public function runThrowsAndDoesNotMutateWhenOcrUnavailable(): void
    {
        $cert = $this->createCertificate();
        $this->em->flush();
        $certId = $cert->getId();
        self::assertNotNull($certId);

        $job = $this->makeJob(
            ocrAvailable: false,
            pdfText: self::SAMPLE_CERT_TEXT,
        );

        try {
            $job->run($this->makeContext(['certificateId' => $certId]));
            self::fail('Expected ModuleNotActiveException when OCR is unavailable.');
        } catch (ModuleNotActiveException $e) {
            // expected
            self::assertSame('ocr_processing', $e->getModuleKey());
        }

        $this->em->clear();

        $reloaded = self::getContainer()
            ->get(ComplianceCertificateRepository::class)
            ->find($certId);

        self::assertInstanceOf(ComplianceCertificate::class, $reloaded);
        // Manual source untouched, no confidence written.
        self::assertSame('manual', $reloaded->getExtractionSource());
        self::assertNull($reloaded->getExtractionConfidence());
        self::assertSame('', $reloaded->getFrameworkCode());
    }

    // -------------------------------------------------------------------------

    private function makeJob(bool $ocrAvailable, string $pdfText): ProcessCertificateOcrJob
    {
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');

        // OcrCapabilityDetector is final → build a REAL one and control both axes
        // hermetically: a ModuleConfigurationService subclass forces the module
        // state, and the $binaryResolver closure forces binary presence/absence.
        // No YAML file writes, no dependency on the host's real binaries.
        $modules = new class($projectDir, $ocrAvailable) extends ModuleConfigurationService {
            public function __construct(string $projectDir, private readonly bool $active)
            {
                parent::__construct($projectDir);
            }

            public function isModuleActive(string $moduleKey): bool
            {
                if ($moduleKey === 'ocr_processing') {
                    return $this->active;
                }

                return parent::isModuleActive($moduleKey);
            }
        };

        $resolver = static fn(string $bin): ?string => $ocrAvailable ? '/usr/bin/' . $bin : null;

        $detector = new OcrCapabilityDetector($modules, 'pdftotext', 'tesseract', $resolver);

        $pdfExtractor = new class($pdfText) extends PdfTextExtractor {
            public function __construct(private readonly string $text)
            {
                parent::__construct();
            }

            public function extractText(string $absPath): string
            {
                return $this->text;
            }
        };

        return new ProcessCertificateOcrJob(
            $detector,
            $pdfExtractor,
            new CertificateFieldExtractor(),
            self::getContainer()->get(ComplianceCertificateRepository::class),
            $this->em,
            self::getContainer()->getParameter('kernel.project_dir'),
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function makeContext(array $args): JobContext
    {
        $statusService = self::getContainer()->get(JobStatusService::class);
        $jobId = $statusService->create('test.cert_ocr', []);

        return new JobContext($jobId, $statusService, $args);
    }

    private function createCertificate(): ComplianceCertificate
    {
        $tenant = new Tenant();
        $tenant->setName('OCR Test Tenant');
        $tenant->setCode('ocr_' . uniqid());
        $this->em->persist($tenant);

        $user = new User();
        $user->setEmail('ocr_' . uniqid() . '@example.test');
        $user->setFirstName('Ocr');
        $user->setLastName('Tester');
        $user->setTenant($tenant);
        $this->em->persist($user);

        $doc = new Document();
        $doc->setFilename('cert.pdf')
            ->setOriginalFilename('cert.pdf')
            ->setMimeType('application/pdf')
            ->setFileSize(1024)
            ->setFilePath('/uploads/documents/cert.pdf')
            ->setCategory('certificate')
            ->setTenant($tenant)
            ->setUploadedBy($user);
        $this->em->persist($doc);

        $cert = new ComplianceCertificate();
        $cert->setTenant($tenant)
            ->setUploadedBy($user)
            ->setCertificateDocument($doc)
            ->setExtractionSource('manual')
            ->setStatus('active')
            ->setFrameworkCode('')
            ->setCertBody('');
        $this->em->persist($cert);

        return $cert;
    }
}
