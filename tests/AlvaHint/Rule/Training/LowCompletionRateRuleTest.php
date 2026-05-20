<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Training;

use App\AlvaHint\Rule\Training\LowCompletionRateRule;
use App\Entity\Training;
use App\Entity\TrainingParticipation;
use App\Entity\User;
use App\Repository\TrainingParticipationRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class LowCompletionRateRuleTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    private function buildParticipation(string $status): TrainingParticipation
    {
        $p = $this->createMock(TrainingParticipation::class);
        $p->method('getStatus')->willReturn($status);
        return $p;
    }

    #[Test]
    public function appliesForMandatoryTrainingWithLowCompletionRate(): void
    {
        $training = $this->createMock(Training::class);
        $training->method('isMandatory')->willReturn(true);

        $participations = [
            $this->buildParticipation(TrainingParticipation::STATUS_COMPLETED),
            $this->buildParticipation(TrainingParticipation::STATUS_PENDING),
            $this->buildParticipation(TrainingParticipation::STATUS_PENDING),
            $this->buildParticipation(TrainingParticipation::STATUS_PENDING),
        ];

        $repo = $this->createMock(TrainingParticipationRepository::class);
        $repo->method('findByTraining')->willReturn($participations);

        $rule = new LowCompletionRateRule($repo);
        $this->assertTrue($rule->appliesTo($training, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenCompletionRateIsHigh(): void
    {
        $training = $this->createMock(Training::class);
        $training->method('isMandatory')->willReturn(true);

        $participations = [
            $this->buildParticipation(TrainingParticipation::STATUS_COMPLETED),
            $this->buildParticipation(TrainingParticipation::STATUS_COMPLETED),
            $this->buildParticipation(TrainingParticipation::STATUS_COMPLETED),
            $this->buildParticipation(TrainingParticipation::STATUS_PENDING),
        ];

        $repo = $this->createMock(TrainingParticipationRepository::class);
        $repo->method('findByTraining')->willReturn($participations);

        $rule = new LowCompletionRateRule($repo);
        $this->assertFalse($rule->appliesTo($training, $this->user));
    }

    #[Test]
    public function doesNotApplyForNonMandatoryTraining(): void
    {
        $training = $this->createMock(Training::class);
        $training->method('isMandatory')->willReturn(false);

        $repo = $this->createMock(TrainingParticipationRepository::class);
        $rule = new LowCompletionRateRule($repo);
        $this->assertFalse($rule->appliesTo($training, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenTooFewParticipants(): void
    {
        $training = $this->createMock(Training::class);
        $training->method('isMandatory')->willReturn(true);

        $repo = $this->createMock(TrainingParticipationRepository::class);
        $repo->method('findByTraining')->willReturn([
            $this->buildParticipation(TrainingParticipation::STATUS_PENDING),
        ]);

        $rule = new LowCompletionRateRule($repo);
        $this->assertFalse($rule->appliesTo($training, $this->user));
    }

    #[Test]
    public function doesNotApplyToNonTrainingEntity(): void
    {
        $repo = $this->createMock(TrainingParticipationRepository::class);
        $rule = new LowCompletionRateRule($repo);
        $this->assertFalse($rule->appliesTo(new \stdClass(), $this->user));
    }

    #[Test]
    public function buildReturnsHintWithCorrectKey(): void
    {
        $training = $this->createMock(Training::class);
        $training->method('getId')->willReturn(6);
        $training->method('isMandatory')->willReturn(true);

        $repo = $this->createMock(TrainingParticipationRepository::class);
        $repo->method('findByTraining')->willReturn([
            $this->buildParticipation(TrainingParticipation::STATUS_PENDING),
            $this->buildParticipation(TrainingParticipation::STATUS_PENDING),
            $this->buildParticipation(TrainingParticipation::STATUS_PENDING),
        ]);

        $rule = new LowCompletionRateRule($repo);
        $hint = $rule->build($training, $this->user);
        $this->assertSame('training.low_completion_rate', $hint->key);
        $this->assertSame(2, $hint->priorityTier);
    }

    #[Test]
    public function moduleGateIsTraining(): void
    {
        $repo = $this->createMock(TrainingParticipationRepository::class);
        $rule = new LowCompletionRateRule($repo);
        $this->assertSame(['training'], $rule->requiredModules());
    }
}
