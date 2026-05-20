<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Incident\HighSeverityOpenTooLongRule;
use App\Entity\Incident;
use App\Entity\User;
use App\Enum\IncidentSeverity;
use App\Enum\IncidentStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class HighSeverityOpenTooLongRuleTest extends TestCase
{
    private HighSeverityOpenTooLongRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new HighSeverityOpenTooLongRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesForCriticalOpenIncidentBeyondSla(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getSeverity')->willReturn(IncidentSeverity::Critical);
        $incident->method('getStatus')->willReturn(IncidentStatus::InInvestigation);
        $incident->method('getDetectedAt')->willReturn(new DateTimeImmutable('-72 hours -1 minute'));

        $this->assertTrue($this->rule->appliesTo($incident, $this->user));
    }

    #[Test]
    public function doesNotApplyForResolvedIncident(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getSeverity')->willReturn(IncidentSeverity::Critical);
        $incident->method('getStatus')->willReturn(IncidentStatus::Resolved);

        $this->assertFalse($this->rule->appliesTo($incident, $this->user));
    }

    #[Test]
    public function doesNotApplyForLowSeverity(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getSeverity')->willReturn(IncidentSeverity::Low);
        $incident->method('getStatus')->willReturn(IncidentStatus::Reported);

        $this->assertFalse($this->rule->appliesTo($incident, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenWithinSla(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getSeverity')->willReturn(IncidentSeverity::High);
        $incident->method('getStatus')->willReturn(IncidentStatus::Reported);
        $incident->method('getDetectedAt')->willReturn(new DateTimeImmutable('-10 hours'));

        $this->assertFalse($this->rule->appliesTo($incident, $this->user));
    }

    #[Test]
    public function doesNotApplyToNonIncidentEntity(): void
    {
        $this->assertFalse($this->rule->appliesTo(new \stdClass(), $this->user));
    }

    #[Test]
    public function buildReturnsNonDismissibleTier1Hint(): void
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getId')->willReturn(5);
        $incident->method('getDetectedAt')->willReturn(new DateTimeImmutable('-100 hours'));

        $hint = $this->rule->build($incident, $this->user);
        $this->assertSame('incident.high_severity_open_too_long', $hint->key);
        $this->assertSame(1, $hint->priorityTier);
        $this->assertFalse($hint->dismissible);
        $this->assertSame('danger', $hint->variant);
    }

    #[Test]
    public function moduleGateIsIncidents(): void
    {
        $this->assertSame(['incidents'], $this->rule->requiredModules());
    }
}
