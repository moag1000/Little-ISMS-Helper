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
use ReflectionMethod;
use ReflectionProperty;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

/**
 * Unit tests for the GFM-table rendering added to
 * {@see PolicyPdfExporter::markdownToHtml()}.
 *
 * The method is private, but we call it through the public
 * {@see PolicyPdfExporter::renderBodyHtmlPublic()} surface which
 * drives it with the document's policyBody.
 */
#[AllowMockObjectsWithoutExpectations]
final class PolicyPdfExporterMarkdownTableTest extends TestCase
{
    private PolicyPdfExporter $exporter;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader(
            dirname(__DIR__, 4) . '/templates',
        );
        $twig = new TwigEnvironment($loader, ['cache' => false, 'strict_variables' => false]);
        $twig->addFilter(new \Twig\TwigFilter('trans', static function (string $message): string {
            $parts = explode('.', $message);
            return ucfirst((string) end($parts));
        }));
        $twig->addGlobal('app', new class () {
            public function getRequest(): object
            {
                return new class () {
                    public function getLocale(): string { return 'en'; }
                };
            }
        });
        $this->exporter = new PolicyPdfExporter($twig);
    }

    #[Test]
    public function testSimpleGfmTableRendersCorrectly(): void
    {
        $body = "## 3. RACI\n\n| Rolle | R | A |\n| --- | --- | --- |\n| CISO | A | R |\n| DPO | C | I |\n";
        $html = $this->renderBody($body);

        self::assertStringContainsString('<table class="fa-policy-table">', $html);
        self::assertStringContainsString('<thead>', $html);
        self::assertStringContainsString('<tbody>', $html);
        self::assertStringContainsString('<th>Rolle</th>', $html);
        self::assertStringContainsString('<th>R</th>', $html);
        self::assertStringContainsString('<td>CISO</td>', $html);
        self::assertStringContainsString('<td>DPO</td>', $html);
        // heading before table must still be rendered
        self::assertStringContainsString('<h2>', $html);
    }

    #[Test]
    public function testTableWithAlignmentColons(): void
    {
        $body = "| Name | Score |\n| :--- | ---: |\n| Alice | 99 |\n";
        $html = $this->renderBody($body);

        self::assertStringContainsString('<table class="fa-policy-table">', $html);
        // right-aligned column
        self::assertStringContainsString('text-align:right', $html);
        // left-aligned (default — no style attr on th/td for left)
        self::assertStringContainsString('<th>Name</th>', $html);
    }

    #[Test]
    public function testTableCellContentIsHtmlEscaped(): void
    {
        $body = "| Col |\n| --- |\n| <script>evil</script> |\n";
        $html = $this->renderBody($body);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function testTableFollowedByParagraphRendersCorrectly(): void
    {
        $body = "| A | B |\n| --- | --- |\n| 1 | 2 |\n\nSome paragraph after the table.\n";
        $html = $this->renderBody($body);

        self::assertStringContainsString('<table class="fa-policy-table">', $html);
        self::assertStringContainsString('</table>', $html);
        self::assertStringContainsString('<p>', $html);
        self::assertStringContainsString('Some paragraph after the table.', $html);
    }

    #[Test]
    public function testNonTablePipeLineDoesNotTriggerTable(): void
    {
        // A single pipe line without a separator row below it must NOT render as table.
        $body = "Some text with | pipe in it.\nNo separator row follows.\n";
        $html = $this->renderBody($body);

        self::assertStringNotContainsString('<table', $html);
    }

    #[Test]
    public function testRaciMatrixRendersAllCells(): void
    {
        $body = "## Verantwortlichkeiten (RACI)\n\n"
            . "| Rolle | R | A | C | I |\n"
            . "| --- | --- | --- | --- | --- |\n"
            . "| CISO | A | R | C | I |\n"
            . "| IT-Leiter | R | C | I | - |\n";
        $html = $this->renderBody($body);

        self::assertStringContainsString('<table class="fa-policy-table">', $html);
        self::assertStringContainsString('<th>Rolle</th>', $html);
        self::assertStringContainsString('<td>CISO</td>', $html);
        self::assertStringContainsString('<td>IT-Leiter</td>', $html);
        // 4 columns R/A/C/I
        self::assertStringContainsString('<th>C</th>', $html);
        self::assertStringContainsString('<th>I</th>', $html);
    }

    /** Render a markdown body through the public API surface. */
    private function renderBody(string $body): string
    {
        $doc = $this->makeDocument();
        $doc->setPolicyBody($body);
        return (string) $this->exporter->renderBodyHtmlPublic($doc, true);
    }

    private function makeDocument(): Document
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn(1);
        $tenant->method('getLegalName')->willReturn('TestCo');
        $tenant->method('getName')->willReturn('TestCo');
        $tenant->method('getCode')->willReturn('TC');

        $template = new PolicyTemplate();
        $template->setKey('iso27001.access_control');
        $template->setStandard('iso27001');
        $template->setTopic('access_control');
        $template->setVersion(1);

        $doc = new Document();
        $doc->setTenant($tenant);
        $doc->setFilename('policy-test.md');
        $doc->setOriginalFilename('Test Policy');
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(100);
        $doc->setFilePath('virtual:test');
        $doc->setCategory('policy');
        $doc->setStatus('approved');
        $doc->setUploadedAt(new DateTimeImmutable('2026-06-01 10:00:00'));
        $doc->setSha256Hash('abc123');
        $doc->setGeneratedFromTemplate($template);

        $ref = new ReflectionProperty(Document::class, 'id');
        $ref->setValue($doc, 42);

        return $doc;
    }
}
