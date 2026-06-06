<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification\Event;

use App\Entity\AuditFinding;
use App\Entity\Control;
use App\Entity\DataBreach;
use App\Entity\Document;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Enum\IncidentSeverity;
use App\Service\Notification\Event\DomainEventDetector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The detector turns entity creation/change into the notification domain events.
 * Transitions must fire ONCE (storm-avoidance) and only when crossing into the
 * triggering state.
 */
final class DomainEventDetectorTest extends TestCase
{
    private DomainEventDetector $detector;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->detector = new DomainEventDetector();
        $this->tenant = new Tenant();
    }

    /** @return string[] */
    private function types(array $events): array
    {
        return array_map(static fn ($e): string => $e->eventType, $events);
    }

    #[Test]
    public function noTenantYieldsNoEvents(): void
    {
        self::assertSame([], $this->detector->forInsert(new Incident()));
    }

    #[Test]
    public function newIncidentEmitsCreated(): void
    {
        $incident = (new Incident())->setTenant($this->tenant)->setSeverity(IncidentSeverity::Low);
        self::assertSame(['incident.created'], $this->types($this->detector->forInsert($incident)));
    }

    #[Test]
    public function newHighIncidentAlsoEmitsSeverityHigh(): void
    {
        $incident = (new Incident())->setTenant($this->tenant)->setSeverity(IncidentSeverity::Critical);
        self::assertSame(
            ['incident.created', 'incident.severity_high'],
            $this->types($this->detector->forInsert($incident)),
        );
    }

    #[Test]
    public function incidentSeverityTransitionIntoHighFiresOnce(): void
    {
        $incident = (new Incident())->setTenant($this->tenant)->setSeverity(IncidentSeverity::High);

        // medium → high → fires
        self::assertSame(
            ['incident.severity_high'],
            $this->types($this->detector->forUpdate($incident, ['severity' => [IncidentSeverity::Medium, IncidentSeverity::High]])),
        );
        // high → critical (already in trigger band) → does NOT re-fire
        self::assertSame(
            [],
            $this->types($this->detector->forUpdate($incident, ['severity' => [IncidentSeverity::High, IncidentSeverity::Critical]])),
        );
    }

    #[Test]
    public function newCriticalRiskEmitsCreatedAndScoreCritical(): void
    {
        $risk = (new Risk())->setTenant($this->tenant)->setProbability(5)->setImpact(5); // 25 >= 20
        self::assertSame(
            ['risk.created', 'risk.score_critical'],
            $this->types($this->detector->forInsert($risk)),
        );
    }

    #[Test]
    public function riskCrossingIntoCriticalFiresOnce(): void
    {
        // probability 5, impact changes 2→5 : old 10 (not critical) → new 25 (critical) → fires
        $risk = (new Risk())->setTenant($this->tenant)->setProbability(5)->setImpact(5);
        self::assertSame(
            ['risk.score_critical'],
            $this->types($this->detector->forUpdate($risk, ['impact' => [2, 5]])),
        );

        // probability 4→5, impact 5 : old 20 (already critical) → new 25 → no re-fire
        $risk2 = (new Risk())->setTenant($this->tenant)->setProbability(5)->setImpact(5);
        self::assertSame(
            [],
            $this->types($this->detector->forUpdate($risk2, ['probability' => [4, 5]])),
        );

        // change unrelated to score → no event
        self::assertSame([], $this->types($this->detector->forUpdate($risk, ['title' => ['a', 'b']])));
    }

    #[Test]
    public function dataBreachCreatedAndSeverityChanged(): void
    {
        $breach = (new DataBreach())->setTenant($this->tenant)->setSeverity('high');
        self::assertSame(['data_breach.created'], $this->types($this->detector->forInsert($breach)));
        self::assertSame(
            ['data_breach.severity_changed'],
            $this->types($this->detector->forUpdate($breach, ['severity' => ['medium', 'high']])),
        );
        self::assertSame([], $this->types($this->detector->forUpdate($breach, ['severity' => ['high', 'high']])));
    }

    #[Test]
    public function auditFindingCreated(): void
    {
        $finding = (new AuditFinding())->setTenant($this->tenant)->setSeverity('major');
        self::assertSame(['audit.finding_created'], $this->types($this->detector->forInsert($finding)));
    }

    #[Test]
    public function documentEnteringReviewRequiresApproval(): void
    {
        $doc = (new Document())->setTenant($this->tenant)->setStatus('in_review');
        self::assertSame(
            ['document.approval_required'],
            $this->types($this->detector->forUpdate($doc, ['status' => ['draft', 'in_review']])),
        );
        self::assertSame([], $this->types($this->detector->forUpdate($doc, ['status' => ['in_review', 'approved']])));
    }

    #[Test]
    public function controlEvidenceExpiredOnFlagFlip(): void
    {
        $control = (new Control())->setTenant($this->tenant)->setEvidenceOutdated(true);
        self::assertSame(
            ['control.evidence_expired'],
            $this->types($this->detector->forUpdate($control, ['evidenceOutdated' => [false, true]])),
        );
        // flag going back to false → no event
        self::assertSame([], $this->types($this->detector->forUpdate($control, ['evidenceOutdated' => [true, false]])));
    }
}
