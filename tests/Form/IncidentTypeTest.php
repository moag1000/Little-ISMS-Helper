<?php

declare(strict_types=1);

namespace App\Tests\Form;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for S4 P-1 Wave-2 — IncidentType OwnerPicker rollout.
 *
 * Verifies that IncidentType:
 *  1. Uses the OwnerPickerFormTrait (or otherwise wires the slot via addOwnerPicker).
 *  2. Defines the compound `reportedBy` slot (User + Person + Deputies + legacy text).
 *  3. Keeps the existing single-Person `responsiblePerson` governance slot.
 *  4. Preserves the validator that requires either user or person for reportedBy.
 *
 * Structural source-inspection pattern (same as ProcessingActivityTypeTest) —
 * IncidentType pulls in 10+ EntityType fields which would require a full
 * Doctrine mocking matrix for a behavioural test.
 */
final class IncidentTypeTest extends TestCase
{
    private static function getFormTypeSource(): string
    {
        $file = __DIR__ . '/../../src/Form/IncidentType.php';
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
            'IncidentType must use OwnerPickerFormTrait to wire the reportedBy compound slot.'
        );
        self::assertStringContainsString(
            'use App\\Form\\Trait\\OwnerPickerFormTrait;',
            $source,
            'IncidentType must import App\\Form\\Trait\\OwnerPickerFormTrait.'
        );
    }

    #[Test]
    public function reportedBySlotIsWiredViaAddOwnerPicker(): void
    {
        $source = self::getFormTypeSource();

        self::assertStringContainsString(
            "\$this->addOwnerPicker(\$builder, [",
            $source,
            'IncidentType must wire reportedBy slot via $this->addOwnerPicker().'
        );
        self::assertStringContainsString(
            "'field_prefix'       => 'reportedBy',",
            $source,
            'addOwnerPicker call must use field_prefix=reportedBy.'
        );
        self::assertStringContainsString(
            "'person_field'       => 'reportedByPerson',",
            $source,
            'Person slot must remain reportedByPerson.'
        );
        self::assertStringContainsString(
            "'deputies_field'     => 'reportedByDeputyPersons',",
            $source,
            'Deputies slot must remain reportedByDeputyPersons.'
        );
        self::assertStringContainsString(
            "'legacy_field'       => 'reportedBy',",
            $source,
            'Legacy free-text slot must remain `reportedBy`.'
        );
    }

    #[Test]
    public function responsiblePersonRemainsSingleGovernanceSlot(): void
    {
        $source = self::getFormTypeSource();

        // `responsiblePerson` is documented as a single-Person governance slot,
        // intentionally NOT compounded via OwnerPicker.
        self::assertMatchesRegularExpression(
            "/->add\(\s*'responsiblePerson'\s*,\s*EntityType::class/",
            $source,
            'IncidentType must keep responsiblePerson as a single EntityType(Person) governance slot.'
        );
    }

    #[Test]
    public function validatorEnforcesUserOrPersonForReportedBySlot(): void
    {
        $source = self::getFormTypeSource();

        self::assertStringContainsString(
            'public function validateReportedBySlot(',
            $source,
            'IncidentType must keep the validateReportedBySlot callback validator.'
        );
        self::assertStringContainsString(
            'incident.error.owner_required_user_or_person',
            $source,
            'Validator must emit the canonical owner-required violation key.'
        );
    }

    #[Test]
    public function legacyInlineFieldsAreRemoved(): void
    {
        $source = self::getFormTypeSource();

        // Sanity check: the per-field block we removed must not reappear.
        self::assertStringNotContainsString(
            "->add('reportedByUser', EntityType::class",
            $source,
            'reportedByUser should now be wired via addOwnerPicker, not as an inline ->add() call.'
        );
        self::assertStringNotContainsString(
            "->add('reportedByDeputyPersons', EntityType::class",
            $source,
            'reportedByDeputyPersons should now be wired via addOwnerPicker.'
        );
    }
}
