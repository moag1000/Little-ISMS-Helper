<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\RoadmapGroup;
use App\Entity\RoadmapTask;
use App\Entity\Tenant;
use App\Entity\UnavailabilityCalendar;
use App\Entity\UnavailabilityPeriod;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seed default system Roadmap-Groups + Roadmap-Tasks for the resource-planning
 * module. Idempotent: a (tenant, name) pair is created only once; existing rows
 * are left untouched. System rows are flagged isSystem* so the UI protects them
 * from deletion.
 *
 * Scope: per-tenant. Without --tenant it seeds every tenant.
 */
#[AsCommand(
    name: 'app:planning:seed-defaults',
    description: 'Seed default system Roadmap-Groups and Roadmap-Tasks for resource planning',
)]
final class PlanningSeedDefaultsCommand extends Command
{
    /**
     * Default groups: name => [icon, ismsDomain].
     *
     * @var array<string, array{string, string}>
     */
    private const GROUPS = [
        'Audits & Reviews'   => ['journal-check', 'A.9.2'],
        'Korrekturmaßnahmen' => ['wrench', 'A.10.1'],
        'Business Continuity' => ['shield-shaded', 'A.5.29'],
        'Awareness & Training' => ['graduation-cap', 'A.6.3'],
        'Betrieb & Tagesgeschäft' => ['rotate', 'A.5.1'],
    ];

    /**
     * Default tasks: name => [group-name, defaultPtPerWeek, recurring, reactive, ismsDomain].
     *
     * @var array<string, array{string, ?string, bool, bool, ?string}>
     */
    private const TASKS = [
        'Interne Audits'        => ['Audits & Reviews', '2.0', true, false, 'A.9.2'],
        'Management-Review'     => ['Audits & Reviews', '1.0', true, false, 'A.9.3'],
        'Korrekturmaßnahmen'    => ['Korrekturmaßnahmen', null, true, false, 'A.10.1'],
        'BC-Übungen'            => ['Business Continuity', '1.0', true, false, 'A.5.30'],
        'Awareness-Schulungen'  => ['Awareness & Training', '0.5', true, false, 'A.6.3'],
        'Rezertifizierung'      => ['Betrieb & Tagesgeschäft', '1.0', true, false, 'A.5.1'],
        'Vorfallsbehandlung'    => ['Betrieb & Tagesgeschäft', '1.0', false, true, 'A.5.24'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Seed only this tenant id (default: all tenants)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be created without persisting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $tenantId = $input->getOption('tenant');

        $tenantRepo = $this->entityManager->getRepository(Tenant::class);
        $tenants = $tenantId !== null
            ? array_filter([$tenantRepo->find((int) $tenantId)])
            : $tenantRepo->findAll();

        if ($tenants === []) {
            $io->warning('No matching tenant found.');
            return Command::SUCCESS;
        }

        $groupRepo = $this->entityManager->getRepository(RoadmapGroup::class);
        $taskRepo = $this->entityManager->getRepository(RoadmapTask::class);
        $created = 0;

        foreach ($tenants as $tenant) {
            /** @var Tenant $tenant */
            $groupByName = [];

            $sort = 0;
            foreach (self::GROUPS as $name => [$icon, $domain]) {
                $existing = $groupRepo->findOneBy(['tenant' => $tenant, 'name' => $name]);
                if ($existing instanceof RoadmapGroup) {
                    $groupByName[$name] = $existing;
                    $sort++;
                    continue;
                }
                $group = new RoadmapGroup();
                $group->setName($name)
                    ->setIcon($icon)
                    ->setIsmsDomain($domain)
                    ->setSortOrder($sort++)
                    ->setIsSystemGroup(true)
                    ->setTenant($tenant);
                $groupByName[$name] = $group;
                if (!$dryRun) {
                    $this->entityManager->persist($group);
                }
                $created++;
            }

            foreach (self::TASKS as $name => [$groupName, $pt, $recurring, $reactive, $domain]) {
                $existing = $taskRepo->findOneBy(['tenant' => $tenant, 'name' => $name]);
                if ($existing instanceof RoadmapTask) {
                    continue;
                }
                $task = new RoadmapTask();
                $task->setName($name)
                    ->setGroup($groupByName[$groupName] ?? null)
                    ->setDefaultPtPerWeek($pt)
                    ->setRecurring($recurring)
                    ->setIsReactiveReservation($reactive)
                    ->setIsmsDomain($domain)
                    ->setVisibility('all')
                    ->setIsSystemTask(true)
                    ->setTenant($tenant);
                if (!$dryRun) {
                    $this->entityManager->persist($task);
                }
                $created++;
            }

            $created += $this->seedHolidays($tenant, $dryRun);
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            '%s %d system planning row(s) across %d tenant(s).',
            $dryRun ? '[dry-run] would create' : 'Created',
            $created,
            count($tenants),
        ));

        return Command::SUCCESS;
    }

    /**
     * Ensure the tenant has an UnavailabilityCalendar seeded with the ~9
     * Germany-wide public holidays for the current + next year. Idempotent.
     * No Bundesland split, no shutdown periods (left empty by design).
     *
     * @return int number of holiday rows created
     */
    private function seedHolidays(Tenant $tenant, bool $dryRun): int
    {
        $calendarRepo = $this->entityManager->getRepository(UnavailabilityCalendar::class);
        $calendar = $calendarRepo->findOneBy(['tenant' => $tenant]);
        if (!$calendar instanceof UnavailabilityCalendar) {
            $calendar = new UnavailabilityCalendar();
            $calendar->setName('Feiertage')->setTenant($tenant);
            if (!$dryRun) {
                $this->entityManager->persist($calendar);
            }
        }

        $existing = [];
        foreach ($calendar->getPeriods() as $period) {
            $existing[$period->getStartDate()?->format('Y-m-d')] = true;
        }

        $created = 0;
        $year = (int) (new DateTimeImmutable())->format('Y');
        foreach ([$year, $year + 1] as $y) {
            foreach ($this->germanHolidays($y) as $label => $date) {
                $key = $date->format('Y-m-d');
                if (isset($existing[$key])) {
                    continue;
                }
                $existing[$key] = true;
                $period = new UnavailabilityPeriod();
                $period->setKind(UnavailabilityPeriod::KIND_HOLIDAY)
                    ->setStartDate($date)
                    ->setLabel($label);
                $calendar->addPeriod($period);
                if (!$dryRun) {
                    $this->entityManager->persist($period);
                }
                $created++;
            }
        }

        return $created;
    }

    /**
     * Germany-wide public holidays for a year.
     *
     * @return array<string, DateTimeImmutable>
     */
    private function germanHolidays(int $year): array
    {
        $easter = $this->easterSunday($year);

        return [
            'Neujahr'                    => new DateTimeImmutable(sprintf('%d-01-01', $year)),
            'Karfreitag'                 => $easter->modify('-2 days'),
            'Ostermontag'                => $easter->modify('+1 day'),
            'Tag der Arbeit'             => new DateTimeImmutable(sprintf('%d-05-01', $year)),
            'Christi Himmelfahrt'        => $easter->modify('+39 days'),
            'Pfingstmontag'              => $easter->modify('+50 days'),
            'Tag der Deutschen Einheit'  => new DateTimeImmutable(sprintf('%d-10-03', $year)),
            '1. Weihnachtstag'           => new DateTimeImmutable(sprintf('%d-12-25', $year)),
            '2. Weihnachtstag'           => new DateTimeImmutable(sprintf('%d-12-26', $year)),
        ];
    }

    /**
     * Easter Sunday via the Anonymous Gregorian algorithm (no ext-calendar needed).
     */
    private function easterSunday(int $year): DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
