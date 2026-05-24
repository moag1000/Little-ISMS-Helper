<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\AuditFindingMajorNcRequiresCapaInlineRule;
use App\Entity\AuditFinding;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditFindingMajorNcRequiresCapaInlineRuleTest extends TestCase
{
    private AuditFindingMajorNcRequiresCapaInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new AuditFindingMajorNcRequiresCapaInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsMajorNcType(): void
    {
        self::assertTrue($this->rule->supports(['type' => AuditFinding::TYPE_MAJOR_NC], $this->user));
        self::assertTrue($this->rule->supports(['type' => 'major_nc'], $this->user));
    }

    #[Test]
    public function doesNotSupportMinorNcType(): void
    {
        self::assertFalse($this->rule->supports(['type' => 'minor_nc'], $this->user));
    }

    #[Test]
    public function doesNotSupportObservationOrOpportunity(): void
    {
        self::assertFalse($this->rule->supports(['type' => 'observation'], $this->user));
        self::assertFalse($this->rule->supports(['type' => 'opportunity'], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenTypeMissing(): void
    {
        self::assertFalse($this->rule->supports([], $this->user));
        self::assertFalse($this->rule->supports(['type' => ''], $this->user));
    }

    #[Test]
    public function evaluateAnchorsOnTypeField(): void
    {
        $hint = $this->rule->evaluate(['type' => 'major_nc'], $this->user);

        self::assertSame('audit_finding.form.major_nc_requires_capa', $hint->key);
        self::assertSame('type', $hint->field);
        self::assertSame('warning', $hint->tier);
    }

    #[Test]
    public function entityTypeAndModulesAreCorrect(): void
    {
        self::assertSame('audit_finding', $this->rule->entityType());
        self::assertSame(['audits'], $this->rule->requiredModules());
    }
}
