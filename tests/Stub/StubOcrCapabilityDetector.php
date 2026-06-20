<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Service\Certificate\OcrCapabilityInterface;

/**
 * Hermetic test double for {@see \App\Service\Certificate\OcrCapabilityDetector}.
 *
 * The real detector is `final` and gates on host binaries (pdftotext /
 * tesseract) + the `ocr_processing` module — neither of which is reliable on a
 * CI host. This stub lets a functional test drive BOTH controller branches
 * deterministically by flipping a static flag BEFORE issuing the request that
 * boots the kernel.
 *
 * Bound to {@see OcrCapabilityInterface} only in the test environment
 * (config/services_test.yaml). Default verdict is `false`, so unless a
 * test explicitly opts in, every other functional test sees the manual upload
 * path (identical to a production host without the OCR binaries).
 */
final class StubOcrCapabilityDetector implements OcrCapabilityInterface
{
    private static bool $available = false;

    public static function setAvailable(bool $available): void
    {
        self::$available = $available;
    }

    /**
     * Reset to the default (unavailable) verdict — call in test tearDown so the
     * flag never leaks into a sibling test class.
     */
    public static function reset(): void
    {
        self::$available = false;
    }

    public function isAvailable(): bool
    {
        return self::$available;
    }
}
