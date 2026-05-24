<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\TrainingMandatoryNoAudienceInlineRule;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TrainingMandatoryNoAudienceInlineRuleTest extends TestCase
{
    private TrainingMandatoryNoAudienceInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new TrainingMandatoryNoAudienceInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsMandatoryWithNoAudienceAtAll(): void
    {
        self::assertTrue($this->rule->supports(['mandatory' => '1'], $this->user));
        self::assertTrue($this->rule->supports([
            'mandatory' => true,
            'targetAudience' => '',
            'participants' => '',
            'participantUsers' => [],
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenNotMandatory(): void
    {
        self::assertFalse($this->rule->supports([
            'mandatory' => '0',
            'targetAudience' => '',
        ], $this->user));
        self::assertFalse($this->rule->supports([], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenTargetAudienceFilled(): void
    {
        self::assertFalse($this->rule->supports([
            'mandatory' => '1',
            'targetAudience' => 'All developers',
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenParticipantsTextFilled(): void
    {
        self::assertFalse($this->rule->supports([
            'mandatory' => '1',
            'participants' => 'Alice, Bob',
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenParticipantUsersCollectionHasEntries(): void
    {
        self::assertFalse($this->rule->supports([
            'mandatory' => '1',
            'participantUsers' => ['42'],
        ], $this->user));
    }

    #[Test]
    public function evaluateAnchorsOnTargetAudienceField(): void
    {
        $hint = $this->rule->evaluate(['mandatory' => '1'], $this->user);

        self::assertSame('training.form.mandatory_without_audience', $hint->key);
        self::assertSame('targetAudience', $hint->field);
    }

    #[Test]
    public function entityTypeAndModulesAreCorrect(): void
    {
        self::assertSame('training', $this->rule->entityType());
        self::assertSame(['training'], $this->rule->requiredModules());
    }
}
