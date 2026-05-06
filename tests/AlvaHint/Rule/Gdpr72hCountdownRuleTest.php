<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\DataBreach\Gdpr72hCountdownRule;
use App\Entity\DataBreach;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class Gdpr72hCountdownRuleTest extends TestCase
{
    private Gdpr72hCountdownRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new Gdpr72hCountdownRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesWithinSeventyTwoHourWindow(): void
    {
        $breach = $this->buildBreach('high', new DateTimeImmutable('-12 hours'));

        $this->assertTrue($this->rule->appliesTo($breach, $this->user));
    }

    #[Test]
    public function doesNotApplyAfterDeadline(): void
    {
        $breach = $this->buildBreach('high', new DateTimeImmutable('-80 hours'));

        $this->assertFalse($this->rule->appliesTo($breach, $this->user));
    }

    #[Test]
    public function doesNotApplyOnceAuthorityNotified(): void
    {
        $breach = $this->buildBreach('critical', new DateTimeImmutable('-1 hour'));
        $breach->setSupervisoryAuthorityNotifiedAt(new DateTimeImmutable());

        $this->assertFalse($this->rule->appliesTo($breach, $this->user));
    }

    #[Test]
    public function doesNotApplyForLowSeverity(): void
    {
        $breach = $this->buildBreach('low', new DateTimeImmutable('-1 hour'));

        $this->assertFalse($this->rule->appliesTo($breach, $this->user));
    }

    #[Test]
    public function buildEmitsTier1NonDismissible(): void
    {
        $breach = $this->buildBreach('high', new DateTimeImmutable('-1 hour'));
        $hint = $this->rule->build($breach, $this->user);

        $this->assertSame(1, $hint->priorityTier);
        $this->assertFalse($hint->dismissible);
        $this->assertSame('DataBreach', $hint->entityType);
    }

    private function buildBreach(string $severity, DateTimeImmutable $detected): DataBreach
    {
        $breach = new DataBreach();
        $breach->setSeverity($severity);
        $breach->setDetectedAt($detected);

        return $breach;
    }
}
