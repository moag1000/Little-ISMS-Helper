<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\DataBreach;
use App\Entity\Incident;
use App\Entity\Notification\SlaDeadlineMonitor;
use App\Entity\Tenant;
use App\Enum\SlaDeadlineStatus;
use App\Repository\Notification\SlaDeadlineMonitorRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Statutory reports of incidents and data breaches (NIS-2 Art. 23, DORA
 * Art. 19, GDPR Art. 33) that are not made yet — one item per report, read
 * from the SLA monitors the ReportingPlanner keeps. They reach whoever is
 * responsible for the incident (owner, otherwise ISB/CISO) or the breach
 * (assessor/DPO), in My Day and as an immediate mail: these deadlines are
 * counted in hours. The ISB/CISO is always informed as well.
 */
final class ReportingDutySource extends EntityDeadlineSource
{
    /** German duty names (the digest and My Day speak German first; titles are data). */
    private const array NAMES = [
        'nis2_24h' => 'NIS-2-Frühwarnung (24 h)',
        'nis2_72h' => 'NIS-2-Meldung (72 h)',
        'nis2_1mo' => 'NIS-2-Abschlussbericht (1 Monat)',
        'dora_4h' => 'DORA-Erstmeldung',
        'dora_72h' => 'DORA-Zwischenbericht (72 h)',
        'dora_1mo' => 'DORA-Abschlussbericht (1 Monat)',
        'gdpr_72h' => 'DSGVO-Meldung an die Aufsicht (72 h)',
    ];

    /** Monitor entity types whose clocks are statutory reports. */
    public const array ENTITY_TYPES = ['Incident', 'DataBreach'];

    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly SlaDeadlineMonitorRepository $monitors,
    ) {
        parent::__construct($entityManager);
    }

    protected function entityClass(): string
    {
        return SlaDeadlineMonitor::class;
    }

    public function slug(): string
    {
        return 'reporting_duty';
    }

    public function label(): string
    {
        return 'Meldepflicht';
    }

    public function findOpen(Tenant $tenant): iterable
    {
        foreach ($this->monitors->findOpenReporting($tenant) as $monitor) {
            if ($this->recordOf($monitor) !== null) {
                yield $monitor;
            }
        }
    }

    public function dueDateOf(object $item): DateTimeInterface
    {
        assert($item instanceof SlaDeadlineMonitor);

        return $item->getDeadlineAt();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof SlaDeadlineMonitor);
        $record = $this->recordOf($item);
        $name = self::NAMES[$item->getDeadlineType()->value] ?? $item->getDeadlineType()->label();

        return sprintf('%s: %s', $name, $record?->getTitle() ?? '#' . $item->getEntityId());
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof SlaDeadlineMonitor);

        return $item->getSatisfiedAt() !== null
            || in_array($item->getStatus(), [SlaDeadlineStatus::Satisfied, SlaDeadlineStatus::Dismissed], true);
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof SlaDeadlineMonitor);
        $record = $this->recordOf($item);

        // The security officer hears of every statutory report, also when the
        // incident has its own owner (the authority asks the ISB first).
        return $record === null
            ? Responsibility::roles('ROLE_ISB', 'ROLE_CISO')
            : Responsibility::ofRecord($record)->alsoInform('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof SlaDeadlineMonitor);

        return $item->getEntityType() === 'DataBreach'
            ? new RecordLink('app_data_breach_show', ['id' => $item->getEntityId()])
            : new RecordLink('app_incident_show', ['id' => $item->getEntityId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(
            bucket: 'reporting_duties',
            leadDays: null,
            normReference: 'NIS-2 Art. 23 / DORA Art. 19 / DSGVO Art. 33',
            urgentHours: 72,
        );
    }

    private function recordOf(SlaDeadlineMonitor $monitor): Incident|DataBreach|null
    {
        $class = match ($monitor->getEntityType()) {
            'Incident' => Incident::class,
            'DataBreach' => DataBreach::class,
            default => null,
        };

        return $class === null ? null : $this->entityManager->find($class, $monitor->getEntityId());
    }
}
