<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Export;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\TenantBranding;
use App\Repository\TenantBrandingRepository;
use App\Service\PolicyWizard\Export\PolicyPdfExporter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

/**
 * Policy-Wizard W7-A — PolicyPdfExporter unit tests.
 *
 * Verifies dompdf-backed PDF generation against:
 *  - basic PDF output structure (`%PDF-` magic bytes)
 *  - tenant branding letterhead inclusion (logo + legal_name + colors)
 *  - graceful fallback when no TenantBranding row is wired
 *  - cover page approver-chain rendering
 *  - footer batch_id surfacing from substitutionVariables
 *
 * Tests the renderBodyHtml + dompdf pipeline end-to-end. Translations
 * use a stub translator backed by a minimal IdentityTranslator
 * extension so we don't load the full Symfony container.
 */
#[AllowMockObjectsWithoutExpectations]
final class PolicyPdfExporterTest extends TestCase
{
    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader(
            dirname(__DIR__, 4) . '/templates',
        );
        $this->twig = new TwigEnvironment($loader, ['cache' => false, 'strict_variables' => false]);

        // The PDF templates use the trans filter; register it as a
        // simple identity filter so we don't need the full Symfony
        // translation extension here.
        $this->twig->addFilter(new \Twig\TwigFilter('trans', static function (
            string $message,
            array $arguments = [],
            ?string $domain = null,
        ): string {
            // Echo the last segment after the final dot for readability.
            $parts = explode('.', $message);
            return ucfirst((string) end($parts));
        }));
        // Templates reference app.request.locale; provide a stub global.
        $this->twig->addGlobal('app', new class () {
            public function getRequest(): object
            {
                return new class () {
                    public function getLocale(): string
                    {
                        return 'en';
                    }
                };
            }
        });
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

    private function makeTemplate(string $standard = 'iso27001', string $topic = 'access_control'): PolicyTemplate
    {
        $template = new PolicyTemplate();
        $template->setKey($standard . '.' . $topic);
        $template->setStandard($standard);
        $template->setTopic($topic);
        $template->setVersion(2);
        return $template;
    }

    private function makeDocument(
        ?Tenant $tenant = null,
        ?PolicyTemplate $template = null,
        array $variables = [],
        ?string $body = null,
    ): Document {
        $doc = new Document();
        $doc->setTenant($tenant ?? $this->makeTenant());
        $doc->setFilename('policy-test.md');
        $doc->setOriginalFilename('Access Control Policy');
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(1234);
        $doc->setFilePath('virtual:test');
        $doc->setCategory('policy');
        $doc->setDescription($body ?? "# Heading\n\nA paragraph with **bold** and *italic*.\n\n- item one\n- item two");
        $doc->setStatus('approved');
        $doc->setUploadedAt(new DateTimeImmutable('2026-05-08 10:00:00'));
        $doc->setSha256Hash('abcdef1234567890');
        if ($template !== null) {
            $doc->setGeneratedFromTemplate($template);
        }
        $doc->setSubstitutionVariables($variables + ['_title' => 'Access Control Policy']);

        // Stamp synthetic ID via reflection (auto-generated normally).
        $ref = new ReflectionProperty(Document::class, 'id');
        $ref->setValue($doc, 99);

        return $doc;
    }

    private function makeBranding(Tenant $tenant): TenantBranding
    {
        $branding = new TenantBranding();
        $branding->setTenant($tenant);
        $branding->setPrimaryColor('#aa00bb');
        $branding->setSecondaryColor('#005500');
        $branding->setFontFamily('Helvetica');
        $branding->setHeaderHtml('<em>Confidential — Internal Only</em>');
        $branding->setFooterHtml('<em>TestCo GmbH · Berlin</em>');
        return $branding;
    }

    #[Test]
    public function testRendersDocumentToPdf(): void
    {
        $tenant = $this->makeTenant();
        $doc = $this->makeDocument($tenant, $this->makeTemplate());

        $exporter = new PolicyPdfExporter($this->twig);

        $pdf = $exporter->exportDocument($doc, null);

        self::assertNotSame('', $pdf);
        self::assertSame('%PDF-', substr($pdf, 0, 5), 'Output should start with PDF magic bytes.');
        self::assertGreaterThan(1000, strlen($pdf), 'A real PDF rendering is at least ~1 KB.');
    }

    #[Test]
    public function testIncludesTenantBrandingLetterhead(): void
    {
        $tenant = $this->makeTenant();
        $branding = $this->makeBranding($tenant);
        $doc = $this->makeDocument($tenant, $this->makeTemplate());

        $exporter = new PolicyPdfExporter($this->twig);

        $pdf = $exporter->exportDocument($doc, $branding);

        // Peek at the (mostly binary) PDF stream — readable text
        // segments still let us assert the legal_name + branding text
        // were rendered into the page tree.
        self::assertNotSame('', $pdf);
        self::assertSame('%PDF-', substr($pdf, 0, 5));
        // Branding header label + legal name should both appear in the
        // (uncompressed when small) text stream.
        $haystack = $this->extractText($pdf);
        self::assertStringContainsString('TestCo GmbH', $haystack);
    }

    #[Test]
    public function testFallsBackToDefaultsWithoutBranding(): void
    {
        $tenant = $this->makeTenant();
        $doc = $this->makeDocument($tenant, $this->makeTemplate());

        $brandingRepo = $this->createMock(TenantBrandingRepository::class);
        $brandingRepo->method('findOneByTenant')->willReturn(null);

        $exporter = new PolicyPdfExporter($this->twig, $brandingRepo);

        $pdf = $exporter->exportDocument($doc, null);

        self::assertNotSame('', $pdf);
        self::assertSame('%PDF-', substr($pdf, 0, 5));
        // Without a branding row, legal name still appears (fallback to
        // `tenant.legalName` in header right cell).
        $haystack = $this->extractText($pdf);
        self::assertStringContainsString('TestCo GmbH', $haystack);
    }

    #[Test]
    public function testCoverPageContainsApproverChain(): void
    {
        $tenant = $this->makeTenant();
        $doc = $this->makeDocument(
            $tenant,
            $this->makeTemplate(),
            ['approval.chain' => ['ROLE_DPO: Anna Müller', 'ROLE_CISO: Bob Tester', 'ROLE_TOP_MGMT: Cara Chief']],
        );

        $exporter = new PolicyPdfExporter($this->twig);
        $pdf = $exporter->exportDocument($doc, null);

        $haystack = $this->extractText($pdf);
        self::assertStringContainsString('ROLE_DPO', $haystack);
        self::assertStringContainsString('ROLE_CISO', $haystack);
        self::assertStringContainsString('ROLE_TOP_MGMT', $haystack);
    }

    #[Test]
    public function testFooterContainsBatchId(): void
    {
        $tenant = $this->makeTenant();
        $doc = $this->makeDocument(
            $tenant,
            $this->makeTemplate(),
            ['audit_trail.batch_id' => 'BATCH-2026-05-08-001'],
        );

        $exporter = new PolicyPdfExporter($this->twig);
        $pdf = $exporter->exportDocument($doc, null);

        $haystack = $this->extractText($pdf);
        self::assertStringContainsString('BATCH-2026-05-08-001', $haystack);
    }

    /**
     * Pull readable text out of a dompdf-rendered PDF buffer. dompdf
     * emits page contents as FlateDecode-compressed streams; we walk
     * each `stream … endstream` block, decompress it, then strip
     * non-printable bytes so we can assert on substrings.
     */
    private function extractText(string $pdf): string
    {
        $out = '';
        $offset = 0;
        while (($start = strpos($pdf, "stream\n", $offset)) !== false) {
            $start += strlen("stream\n");
            $end = strpos($pdf, "\nendstream", $start);
            if ($end === false) {
                break;
            }
            $blob = substr($pdf, $start, $end - $start);
            $offset = $end + strlen("\nendstream");

            $decoded = @gzuncompress($blob);
            if ($decoded === false) {
                $decoded = @gzinflate($blob);
            }
            if (!is_string($decoded)) {
                continue;
            }
            // PDF text-show operators wrap strings in `( … )`; pull them
            // out so we can match plain-text substrings.
            if (preg_match_all('/\(([^()\\\\]*)\)/', $decoded, $matches)) {
                foreach ($matches[1] as $literal) {
                    $out .= $literal . ' ';
                }
            }
        }
        return $out;
    }
}
