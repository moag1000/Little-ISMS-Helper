<?php

declare(strict_types=1);

namespace App\Service\Certificate;

use App\Exception\Io\IoException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Extracts plain text from a certificate file on disk.
 *
 * Two-stage strategy (digital-first, scan-fallback):
 *   1. `pdftotext -layout <path> -` — pulls the embedded text layer of a
 *      digitally-generated PDF. Fast and accurate when a text layer exists.
 *   2. If that yields only whitespace (a scanned image PDF with no text
 *      layer), fall back to `tesseract <path> stdout` — true OCR.
 *
 * This collaborator isolates the only part of the OCR pipeline that touches
 * external binaries, so {@see ProcessCertificateOcrJob} stays unit-testable:
 * tests inject a stub subclass overriding {@see extractText()} and need no
 * real PDF, no `pdftotext`, and no `tesseract` on the host.
 *
 * Availability of the binaries themselves is gated upstream by
 * {@see OcrCapabilityDetector}; this class assumes they exist when called.
 */
class PdfTextExtractor
{
    /**
     * @param int $timeoutSeconds Max wall-clock per binary invocation.
     */
    public function __construct(
        private readonly string $pdftotextBin = 'pdftotext',
        private readonly string $tesseractBin = 'tesseract',
        private readonly int $timeoutSeconds = 120,
    ) {
    }

    /**
     * Return the extracted text for the file at $absPath.
     *
     * @param string $absPath Absolute path to the (PDF or image) file on disk.
     *
     * @throws IoException If the file is missing or both extraction stages fail.
     */
    public function extractText(string $absPath): string
    {
        if (!is_file($absPath)) {
            throw new IoException('Certificate file not found for OCR.', $absPath);
        }

        $digital = $this->run([$this->pdftotextBin, '-layout', $absPath, '-']);

        if (trim($digital) !== '') {
            return $digital;
        }

        // No text layer → scanned document; fall back to real OCR.
        return $this->run([$this->tesseractBin, $absPath, 'stdout']);
    }

    /**
     * @param list<string> $command
     *
     * @throws IoException
     */
    private function run(array $command): string
    {
        $process = new Process($command);
        $process->setTimeout((float) $this->timeoutSeconds);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new IoException(
                sprintf('Text extraction command "%s" failed.', $command[0]),
                $command[count($command) - 1] ?? null,
                $e,
            );
        }

        return $process->getOutput();
    }
}
