<?php

declare(strict_types=1);

namespace App\Tests\Form;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for S4 P-1 Wave-2 — BusinessContinuityPlanType OwnerPicker rollout.
 *
 * Verifies that BusinessContinuityPlanType:
 *  1. Uses the OwnerPickerFormTrait.
 *  2. Wires the planOwner compound slot via addOwnerPicker().
 *  3. Preserves the validatePlanOwnerSlot validator.
 *  4. Removes the inline ->add() blocks for the 4 owner fields.
 *
 * Structural source-inspection pattern (matches ProcessingActivityTypeTest).
 */
final class BusinessContinuityPlanTypeTest extends TestCase
{
    private static function getFormTypeSource(): string
    {
        $file = __DIR__ . '/../../src/Form/BusinessContinuityPlanType.php';
        self::assertFileExists($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    #[Test]
    public function usesOwnerPickerFormTrait(): void
    {
        $source = self::getFormTypeSource();

        self::assertStringContainsString(
            'use OwnerPickerFormTrait;',
            $source,
            'BusinessContinuityPlanType must use OwnerPickerFormTrait.'
        );
        self::assertStringContainsString(
            'use App\\Form\\Trait\\OwnerPickerFormTrait;',
            $source,
            'BusinessContinuityPlanType must import App\\Form\\Trait\\OwnerPickerFormTrait.'
        );
    }

    #[Test]
    public function planOwnerSlotIsWiredViaAddOwnerPicker(): void
    {
        $source = self::getFormTypeSource();

        self::assertStringContainsString(
            "\$this->addOwnerPicker(\$builder, [",
            $source,
            'BusinessContinuityPlanType must wire planOwner slot via $this->addOwnerPicker().'
        );
        self::assertStringContainsString(
            "'user_field'         => 'planOwnerUser',",
            $source
        );
        self::assertStringContainsString(
            "'person_field'       => 'planOwnerPerson',",
            $source
        );
        self::assertStringContainsString(
            "'deputies_field'     => 'planOwnerDeputyPersons',",
            $source
        );
        self::assertStringContainsString(
            "'legacy_field'       => 'planOwner',",
            $source,
            'Legacy free-text planOwner must remain wired as read-only Migration-Hint.'
        );
    }

    #[Test]
    public function validatorEnforcesUserOrPersonForPlanOwnerSlot(): void
    {
        $source = self::getFormTypeSource();

        self::assertStringContainsString(
            'public function validatePlanOwnerSlot(',
            $source,
            'BusinessContinuityPlanType must retain the validatePlanOwnerSlot callback validator.'
        );
        self::assertStringContainsString(
            'bc_plans.error.owner_required_user_or_person',
            $source,
            'Validator must emit the canonical owner-required violation key.'
        );
    }

    #[Test]
    public function legacyInlineFieldsAreRemoved(): void
    {
        $source = self::getFormTypeSource();

        self::assertStringNotContainsString(
            "->add('planOwnerUser', EntityType::class",
            $source,
            'planOwnerUser must be wired exclusively via addOwnerPicker.'
        );
        self::assertStringNotContainsString(
            "->add('planOwnerDeputyPersons', EntityType::class",
            $source,
            'planOwnerDeputyPersons must be wired exclusively via addOwnerPicker.'
        );
        self::assertStringNotContainsString(
            "->add('planOwner', TextType::class",
            $source,
            'planOwner legacy field must be wired exclusively via addOwnerPicker.'
        );
    }
}
