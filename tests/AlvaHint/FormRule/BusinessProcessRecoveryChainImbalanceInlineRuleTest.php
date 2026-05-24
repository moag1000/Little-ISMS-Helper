<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\BusinessProcessRecoveryChainImbalanceInlineRule;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BusinessProcessRecoveryChainImbalanceInlineRuleTest extends TestCase
{
    private BusinessProcessRecoveryChainImbalanceInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new BusinessProcessRecoveryChainImbalanceInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsWhenRpoGreaterThanHalfRto(): void
    {
        // RPO 3h, RTO 4h → 3*2 = 6 > 4 → fires.
        self::assertTrue($this->rule->supports(['rpo' => 3, 'rto' => 4], $this->user));
        // RPO 5h, RTO 8h → 5*2 = 10 > 8 → fires.
        self::assertTrue($this->rule->supports(['rpo' => 5, 'rto' => 8], $this->user));
    }

    #[Test]
    public function supportsAcceptsStringNumerics(): void
    {
        self::assertTrue($this->rule->supports(['rpo' => '3', 'rto' => '4'], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenRpoIsExactlyHalfRto(): void
    {
        // RPO 2h, RTO 4h → 2*2 = 4 NOT > 4 → silent (boundary).
        self::assertFalse($this->rule->supports(['rpo' => 2, 'rto' => 4], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenFieldsMissingOrZero(): void
    {
        self::assertFalse($this->rule->supports([], $this->user));
        self::assertFalse($this->rule->supports(['rpo' => 0, 'rto' => 4], $this->user));
        self::assertFalse($this->rule->supports(['rpo' => 3, 'rto' => 0], $this->user));
        self::assertFalse($this->rule->supports(['rpo' => 3], $this->user));
    }

    #[Test]
    public function evaluateAnchorsOnRpoFieldAndExposesScoresAsParams(): void
    {
        $hint = $this->rule->evaluate(['rpo' => 5, 'rto' => 8], $this->user);

        self::assertSame('business_process.form.rpo_exceeds_rto_half', $hint->key);
        self::assertSame('rpo', $hint->field);
        self::assertSame(['%rpo%' => 5, '%rto%' => 8], $hint->bodyParams);
    }

    #[Test]
    public function entityTypeAndModulesAreCorrect(): void
    {
        self::assertSame('business_process', $this->rule->entityType());
        self::assertSame(['bcm'], $this->rule->requiredModules());
    }
}
