<?php

declare(strict_types=1);

namespace App\Tests\Form;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for S4 P-1 Wave-2 — TrainingType OwnerPicker rollout.
 *
 * Verifies that TrainingType wires the trainer compound slot via
 * OwnerPickerFormTrait + addOwnerPicker(), and retains its
 * validateTrainerSlot callback validator.
 *
 * Structural source-inspection pattern (matches ProcessingActivityTypeTest).
 */
final class TrainingTypeTest extends TestCase
{
    private static function getFormTypeSource(): string
    {
        $file = __DIR__ . '/../../src/Form/TrainingType.php';
        self::assertFileExists($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    #[Test]
    public function usesOwnerPickerFormTrait(): void
    {
        $source = self::getFormTypeSource();

        self::assertStringContainsString('use OwnerPickerFormTrait;', $source);
        self::assertStringContainsString(
            'use App\\Form\\Trait\\OwnerPickerFormTrait;',
            $source
        );
    }

    #[Test]
    public function trainerSlotIsWiredViaAddOwnerPicker(): void
    {
        $source = self::getFormTypeSource();

        self::assertStringContainsString("\$this->addOwnerPicker(\$builder, [", $source);
        self::assertStringContainsString("'field_prefix'       => 'trainer',", $source);
        self::assertStringContainsString("'user_field'         => 'trainerUser',", $source);
        self::assertStringContainsString("'person_field'       => 'trainerPerson',", $source);
        self::assertStringContainsString("'deputies_field'     => 'trainerDeputyPersons',", $source);
        self::assertStringContainsString(
            "'legacy_field'       => 'trainer',",
            $source,
            'Legacy free-text `trainer` must remain wired as read-only Migration-Hint.'
        );
    }

    #[Test]
    public function validatorEnforcesUserOrPersonForTrainerSlot(): void
    {
        $source = self::getFormTypeSource();

        self::assertStringContainsString(
            'public function validateTrainerSlot(',
            $source,
            'TrainingType must retain validateTrainerSlot callback validator.'
        );
        self::assertStringContainsString(
            'training.error.owner_required_user_or_person',
            $source,
            'Validator must emit the canonical owner-required violation key.'
        );
    }

    #[Test]
    public function legacyInlineFieldsAreRemoved(): void
    {
        $source = self::getFormTypeSource();

        self::assertStringNotContainsString(
            "->add('trainerUser', EntityType::class",
            $source,
            'trainerUser must be wired exclusively via addOwnerPicker.'
        );
        self::assertStringNotContainsString(
            "->add('trainerDeputyPersons', EntityType::class",
            $source,
            'trainerDeputyPersons must be wired exclusively via addOwnerPicker.'
        );
        self::assertStringNotContainsString(
            "->add('trainer', TextType::class",
            $source,
            'trainer legacy field must be wired exclusively via addOwnerPicker.'
        );
    }
}
