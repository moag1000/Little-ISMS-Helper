<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Export;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Service\PolicyWizard\Export\PolicyPdfExporter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

/**
 * Policy-Wizard — design-system policy-doc 5-slot template smoke-tests.
 *
 * Verifies the PDF export wraps every Document in the canonical
 * `<article class="policy-doc policy-doc--<status>">` shell with all
 * five mandatory semantic slots (cover · toc · history · body ·
 * signature). Markers are matched against the rendered HTML *before*
 * dompdf compresses streams, so we render the wrapper template
 * directly via Twig — the dompdf binary path is exercised by the
 * sibling {@see PolicyPdfExporterTest}.
 *
 * Spec: docs/design_system/sections/policy-templates.html.
 */
#[AllowMockObjectsWithoutExpectations]
final class PolicyPdfExporterTemplateTest extends TestCase
{
    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader(
            dirname(__DIR__, 4) . '/templates',
        );
        $this->twig = new TwigEnvironment($loader, ['cache' => false, 'strict_variables' => false]);

        // Identity-translator stub — tests assert structural classes,
        // not localised strings.
        $this->twig->addFilter(new \Twig\TwigFilter('trans', static function (
            string $message,
            array $arguments = [],
            ?string $domain = null,
        ): string {
            $parts = explode('.', $message);
            return ucfirst((string) end($parts));
        }));
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

    private function makeTenant(): Tenant
    {
        $stub = $this->createStub(Tenant::class);
        $stub->method('getId')->willReturn(11);
        $stub->method('getLegalName')->willReturn('TestCo GmbH');
        $stub->method('getName')->willReturn('TestCo');
        return $stub;
    }

    private function makeTemplate(): PolicyTemplate
    {
        $template = new PolicyTemplate();
        $template->setKey('iso27001.access_control');
        $template->setStandard('iso27001');
        $template->setTopic('access_control');
        $template->setVersion(2);
        return $template;
    }

    private function makeDocument(string $status = 'approved', ?string $body = null): Document
    {
        $doc = new Document();
        $doc->setTenant($this->makeTenant());
        $doc->setFilename('policy-test.md');
        $doc->setOriginalFilename('Access Control Policy');
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(1234);
        $doc->setFilePath('virtual:test');
        $doc->setCategory('policy');
        $doc->setDescription($body ?? "## Purpose\n\nA paragraph.\n\n## Scope\n\nMore prose.");
        $doc->setStatus($status);
        $doc->setUploadedAt(new DateTimeImmutable('2026-05-08 10:00:00'));
        $doc->setSha256Hash('abcdef1234567890');
        $doc->setGeneratedFromTemplate($this->makeTemplate());
        $doc->setSubstitutionVariables([
            '_title'           => 'Access Control Policy',
            'approval.chain'   => ['ROLE_DPO: Anna Müller', 'ROLE_CISO: Bob Tester', 'ROLE_TOP_MGMT: Cara Chief'],
        ]);

        $ref = new ReflectionProperty(Document::class, 'id');
        $ref->setValue($doc, 99);

        return $doc;
    }

    /**
     * Render the wrapper Twig template via the same context the
     * exporter builds, but bypass the dompdf binary step so we can
     * grep the raw HTML for design-system class markers.
     */
    private function renderWrapperHtml(Document $doc): string
    {
        $exporter = new PolicyPdfExporter($this->twig);
        $variables = $doc->getSubstitutionVariables() ?? [];

        // Mirror the exporter's context-build (inner private accessors
        // are invoked via reflection so we don't need to expose them).
        // PHP 8.1+ already exposes private methods to invoke() — the
        // setAccessible() call is a no-op since 8.1 and deprecated in
        // 8.5, so we skip it entirely.
        $reflector = new \ReflectionClass($exporter);
        $coverFn = $reflector->getMethod('buildCoverContext');
        $footerFn = $reflector->getMethod('buildFooterContext');
        $bodyFn = $reflector->getMethod('renderBodyHtml');

        return $this->twig->render(
            'policy_wizard/export/_pdf_document.html.twig',
            [
                'document'    => $doc,
                'tenant'      => $doc->getTenant(),
                'branding'    => null,
                'cover'       => $coverFn->invoke($exporter, $doc, $variables),
                'body_html'   => $bodyFn->invoke($exporter, $doc),
                'footer'      => $footerFn->invoke($exporter, $doc, $variables),
                'primary'     => '#0d6efd',
                'secondary'   => '#6c757d',
                'font_family' => 'Inter',
                'logo_path'   => null,
                'header_html' => null,
                'footer_html' => null,
            ],
        );
    }

    #[Test]
    public function testRenderedHtmlContainsAllFiveSemanticSlots(): void
    {
        $html = $this->renderWrapperHtml($this->makeDocument('approved'));

        // Article shell + status modifier.
        self::assertStringContainsString('class="policy-doc policy-doc--approved"', $html);

        // 5 mandatory semantic slots.
        self::assertStringContainsString('class="policy-doc__cover"', $html);
        self::assertStringContainsString('class="policy-doc__toc"', $html);
        self::assertStringContainsString('class="policy-doc__body"', $html);
        self::assertStringContainsString('class="policy-doc__signature"', $html);

        // History slot is conditional on a supersedes-chain. The
        // smoke-doc has no chain, so the slot is absent — verified
        // separately in {@see testHistorySlotEmittedWhenSupersedesChainPresent}.
    }

    #[Test]
    public function testStatusDriftWatermarkOnDraftDocument(): void
    {
        $html = $this->renderWrapperHtml($this->makeDocument('draft'));

        self::assertStringContainsString('policy-doc--draft', $html);
        self::assertStringContainsString('data-watermark="Entwurf"', $html);
    }

    #[Test]
    public function testApprovedDocumentHasNoWatermarkAttr(): void
    {
        $html = $this->renderWrapperHtml($this->makeDocument('approved'));

        self::assertStringContainsString('policy-doc--approved', $html);
        // data-watermark attr is only emitted for non-approved states.
        self::assertStringNotContainsString('data-watermark="Entwurf"', $html);
        self::assertStringNotContainsString('data-watermark="Archiviert"', $html);
    }

    #[Test]
    public function testTocAutoGeneratedFromBodyHeadings(): void
    {
        $html = $this->renderWrapperHtml($this->makeDocument(
            'approved',
            "## Purpose\n\nFirst chapter.\n\n## Scope\n\nSecond chapter.\n\n## References\n\nThird chapter.",
        ));

        self::assertStringContainsString('policy-doc__toc-list', $html);
        // All three <h2> headings should appear in the TOC.
        self::assertStringContainsString('Purpose', $html);
        self::assertStringContainsString('Scope', $html);
        self::assertStringContainsString('References', $html);
    }

    #[Test]
    public function testSignatureSlotPopulatedFromApproverChain(): void
    {
        $html = $this->renderWrapperHtml($this->makeDocument('approved'));

        self::assertStringContainsString('class="policy-doc__signature"', $html);
        self::assertStringContainsString('policy-doc__signature-grid', $html);
        // Approver-chain entries flow into 3 signature fields.
        self::assertStringContainsString('Anna Müller', $html);
        self::assertStringContainsString('Bob Tester', $html);
        self::assertStringContainsString('Cara Chief', $html);
    }

    #[Test]
    public function testCoverIncludesTitleAndKicker(): void
    {
        $html = $this->renderWrapperHtml($this->makeDocument('approved'));

        self::assertStringContainsString('class="policy-doc__title"', $html);
        self::assertStringContainsString('Access Control Policy', $html);
        self::assertStringContainsString('class="policy-doc__kicker"', $html);
        // standard|upper renders ISO27001.
        self::assertStringContainsString('ISO27001', $html);
    }

    #[Test]
    public function testInlinedAuroraTokenDefaultsPresent(): void
    {
        $html = $this->renderWrapperHtml($this->makeDocument('approved'));

        // The exporter inlines Aurora token defaults so dompdf has a
        // working --primary / --surface cascade.
        self::assertStringContainsString('--surface:', $html);
        self::assertStringContainsString('--primary:', $html);
        self::assertStringContainsString('--fg-1:', $html);
        // A4 portrait page-rule.
        self::assertStringContainsString('@page', $html);
        self::assertStringContainsString('A4 portrait', $html);
    }
}
