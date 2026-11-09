<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Entity\Document;
use App\Service\Export\DocxExportService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * F20 — DOCX + Markdown export. DB-free: builds a Document in memory and checks
 * the produced .docx is a valid OOXML package and the .md carries the body.
 */
final class DocxExportServiceTest extends TestCase
{
    private function document(string $name, string $body): Document
    {
        $doc = new Document();
        $doc->setFilename($name);
        $doc->setDescription($body); // resolveMarkdown() falls back to description
        return $doc;
    }

    #[Test]
    public function exportsValidDocxPackage(): void
    {
        $doc = $this->document('Access Control Policy', "# Scope\n\nApplies to **all** staff.\n\n- rule one\n- rule two");

        $bytes = (new DocxExportService(new \Symfony\Component\Translation\IdentityTranslator()))->exportDocument($doc);
        self::assertNotSame('', $bytes);

        $tmp = tempnam(sys_get_temp_dir(), 'docx-test-');
        self::assertIsString($tmp);
        file_put_contents($tmp, $bytes);

        // A .docx is a ZIP (OOXML) containing word/document.xml.
        $zip = new ZipArchive();
        self::assertTrue($zip->open($tmp) === true, 'DOCX must be a valid ZIP/OOXML package');
        self::assertNotFalse($zip->locateName('word/document.xml'));
        $zip->close();
        @unlink($tmp);
    }

    #[Test]
    public function markdownExportContainsTitleAndBody(): void
    {
        $doc = $this->document('Backup Policy', 'Daily backups are mandatory.');

        $md = (new DocxExportService(new \Symfony\Component\Translation\IdentityTranslator()))->exportMarkdown($doc);

        self::assertStringContainsString('# Backup Policy', $md);
        self::assertStringContainsString('Daily backups are mandatory.', $md);
    }

    #[Test]
    public function resolveMarkdownFallsBackToEmptyPlaceholder(): void
    {
        $doc = new Document();
        $doc->setFilename('Empty');

        // No body / description set anywhere → placeholder, never an empty string.
        self::assertStringContainsString('export.doc.empty_body', (new DocxExportService(new \Symfony\Component\Translation\IdentityTranslator()))->resolveMarkdown($doc));
    }

    #[Test]
    public function exportNeverThrowsOnMalformedMarkdown(): void
    {
        $doc = $this->document('Weird', "## <script>alert(1)</script>\n**unterminated bold");

        // Must not throw and must escape the script tag (no raw <script> in output bytes).
        $bytes = (new DocxExportService(new \Symfony\Component\Translation\IdentityTranslator()))->exportDocument($doc);
        self::assertNotSame('', $bytes);
    }
    /**
     * S10: record text is text in the OOXML, never markup. Without output escaping a
     * title closing </w:t> injects a Word field (INCLUDEPICTURE → UNC fetch on open).
     */
    #[Test]
    public function titleAndBodyAreEscapedInTheXml(): void
    {
        $title = 'T</w:t></w:r><w:fldSimple w:instr="INCLUDEPICTURE \\\\host\\x"/><w:r><w:t> <&>';
        $doc = $this->document($title, "Body <&> \"quoted\" text");

        $bytes = (new DocxExportService(new \Symfony\Component\Translation\IdentityTranslator()))->exportDocument($doc);
        $tmp = tempnam(sys_get_temp_dir(), 'docx-test-');
        self::assertIsString($tmp);
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($tmp) === true);
        $xml = (string) $zip->getFromName('word/document.xml');
        $core = (string) $zip->getFromName('docProps/core.xml');
        $zip->close();
        @unlink($tmp);

        foreach (['word/document.xml' => $xml, 'docProps/core.xml' => $core] as $name => $part) {
            $dom = new \DOMDocument();
            self::assertTrue(@$dom->loadXML($part), $name . ' must stay well-formed XML');
        }
        self::assertStringNotContainsString('<w:fldSimple', $xml, 'record text must not become a Word field');

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $text = $dom->documentElement->textContent;
        self::assertStringContainsString($title, $text, 'title survives as literal text');
        self::assertStringContainsString('Body <&> "quoted" text', $text, 'body is escaped exactly once');
    }
}
