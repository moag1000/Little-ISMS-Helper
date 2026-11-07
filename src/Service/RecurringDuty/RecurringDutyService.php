<?php

declare(strict_types=1);

namespace App\Service\RecurringDuty;

use App\Entity\ISMSObjective;
use App\Entity\RecurringDuty;
use App\Entity\Tenant;
use App\Repository\RecurringDutyRepository;
use App\Service\ModuleConfigurationService;
use App\Util\Cadence;
use App\Util\LegalPeriod;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The organisation's recurring duties: seeds a row for every catalogue duty
 * whose module (and NIS-2/DORA scope) applies, the first time it is asked,
 * and hides rows whose module was switched off — nothing is deleted, so the
 * history survives a module being switched off and on again.
 */
class RecurringDutyService
{
    /** First due date of a duty nobody has recorded yet: one month to catch up. */
    public const int FIRST_DUE_MONTHS = 1;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RecurringDutyRepository $repository,
        private readonly ModuleConfigurationService $modules,
    ) {
    }

    /** @return list<RecurringDuty> the duties that apply to the organisation, earliest due first */
    public function applicable(Tenant $tenant): array
    {
        $rows = $this->repository->findByTenantKeyed($tenant);
        if ($this->seedMissing($tenant, $rows)) {
            $rows = $this->repository->findByTenantKeyed($tenant);
        }

        $duties = array_values(array_filter($rows, fn (RecurringDuty $d): bool => $this->applies($d->getDutyKey(), $tenant)));
        usort($duties, static fn (RecurringDuty $a, RecurringDuty $b): int => $a->getNextDueAt() <=> $b->getNextDueAt());

        return $duties;
    }

    public function applies(string $key, Tenant $tenant): bool
    {
        $duty = RecurringDutyCatalog::get($key);

        return $duty !== null
            && RecurringDutyCatalog::inScope($key, $tenant)
            && $this->modules->isModuleActiveForTenant($duty['module'], $tenant);
    }

    /** Default interval: the norm's, for objectives the shortest measurement cadence on record. */
    public function defaultInterval(string $key, Tenant $tenant): int
    {
        $months = RecurringDutyCatalog::get($key)['months'] ?? 12;
        if ($key !== 'objectives_measurement') {
            return $months;
        }

        $cadences = [];
        foreach ($this->entityManager->getRepository(ISMSObjective::class)->findBy(['tenant' => $tenant]) as $objective) {
            if (in_array($objective->getStatus(), ['not_started', 'in_progress', 'delayed'], true)) {
                $cadences[] = Cadence::months($objective->getMeasurementFrequency());
            }
        }
        $cadences = array_filter($cadences);

        return $cadences === [] ? $months : min($cadences);
    }

    /**
     * @param array<string, RecurringDuty> $existing
     * @return bool whether a row was added
     */
    private function seedMissing(Tenant $tenant, array $existing): bool
    {
        $added = false;
        $firstDue = LegalPeriod::addMonths(new DateTimeImmutable('today'), self::FIRST_DUE_MONTHS);
        foreach (array_keys(RecurringDutyCatalog::DUTIES) as $key) {
            if (isset($existing[$key]) || !$this->applies($key, $tenant)) {
                continue;
            }
            try {
                // Plain insert outside the unit of work: two requests seeding at
                // once hit the unique key, and a failed flush would close the
                // EntityManager of the request.
                $this->entityManager->getConnection()->insert('recurring_duty', [
                    'tenant_id' => $tenant->getId(),
                    'duty_key' => $key,
                    'interval_months' => $this->defaultInterval($key, $tenant),
                    'next_due_at' => $firstDue,
                    'created_at' => new DateTimeImmutable(),
                ], [
                    'next_due_at' => Types::DATE_IMMUTABLE,
                    'created_at' => Types::DATETIME_IMMUTABLE,
                ]);
                $added = true;
            } catch (UniqueConstraintViolationException) {
                // seeded by a parallel request
            }
        }

        return $added;
    }
}
