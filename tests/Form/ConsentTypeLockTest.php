<?php

declare(strict_types=1);

namespace App\Tests\Form;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * N-1 (GDPR Art. 7(1)): a verified consent's wording + purposes must be locked
 * against in-place overwrite. Source-inspection pattern — robust under the
 * shared-vendor multi-worktree setup (mirrors ProcessingActivityTypeTest).
 */
final class ConsentTypeLockTest extends TestCase
{
    private static function source(): string
    {
        $file = __DIR__ . '/../../src/Form/ConsentType.php';
        self::assertFileExists($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    #[Test]
    public function locksProofFieldsViaPreSetDataWhenVerified(): void
    {
        $source = self::source();

        self::assertStringContainsString('FormEvents::PRE_SET_DATA', $source);
        self::assertStringContainsString('$consent->getVerifiedAt() !== null', $source);
        self::assertStringContainsString("\$form->add('purposes', ChoiceType::class, \$this->purposesOptions(true))", $source);
        self::assertStringContainsString("\$form->add('consentText', TextareaType::class, \$this->consentTextOptions(true))", $source);
    }

    #[Test]
    public function optionHelpersToggleDisabledFlag(): void
    {
        $source = self::source();

        self::assertStringContainsString('private function purposesOptions(bool $locked = false): array', $source);
        self::assertStringContainsString('private function consentTextOptions(bool $locked = false): array', $source);
        self::assertStringContainsString("'disabled' => \$locked,", $source);
        self::assertStringContainsString("'consent.form.locked_after_verification'", $source);
    }
}
