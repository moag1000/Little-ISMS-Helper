<?php

declare(strict_types=1);

namespace App\Tests\Service\Incident;

use App\Entity\BusinessProcess;
use App\Entity\Incident;
use App\Entity\Tenant;
use App\Enum\IncidentSeverity;
use App\Enum\SlaDeadlineType;
use App\Service\Incident\Nis2SignificanceCriteria;
use App\Service\Incident\ReportingPlanner;
use App\Service\ModuleConfigurationService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one answer to "which reports does this incident owe, by when"
 * (NIS-2 Art. 23, DORA Art. 19) and the one definition of "reportable".
 */
final class ReportingPlannerTest extends TestCase
{
    #[Test]
    public function preCheckNamesItsReasonsAndTheEntityDecides(): void
    {
        $incident = $this->incident();
        self::assertSame([], $incident->getNis2SignificanceReasons());
        self::assertFalse($incident->requiresNis2Reporting());

        $incident->setSeverity(IncidentSeverity::Critical);
        $incident->setCrossBorderImpact(true);
        $incident->setAffectedUsersCount(1); // one affected user alone is not significant
        self::assertSame(['severity', 'cross_border'], $incident->getNis2SignificanceReasons());
        self::assertTrue($incident->requiresNis2Reporting());
        self::assertFalse($incident->isNis2AssessmentConfirmed());

        $incident->setNotificationRequired(false);
        self::assertFalse($incident->requiresNis2Reporting(), 'the entity assessment overrides the pre-check');
        self::assertSame([], $this->planner()->forIncident($incident));
    }

    #[Test]
    public function criticalProcessThresholdsAndMaliceAreCriteria(): void
    {
        $incident = $this->incident();
        $process = (new BusinessProcess())->setCriticality('critical');
        $incident->addAffectedBusinessProcess($process);
        $incident->setAffectedUsersCount(Incident::NIS2_AFFECTED_PERSONS_THRESHOLD);
        $incident->setEstimatedFinancialImpact('500000.00');
        $incident->setNis2SuspectedMalicious(true);

        self::assertSame(['critical_process', 'affected_persons', 'financial_loss', 'malicious'], $incident->getNis2SignificanceReasons());
    }

    #[Test]
    public function notRegulatedOrganisationsAndInactiveModuleOweNoNis2Report(): void
    {
        $incident = $this->incident();
        $incident->setNotificationRequired(true);
        self::assertCount(3, $this->planner()->forIncident($incident));
        self::assertSame([], $this->planner(active: false)->forIncident($incident));

        $incident->getTenant()?->setNis2Classification(Tenant::NIS2_NOT_REGULATED);
        self::assertSame([], $this->planner()->forIncident($incident));
    }

    #[Test]
    public function doraReportsOnlyForMajorIncidentsOfFinancialEntities(): void
    {
        $incident = $this->incident();
        $incident->setIctIncidentClassification(ReportingPlanner::DORA_MAJOR);
        self::assertSame([], $this->planner()->forIncident($incident), 'not a financial entity');

        $incident->getTenant()?->setDoraEntityCategory(Tenant::DORA_FINANCIAL_ENTITY);
        $incident->setInitialReportSubmittedAt(new DateTimeImmutable('2026-05-01 11:00'));
        $duties = $this->planner()->forIncident($incident);

        self::assertSame(
            [SlaDeadlineType::DoraInitialNotification4h, SlaDeadlineType::DoraIntermediate72h, SlaDeadlineType::DoraFinalReport1mo],
            array_map(static fn ($d) => $d->type, $duties),
        );
        self::assertSame('2026-05-02 08:00', $duties[0]->deadline->format('Y-m-d H:i'), '24 h at the latest');
        self::assertSame('2026-05-04 11:00', $duties[1]->deadline->format('Y-m-d H:i'), '72 h after the actual initial report');
        self::assertSame('2026-06-04 11:00', $duties[2]->deadline->format('Y-m-d H:i'), 'one month after the intermediate deadline');
        self::assertTrue($duties[0]->isReportedInTime());
    }

    #[Test]
    public function theIntermediateReportIsADutyOnlyOnRequest(): void
    {
        $incident = $this->incident();
        $incident->setNotificationRequired(true);
        self::assertNull($this->planner()->duty($incident, SlaDeadlineType::Nis2InterimOnRequest));

        $incident->setNis2InterimRequestedAt(new DateTimeImmutable('2026-05-03 10:00'));
        $duty = $this->planner()->duty($incident, SlaDeadlineType::Nis2InterimOnRequest);
        self::assertNotNull($duty);
        self::assertSame('2026-05-06 10:00', $duty->deadline->format('Y-m-d H:i'), '72 h without a date in the request');
        self::assertSame('nis2InterimReportedAt', $duty->field);

        $incident->setNis2InterimDueAt(new DateTimeImmutable('2026-05-04 12:00'));
        self::assertSame('2026-05-04 12:00', $this->planner()->duty($incident, SlaDeadlineType::Nis2InterimOnRequest)?->deadline->format('Y-m-d H:i'));
    }

    #[Test]
    public function theOrganisationSetsTheSignificanceThresholds(): void
    {
        $incident = $this->incident();
        $incident->setAffectedUsersCount(40);
        $incident->setEstimatedFinancialImpact('80000.00');
        self::assertSame([], $incident->getNis2SignificanceReasons());

        $tenant = $incident->getTenant();
        self::assertNotNull($tenant);
        Nis2SignificanceCriteria::set($tenant, 50000, 30);
        self::assertSame(['affected_persons', 'financial_loss'], $incident->getNis2SignificanceReasons());
        self::assertSame(30, Nis2SignificanceCriteria::affectedPersons($tenant));
        self::assertSame(Nis2SignificanceCriteria::DEFAULT_AFFECTED_PERSONS, Nis2SignificanceCriteria::affectedPersons(null));
    }

    private function incident(): Incident
    {
        $incident = new Incident();
        $incident->setTenant(new Tenant());
        $incident->setSeverity(IncidentSeverity::Low);
        $incident->setDetectedAt(new DateTimeImmutable('2026-05-01 08:00'));

        return $incident;
    }

    private function planner(bool $active = true): ReportingPlanner
    {
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActiveForTenant')->willReturn($active);

        return new ReportingPlanner(static fn (): ModuleConfigurationService => $modules);
    }
}
