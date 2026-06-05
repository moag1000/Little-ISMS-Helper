<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\Document;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html as PhpWordHtml;
use PhpOffice\PhpWord\Writer\Word2007;

/**
 * F20 — DOCX + Markdown export for ISMS Documents.
 *
 * The canonical document body is Markdown ({@see Document::getEffectivePolicyBody()},
 * with the same fallback chain the PDF exporter uses). This service renders that
 * Markdown into:
 *   - a Word .docx (via PhpWord — headings, paragraphs, bold/italic, lists), or
 *   - a raw .md file (round-trippable plain text).
 *
 * Editable .docx is the format most auditors + policy owners ask for (track
 * changes, comments), complementing the read-only PDF export.
 */
final class DocxExportService
{
    /**
     * Resolve the canonical Markdown body of a Document, mirroring the PDF
     * exporter's fallback order: effective policy body → substitution `_body`
     * snapshot → description.
     */
    public function resolveMarkdown(Document $document): string
    {
        $effective = $document->getEffectivePolicyBody();
        if (is_string($effective) && trim($effective) !== '') {
            return $effective;
        }

        $vars = $document->getSubstitutionVariables() ?? [];
        if (isset($vars['_body']) && is_string($vars['_body']) && trim($vars['_body']) !== '') {
            return $vars['_body'];
        }

        $description = (string) ($document->getDescription() ?? '');
        if (trim($description) !== '') {
            return $description;
        }

        return '_(empty body)_';
    }

    /**
     * Render the Document to a .docx and return the binary string.
     */
    public function exportDocument(Document $document): string
    {
        $phpWord = new PhpWord();
        $phpWord->getDocInfo()->setTitle((string) ($document->getFilename() ?? 'Document'));

        $section = $phpWord->addSection();

        // Title heading.
        $title = (string) ($document->getFilename() ?? 'Document');
        if ($title !== '') {
            $section->addTitle($title, 1);
        }

        // Body: convert the Markdown to HTML (reuse a safe subset) and let
        // PhpWord's HTML reader emit the corresponding Word elements. This keeps
        // headings/bold/lists without re-implementing a Word AST by hand.
        $html = $this->markdownToHtml($this->resolveMarkdown($document));
        try {
            PhpWordHtml::addHtml($section, $html, false, false);
        } catch (\Throwable) {
            // PhpWord's HTML reader is strict; on any parse hiccup fall back to
            // a plain-text paragraph so the export never hard-fails.
            $section->addText(strip_tags($html));
        }

        return $this->writeToString($phpWord);
    }

    /**
     * Return the raw Markdown body (for a .md download).
     */
    public function exportMarkdown(Document $document): string
    {
        $title = (string) ($document->getFilename() ?? 'Document');
        $body = $this->resolveMarkdown($document);

        return "# {$title}\n\n{$body}\n";
    }

    private function writeToString(PhpWord $phpWord): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx-');
        if ($tmp === false) {
            throw new \App\Exception\Io\IoException('Failed to allocate a temp file for the DOCX export.');
        }

        try {
            (new Word2007($phpWord))->save($tmp);
            $bytes = file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }

        if ($bytes === false) {
            throw new \App\Exception\Io\IoException('Failed to read back the generated DOCX.');
        }

        return $bytes;
    }

    /**
     * Minimal, safe Markdown→HTML for the DOCX HTML reader. Supports headings
     * (#/##/###), bold (**), italic (*), inline code (`) and unordered lists.
     * Everything is HTML-escaped first to prevent injection.
     */
    private function markdownToHtml(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $html = [];
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = rtrim($line);

            if (preg_match('/^\s*[-*]\s+(.*)$/', $trimmed, $m) === 1) {
                if (!$inList) {
                    $html[] = '<ul>';
                    $inList = true;
                }
                $html[] = '<li>' . $this->inline($m[1]) . '</li>';
                continue;
            }

            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }

            if (preg_match('/^(#{1,3})\s+(.*)$/', $trimmed, $m) === 1) {
                $level = strlen($m[1]) + 1; // h2..h4 (h1 reserved for the title)
                $html[] = "<h{$level}>" . $this->inline($m[2]) . "</h{$level}>";
                continue;
            }

            if ($trimmed === '') {
                continue;
            }

            $html[] = '<p>' . $this->inline($trimmed) . '</p>';
        }

        if ($inList) {
            $html[] = '</ul>';
        }

        return implode("\n", $html);
    }

    private function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace('/`(.+?)`/', '<span style="font-family:monospace">$1</span>', $escaped) ?? $escaped;

        return $escaped;
    }
}
