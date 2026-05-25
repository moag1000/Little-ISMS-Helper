<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\AuditFinding\NcWithoutRootCauseRule;
use App\Entity\AuditFinding;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * S17 B4 — Tests for NcWithoutRootCauseRule.
 * Rule fires for NCs older than 30 days without a ncRootCauseSummary.
 */
final class NcWithoutRootCauseRuleTest extends TestCase
{
    private NcWithoutRootCauseRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new NcWithoutRootCauseRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesForOldMajorNcWithoutSummary(): void
    {
        $finding = $this->build(AuditFinding::TYPE_MAJOR_NC, null, '-45 days');
        self::assertTrue($this->rule->appliesTo($finding, $this->user));
    }

    #[Test]
    public function appliesForOldMinorNcWithoutSummary(): void
    {
        $finding = $this->build(AuditFinding::TYPE_MINOR_NC, null, '-31 days');
        self::assertTrue($this->rule->appliesTo($finding, $this->user));
    }

    #[Test]
    public function doesNotApplyForObservation(): void
    {
        $finding = $this->build(AuditFinding::TYPE_OBSERVATION, null, '-90 days');
        self::assertFalse($this->rule->appliesTo($finding, $this->user));
    }

    #[Test]
    public function doesNotApplyForOpportunity(): void
    {
        $finding = $this->build(AuditFinding::TYPE_OPPORTUNITY, null, '-90 days');
        self::assertFalse($this->rule->appliesTo($finding, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenSummaryPresent(): void
    {
        $finding = $this->build(AuditFinding::TYPE_MAJOR_NC, '5-Why: missing review gate.', '-90 days');
        self::assertFalse($this->rule->appliesTo($finding, $this->user));
    }

    #[Test]
    public function doesNotApplyForRecentNc(): void
    {
        $finding = $this->build(AuditFinding::TYPE_MAJOR_NC, null, '-3 days');
        self::assertFalse($this->rule->appliesTo($finding, $this->user));
    }

    #[Test]
    public function keyAndModuleAreCorrect(): void
    {
        self::assertSame('audit_finding.nc_without_root_cause', $this->rule->key());
        self::assertSame(['audits'], $this->rule->requiredModules());
        self::assertSame(2, $this->rule->priorityTier());
    }

    private function build(string $type, ?string $summary, string $createdRelative): AuditFinding
    {
        $finding = new AuditFinding();
        $finding->setType($type);
        $finding->setNcRootCauseSummary($summary);
        // Override the createdAt set in the constructor via reflection.
        $ref = new \ReflectionClass($finding);
        $prop = $ref->getProperty('createdAt');
        $prop->setValue($finding, new DateTimeImmutable($createdRelative));
        return $finding;
    }
}
