<?php

declare(strict_types=1);

namespace App\Service\Certificate;

/**
 * Test seam over {@see OcrCapabilityDetector}.
 *
 * The concrete detector is `final` and gates on host binaries
 * (pdftotext / tesseract) + the `ocr_processing` module, which makes it hard
 * to drive deterministically from a functional test on a CI host that may or
 * may not have those binaries installed. Consumers that only need the
 * availability verdict (e.g. {@see \App\Controller\ComplianceCertificateController})
 * depend on this narrow interface instead, so the test environment can bind a
 * hermetic stub (see config/services_test.yaml) that toggles
 * availability without touching the filesystem.
 *
 * Production binds this to {@see OcrCapabilityDetector} (config/services.yaml).
 */
interface OcrCapabilityInterface
{
    /**
     * Returns true iff the OCR pipeline can run for the current tenant/host.
     */
    public function isAvailable(): bool;
}
