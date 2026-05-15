<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Training\MandatoryTrainingWithoutAudienceRule;
use App\Entity\Training;
use App\Entity\TrainingParticipation;
use App\Entity\User;
use App\Repository\TrainingParticipationRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MandatoryTrainingWithoutAudienceRuleTest extends TestCase
{
    private TrainingParticipationRepository $repo;
    private MandatoryTrainingWithoutAudienceRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(TrainingParticipationRepository::class);
        $this->rule = new MandatoryTrainingWithoutAudienceRule($this->repo);
        $this->user = new User();
    }

    #[Test]
    public function appliesWhenMandatoryAndNoParticipationRowsYet(): void
    {
        $training = (new Training())->setMandatory(true);

        $this->repo->expects($this->once())
            ->method('findOneBy')
            ->with(['training' => $training])
            ->willReturn(null);

        $this->assertTrue($this->rule->appliesTo($training, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenNotMandatory(): void
    {
        $training = (new Training())->setMandatory(false);

        $this->assertFalse($this->rule->appliesTo($training, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenParticipationRowExists(): void
    {
        $training = (new Training())->setMandatory(true);
        $existing = new TrainingParticipation();

        $this->repo->expects($this->once())
            ->method('findOneBy')
            ->willReturn($existing);

        $this->assertFalse($this->rule->appliesTo($training, $this->user));
    }

    #[Test]
    public function buildEmitsTier2InfoHintWithAudiencePickerRoute(): void
    {
        $training = (new Training())->setMandatory(true);

        $hint = $this->rule->build($training, $this->user);

        $this->assertSame('training.mandatory_without_audience', $hint->key);
        $this->assertSame(2, $hint->priorityTier);
        $this->assertSame('info', $hint->variant);
        $this->assertSame('app_training_audience_picker', $hint->actionRoute);
        $this->assertSame('GET', $hint->actionMethod);
        $this->assertSame(['ROLE_MANAGER'], $hint->requiredRoles);
    }

    #[Test]
    public function isNotModuleGated(): void
    {
        // Awareness is ISO 27001 base; every tenant has it.
        $this->assertSame([], $this->rule->requiredModules());
    }
}
