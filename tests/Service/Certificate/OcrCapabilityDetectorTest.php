<?php

declare(strict_types=1);

namespace App\Tests\Service\Certificate;

use App\Service\Certificate\OcrCapabilityDetector;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test for {@see OcrCapabilityDetector}.
 *
 * No database, no kernel — only stub ModuleConfigurationService and a
 * closure-based binary resolver to keep the test hermetic.
 */
class OcrCapabilityDetectorTest extends TestCase
{
    private function makeModules(bool $isActive): ModuleConfigurationService
    {
        /** @var ModuleConfigurationService $stub */
        $stub = $this->createStub(ModuleConfigurationService::class);
        $stub->method('isModuleActive')
            ->willReturnCallback(fn(string $key) => $key === 'ocr_processing' && $isActive);

        return $stub;
    }

    private function makeBinaryResolver(array $presentBins): \Closure
    {
        return fn(string $bin) => in_array($bin, $presentBins, true) ? "/usr/bin/$bin" : null;
    }

    #[Test]
    public function isAvailableReturnsTrueWhenModuleActiveAndBothBinsPresent(): void
    {
        $detector = new OcrCapabilityDetector(
            modules: $this->makeModules(true),
            binaryResolver: $this->makeBinaryResolver(['pdftotext', 'tesseract']),
        );

        self::assertTrue($detector->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsFalseWhenModuleActiveButPdftotextMissing(): void
    {
        $detector = new OcrCapabilityDetector(
            modules: $this->makeModules(true),
            binaryResolver: $this->makeBinaryResolver(['tesseract']),
        );

        self::assertFalse($detector->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsFalseWhenModuleActiveButTesseractMissing(): void
    {
        $detector = new OcrCapabilityDetector(
            modules: $this->makeModules(true),
            binaryResolver: $this->makeBinaryResolver(['pdftotext']),
        );

        self::assertFalse($detector->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsFalseWhenModuleInactiveEvenIfBothBinsPresent(): void
    {
        $resolverCallCount = 0;
        $resolver = function (string $bin) use (&$resolverCallCount): string {
            ++$resolverCallCount;
            return "/usr/bin/$bin";
        };

        $detector = new OcrCapabilityDetector(
            modules: $this->makeModules(false),
            binaryResolver: $resolver,
        );

        self::assertFalse($detector->isAvailable());
        // Short-circuit: binary resolver must NOT have been called
        self::assertSame(0, $resolverCallCount, 'Binary resolver must not be called when module is inactive');
    }
}
