<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\RoadmapGroup;
use App\Entity\RoadmapTask;
use App\Entity\Tenant;
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
}
