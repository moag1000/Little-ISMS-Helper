<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\ProcessingActivity;

use App\AlvaHint\Rule\ProcessingActivity\PurposeLegalBasisMismatchRule;
use App\Entity\ProcessingActivity;
use App\Entity\User;
use App\Enum\ProcessingActivityStatus;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PurposeLegalBasisMismatchRuleTest extends TestCase
{
    private PurposeLegalBasisMismatchRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new PurposeLegalBasisMismatchRule();
        $this->user = new User();
    }

    private function activity(array $purposes, ?string $legalBasis, string $status = 'approved'): ProcessingActivity
    {
        $activity = $this->createMock(ProcessingActivity::class);
        $activity->method('getStatus')->willReturn($status);
        $activity->method('getPurposes')->willReturn($purposes);
        $activity->method('getLegalBasis')->willReturn($legalBasis);
        $activity->method('getId')->willReturn(5);

        return $activity;
    }

    #[Test]
    public function appliesWhenMarketingOnIncompatibleBasis(): void
    {
        $this->assertTrue($this->rule->appliesTo($this->activity(['marketing'], 'legal_obligation'), $this->user));
    }

    #[Test]
    public function doesNotApplyWhenMarketingOnConsent(): void
    {
        $this->assertFalse($this->rule->appliesTo($this->activity(['marketing'], 'consent'), $this->user));
    }

    #[Test]
    public function doesNotApplyWhenMarketingOnLegitimateInterests(): void
    {
        $this->assertFalse($this->rule->appliesTo($this->activity(['marketing'], 'legitimate_interests'), $this->user));
    }

    #[Test]
    public function doesNotApplyWithoutMarketingPurpose(): void
    {
        $this->assertFalse($this->rule->appliesTo($this->activity(['crm', 'accounting'], 'contract'), $this->user));
    }

    #[Test]
    public function doesNotApplyToDraft(): void
    {
        $this->assertFalse($this->rule->appliesTo(
            $this->activity(['marketing'], 'legal_obligation', ProcessingActivityStatus::Draft->value),
            $this->user,
        ));
    }

    #[Test]
    public function doesNotApplyToNonProcessingActivity(): void
    {
        $this->assertFalse($this->rule->appliesTo(new \stdClass(), $this->user));
    }

    #[Test]
    public function buildReturnsDismissibleTier2Hint(): void
    {
        $hint = $this->rule->build($this->activity(['marketing'], 'contract'), $this->user);

        $this->assertSame('processing_activity.purpose_legal_basis_mismatch', $hint->key);
        $this->assertSame(2, $hint->priorityTier);
        $this->assertTrue($hint->dismissible);
        $this->assertSame(['privacy'], $this->rule->requiredModules());
    }
}
