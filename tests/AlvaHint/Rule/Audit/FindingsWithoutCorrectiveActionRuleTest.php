<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Audit;

use App\AlvaHint\Rule\Audit\FindingsWithoutCorrectiveActionRule;
use App\Entity\AuditFinding;
use App\Entity\InternalAudit;
use App\Entity\User;
use App\Enum\AuditFindingStatus;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class FindingsWithoutCorrectiveActionRuleTest extends TestCase
{
    private FindingsWithoutCorrectiveActionRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new FindingsWithoutCorrectiveActionRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesWhenOpenFindingHasNoAssignee(): void
    {
        $finding = $this->createMock(AuditFinding::class);
        $finding->method('getStatusEnum')->willReturn(AuditFindingStatus::Open);
        $finding->method('getAssignedTo')->willReturn(null);
        $finding->method('getAssignedPerson')->willReturn(null);

        $audit = $this->createMock(InternalAudit::class);
        $audit->method('getStructuredFindings')->willReturn(new ArrayCollection([$finding]));

        $this->assertTrue($this->rule->appliesTo($audit, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenAllFindingsAreAssigned(): void
    {
        $user = new User();
        $finding = $this->createMock(AuditFinding::class);
        $finding->method('getStatusEnum')->willReturn(AuditFindingStatus::Open);
        $finding->method('getAssignedTo')->willReturn($user);
        $finding->method('getAssignedPerson')->willReturn(null);

        $audit = $this->createMock(InternalAudit::class);
        $audit->method('getStructuredFindings')->willReturn(new ArrayCollection([$finding]));

        $this->assertFalse($this->rule->appliesTo($audit, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenNoStructuredFindings(): void
    {
        $audit = $this->createMock(InternalAudit::class);
        $audit->method('getStructuredFindings')->willReturn(new ArrayCollection());

        $this->assertFalse($this->rule->appliesTo($audit, $this->user));
    }

    #[Test]
    public function doesNotApplyForClosedFindingsOnly(): void
    {
        $finding = $this->createMock(AuditFinding::class);
        $finding->method('getStatusEnum')->willReturn(AuditFindingStatus::Closed);

        $audit = $this->createMock(InternalAudit::class);
        $audit->method('getStructuredFindings')->willReturn(new ArrayCollection([$finding]));

        $this->assertFalse($this->rule->appliesTo($audit, $this->user));
    }

    #[Test]
    public function doesNotApplyToNonAuditEntity(): void
    {
        $this->assertFalse($this->rule->appliesTo(new \stdClass(), $this->user));
    }

    #[Test]
    public function buildReturnsHintWithCorrectKey(): void
    {
        $audit = $this->createMock(InternalAudit::class);
        $audit->method('getId')->willReturn(4);
        $audit->method('getStructuredFindings')->willReturn(new ArrayCollection());

        $hint = $this->rule->build($audit, $this->user);
        $this->assertSame('audit.findings_without_capa', $hint->key);
        $this->assertSame(2, $hint->priorityTier);
    }

    #[Test]
    public function moduleGateIsAudits(): void
    {
        $this->assertSame(['audits'], $this->rule->requiredModules());
    }
}
