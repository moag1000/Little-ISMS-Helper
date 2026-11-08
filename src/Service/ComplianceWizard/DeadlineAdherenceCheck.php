<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard;

use App\Entity\ISMSObjective;
use App\Entity\Tenant;
use App\Service\Deadline\DeadlineSourceRegistry;
use Closure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * The `deadline_adherence` compliance-wizard check: are the organisation's
 * planned duties done on time? Reads the deadline sources My Day and the
 * reminder mails read ({@see \App\Service\Deadline\DeadlineSource}), so a
 * duty the tool knows is a duty this check weighs:
 *
 *  - ISO 27001 8.1 (operational planning and control): every active source,
 *    recurring duties included;
 *  - ISO 27001 9.1 (monitoring and measurement): objective measurement and
 *    control effectiveness tests (`sources`), objectives measured by a KPI
 *    count as on time (`kpi_objectives: true`), the tool measures them itself.
 *
 * Score = share of open duties not past their date. No duty at all is
 * "no data" — nothing is planned yet.
 */
final class DeadlineAdherenceCheck
{
    /**
     * @param Closure(): DeadlineSourceRegistry $registry lazy: sources reach the responsibility and fulfilment services
     */
    public function __construct(
        #[AutowireServiceClosure(DeadlineSourceRegistry::class)]
        private readonly Closure $registry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $check config keys `sources` (deadline slugs; empty = every active source), `kpi_objectives`
     * @return array{score: float, details: array<string, mixed>, gap: array<string, mixed>|null}
     */
    public function check(array $check, ?Tenant $tenant, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $wanted = array_values(array_filter((array) ($check['sources'] ?? []), 'is_string'));
        $total = 0;
        $overdue = 0;
        $bySource = [];
        if ($tenant instanceof Tenant) {
            foreach (($this->registry)()->active($tenant) as $source) {
                if ($wanted !== [] && !in_array($source->slug(), $wanted, true)) {
                    continue;
                }
                try {
                    foreach ($source->findOpen($tenant) as $item) {
                        $due = $source->dueDateOf($item);
                        if ($due === null || $source->isCompleted($item)) {
                            continue;
                        }
                        $total++;
                        if ($due < $today) {
                            $overdue++;
                            $bySource[$source->label()] = ($bySource[$source->label()] ?? 0) + 1;
                        }
                    }
                } catch (\Throwable) {
                    // one broken source must not hide the others; My Day logs it
                    continue;
                }
            }
            if (($check['kpi_objectives'] ?? false) === true) {
                $total += $this->kpiMeasuredObjectives($tenant);
            }
        }

        if ($total === 0) {
            return NoDataResult::build('wizard.gap.no_planned_duties', 'app_recurring_duty_index', $check, [
                'type' => 'deadline_adherence',
                'total' => 0,
            ]);
        }

        arsort($bySource);
        $score = round(($total - $overdue) / $total * 100, 1);
        $gap = null;
        if ($overdue > 0) {
            $gap = [
                'title' => 'wizard.gap.duties_overdue',
                'description' => 'wizard.gap.duties_overdue_desc',
                'description_params' => [
                    '%overdue%' => $overdue,
                    '%total%' => $total,
                    '%types%' => implode(', ', array_slice(array_keys($bySource), 0, 3)),
                ],
                'priority' => $check['priority'] ?? 'high',
                'route' => $check['route'] ?? 'app_my_day',
            ];
        }

        return [
            'score' => $score,
            'details' => [
                'type' => 'deadline_adherence',
                'total' => $total,
                'overdue' => $overdue,
                'overdue_by_type' => $bySource,
            ],
            'gap' => $gap,
        ];
    }

    /** Open objectives a KPI measures: the tool measures them, they are never late. */
    private function kpiMeasuredObjectives(Tenant $tenant): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(ISMSObjective::class, 'o')
            ->where('o.tenant = :tenant')
            ->andWhere('o.kpiKey IS NOT NULL')
            ->andWhere('o.status NOT IN (:closed)')
            ->setParameter('tenant', $tenant)
            ->setParameter('closed', ['achieved', 'cancelled'])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
