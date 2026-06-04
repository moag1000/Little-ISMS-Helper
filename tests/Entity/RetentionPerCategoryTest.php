<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * N-4 (GDPR Art. 30(1)(f)): optional per-category retention. Source-inspection
 * pattern — robust under the shared-vendor multi-worktree setup.
 */
final class RetentionPerCategoryTest extends TestCase
{
    private static function source(string $rel): string
    {
        $file = __DIR__ . '/../../src/' . $rel;
        self::assertFileExists($file);
        $s = file_get_contents($file);
        self::assertIsString($s);
        return $s;
    }

    #[Test]
    public function entityDeclaresRetentionPerCategory(): void
    {
        $s = self::source('Entity/ProcessingActivity.php');
        self::assertStringContainsString('private ?array $retentionPerCategory = null;', $s);
        self::assertStringContainsString('public function getRetentionPerCategory(): ?array', $s);
        self::assertStringContainsString('public function setRetentionPerCategory(', $s);
    }

    #[Test]
    public function formExposesRetentionPerCategoryViaJsonType(): void
    {
        $s = self::source('Form/ProcessingActivityType.php');
        self::assertStringContainsString("->add('retentionPerCategory', JsonStructuredType::class", $s);
    }
}
