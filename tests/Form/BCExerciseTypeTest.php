<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\BCExercise;
use App\Form\BCExerciseType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * S4 P-15 DataReuse — BCExerciseType form contract:
 *  - facilitator dual-state (User/Person/legacy text)
 *  - participantPersons + observerPersons typed Multi-Selects
 *  - All legacy free-text fields still present for migration display
 *  - Existing exerciseLeader{User,Person} (Phase B1) untouched
 */
final class BCExerciseTypeTest extends KernelTestCase
{
    private FormFactoryInterface $formFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->formFactory = static::getContainer()->get(FormFactoryInterface::class);
    }

    #[Test]
    public function facilitatorPatternASlotsExist(): void
    {
        $form = $this->formFactory->create(BCExerciseType::class, new BCExercise());

        self::assertTrue($form->has('facilitatorUser'), 'Pattern-A: facilitatorUser slot must exist');
        self::assertTrue($form->has('facilitatorPerson'), 'Pattern-A: facilitatorPerson slot must exist');
        self::assertTrue($form->has('facilitator'), 'Legacy facilitator text field must remain');
    }

    #[Test]
    public function participantPersonsMultiSelectExists(): void
    {
        $form = $this->formFactory->create(BCExerciseType::class, new BCExercise());

        self::assertTrue($form->has('participantPersons'));
        $cfg = $form->get('participantPersons')->getConfig();
        self::assertTrue($cfg->getOption('multiple'), 'participantPersons must be multi-select');
    }

    #[Test]
    public function observerPersonsMultiSelectExists(): void
    {
        $form = $this->formFactory->create(BCExerciseType::class, new BCExercise());

        self::assertTrue($form->has('observerPersons'));
        $cfg = $form->get('observerPersons')->getConfig();
        self::assertTrue($cfg->getOption('multiple'), 'observerPersons must be multi-select');
    }

    #[Test]
    public function legacyTextFieldsRemainForMigration(): void
    {
        $form = $this->formFactory->create(BCExerciseType::class, new BCExercise());

        self::assertTrue($form->has('participants'), 'Legacy participants textarea must remain');
        self::assertTrue($form->has('observers'), 'Legacy observers textarea must remain');
        self::assertTrue($form->has('facilitator'), 'Legacy facilitator text must remain');
    }

    #[Test]
    public function existingExerciseLeaderSlotsAreIntact(): void
    {
        $form = $this->formFactory->create(BCExerciseType::class, new BCExercise());

        // Phase-B1 fields must not be regressed by P-15
        self::assertTrue($form->has('exerciseLeaderUser'));
        self::assertTrue($form->has('exerciseLeaderPerson'));
    }

    #[Test]
    public function facilitatorLegacyIsNoLongerHardRequired(): void
    {
        $form = $this->formFactory->create(BCExerciseType::class, new BCExercise());

        $required = $form->get('facilitator')->getConfig()->getOption('required');
        self::assertFalse((bool) $required, 'P-15: legacy facilitator text is optional once Pattern-A typed slot is wired.');
    }
}
