<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Training;
use App\Form\TrainingType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Coverage for S4 P-1 + P-15 TrainingType rollout:
 *
 * P-1 Wave-2 — trainer OwnerPicker compound slot via OwnerPickerFormTrait
 * (User + Person + Deputies + Legacy free-text Migration-Hint).
 *
 * P-15 DataReuse — participantUsers Multi-Select replaces the free-text
 * participants textarea on the canonical data-path; legacy `participants`
 * textarea remains as migration display.
 *
 * Structural source-inspection tests (P-1) match the
 * ProcessingActivityTypeTest pattern; behavioural tests (P-15) use
 * FormFactory via KernelTestCase.
 */
final class TrainingTypeTest extends KernelTestCase
{
    private FormFactoryInterface $formFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->formFactory = static::getContainer()->get(FormFactoryInterface::class);
    }

    private static function getFormTypeSource(): string
    {
        $file = __DIR__ . '/../../src/Form/TrainingType.php';
        self::assertFileExists($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    // ---------- P-1 OwnerPicker structural tests ----------

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

    // ---------- P-15 DataReuse behavioural tests ----------

    #[Test]
    public function participantUsersMultiSelectExists(): void
    {
        $form = $this->formFactory->create(TrainingType::class, new Training());

        self::assertTrue($form->has('participantUsers'), 'P-15: typed participantUsers must be present');
        $cfg = $form->get('participantUsers')->getConfig();
        self::assertTrue($cfg->getOption('multiple'), 'participantUsers must be a multi-select');
        self::assertFalse((bool) $cfg->getOption('by_reference'), 'by_reference=false so collection setter fires');
    }

    #[Test]
    public function legacyParticipantsTextareaStillPresent(): void
    {
        $form = $this->formFactory->create(TrainingType::class, new Training());

        self::assertTrue($form->has('participants'), 'Legacy participants textarea must remain (migration display)');
    }

    #[Test]
    public function structuredTrainerSlotsRemainIntact(): void
    {
        $form = $this->formFactory->create(TrainingType::class, new Training());

        // Existing trainer-Pattern-A slots must not be regressed by P-15.
        self::assertTrue($form->has('trainerUser'));
        self::assertTrue($form->has('trainerPerson'));
        self::assertTrue($form->has('trainerDeputyPersons'));
    }
}
