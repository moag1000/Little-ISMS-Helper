<?php

declare(strict_types=1);

namespace App\Tests\Service\Evidence;

use App\Service\Evidence\ContentHashCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use App\Exception\Io\IoException;

/**
 * F4 — ContentHashCalculator unit tests.
 */
class ContentHashCalculatorTest extends TestCase
{
    private ContentHashCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ContentHashCalculator();
    }

    #[Test]
    public function testCalculateFromString(): void
    {
        $hash = $this->calculator->calculateFromString('hello world');
        // Verify against PHP's own sha256 implementation (ground truth)
        self::assertSame(hash('sha256', 'hello world'), $hash);
        self::assertSame(64, strlen($hash));
    }

    #[Test]
    public function testCalculateFromStringEmpty(): void
    {
        $hash = $this->calculator->calculateFromString('');
        self::assertSame(hash('sha256', ''), $hash);
        self::assertSame(64, strlen($hash));
    }

    #[Test]
    public function testCalculateFromPath(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'evidence_hash_test');
        file_put_contents($tmpFile, 'test content for hashing');

        try {
            $hash = $this->calculator->calculateFromPath($tmpFile);
            $expected = hash('sha256', 'test content for hashing');
            self::assertSame($expected, $hash);
            self::assertSame(64, strlen($hash));
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function testCalculateFromPathThrowsForMissingFile(): void
    {
        $this->expectException(IoException::class);
        $this->calculator->calculateFromPath('/nonexistent/path/file.pdf');
    }

    #[Test]
    public function testCalculateFromSplFileInfo(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'evidence_spl_test');
        file_put_contents($tmpFile, 'spl file content');

        try {
            $fileInfo = new \SplFileInfo($tmpFile);
            $hash = $this->calculator->calculateFromFile($fileInfo);
            $expected = hash('sha256', 'spl file content');
            self::assertSame($expected, $hash);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function testDifferentContentProducesDifferentHash(): void
    {
        $hash1 = $this->calculator->calculateFromString('content A');
        $hash2 = $this->calculator->calculateFromString('content B');

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function testSameContentProducesSameHash(): void
    {
        $hash1 = $this->calculator->calculateFromString('identical content');
        $hash2 = $this->calculator->calculateFromString('identical content');

        self::assertSame($hash1, $hash2);
    }
}
