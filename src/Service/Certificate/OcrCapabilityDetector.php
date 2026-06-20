<?php

declare(strict_types=1);

namespace App\Service\Certificate;

use App\Service\ModuleConfigurationService;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Detects whether the OCR pipeline is available at runtime.
 *
 * Availability requires:
 *  1. The `ocr_processing` module to be active for this tenant.
 *  2. Both `pdftotext` (poppler-utils) and `tesseract` (tesseract-ocr) binaries
 *     to be present on the system PATH.
 *
 * The optional `$binaryResolver` closure allows tests to inject a hermetic
 * stub without touching the filesystem. Production code uses Symfony's
 * {@see ExecutableFinder} by default.
 */
final class OcrCapabilityDetector implements OcrCapabilityInterface
{
    public function __construct(
        private readonly ModuleConfigurationService $modules,
        private readonly string $pdftotextBin = 'pdftotext',
        private readonly string $tesseractBin = 'tesseract',
        private readonly ?\Closure $binaryResolver = null,
    ) {}

    /**
     * Returns true iff the ocr_processing module is active AND both required
     * system binaries are present on the PATH.
     */
    public function isAvailable(): bool
    {
        if (!$this->modules->isModuleActive('ocr_processing')) {
            return false;
        }

        return $this->resolve($this->pdftotextBin) !== null
            && $this->resolve($this->tesseractBin) !== null;
    }

    /**
     * Returns the names of any required binaries that are currently missing.
     * Useful for admin health checks / setup hints.
     *
     * @return list<string>
     */
    public function getMissingBinaries(): array
    {
        $missing = [];

        foreach ([$this->pdftotextBin, $this->tesseractBin] as $bin) {
            if ($this->resolve($bin) === null) {
                $missing[] = $bin;
            }
        }

        return $missing;
    }

    /**
     * Resolves the absolute path of a binary, or null if not found.
     */
    private function resolve(string $bin): ?string
    {
        if ($this->binaryResolver !== null) {
            return ($this->binaryResolver)($bin);
        }

        return (new ExecutableFinder())->find($bin);
    }
}
