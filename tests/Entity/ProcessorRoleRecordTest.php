<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * N-7 (GDPR Art. 30(2)): processor-role record. Source-inspection pattern —
 * robust under the shared-vendor multi-worktree setup.
 */
final class ProcessorRoleRecordTest extends TestCase
{
    private static function src(string $rel): string
    {
        $f = __DIR__ . '/../../src/' . $rel;
        self::assertFileExists($f);
        $s = file_get_contents($f);
        self::assertIsString($s);
        return $s;
    }

    #[Test]
    public function entityDeclaresProcessorRoleFields(): void
    {
        $s = self::src('Entity/ProcessingActivity.php');
        self::assertStringContainsString('private bool $isProcessor = false;', $s);
        self::assertStringContainsString('private ?string $processorClientController = null;', $s);
        self::assertStringContainsString('public function isProcessor(): bool', $s);
        self::assertStringContainsString('public function getProcessorClientController(): ?string', $s);
    }

    #[Test]
    public function validatorRequiresClientWhenProcessor(): void
    {
        $s = self::src('Entity/ProcessingActivity.php');
        self::assertMatchesRegularExpression(
            "/#\[Assert\\\\Callback\]\s*\n\s*public\s+function\s+validateProcessorClientController\s*\(/",
            $s,
        );
        self::assertStringContainsString('$this->isProcessor &&', $s);
        self::assertStringContainsString("'processing_activity.validation.processor_client_controller_required'", $s);
    }

    #[Test]
    public function formExposesProcessorFields(): void
    {
        $s = self::src('Form/ProcessingActivityType.php');
        self::assertStringContainsString("->add('isProcessor', ChoiceType::class", $s);
        self::assertStringContainsString("->add('processorClientController', TextareaType::class", $s);
    }
}
