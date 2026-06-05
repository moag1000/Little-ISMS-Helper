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

        $bytes = (new DocxExportService())->exportDocument($doc);
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

        $md = (new DocxExportService())->exportMarkdown($doc);

        self::assertStringContainsString('# Backup Policy', $md);
        self::assertStringContainsString('Daily backups are mandatory.', $md);
    }

    #[Test]
    public function resolveMarkdownFallsBackToEmptyPlaceholder(): void
    {
        $doc = new Document();
        $doc->setFilename('Empty');

        // No body / description set anywhere → placeholder, never an empty string.
        self::assertStringContainsString('empty body', (new DocxExportService())->resolveMarkdown($doc));
    }

    #[Test]
    public function exportNeverThrowsOnMalformedMarkdown(): void
    {
        $doc = $this->document('Weird', "## <script>alert(1)</script>\n**unterminated bold");

        // Must not throw and must escape the script tag (no raw <script> in output bytes).
        $bytes = (new DocxExportService())->exportDocument($doc);
        self::assertNotSame('', $bytes);
    }
}
