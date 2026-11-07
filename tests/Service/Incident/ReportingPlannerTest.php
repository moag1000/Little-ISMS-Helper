<?php

declare(strict_types=1);

namespace App\Tests\Service\Incident;

use App\Entity\BusinessProcess;
use App\Entity\Incident;
use App\Entity\Tenant;
use App\Enum\IncidentSeverity;
use App\Enum\SlaDeadlineType;
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
