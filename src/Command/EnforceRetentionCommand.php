<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\RetentionEnforcementService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Enforces the per-tenant data-retention policies (GDPR Art. 5(1)(e),
 * storage limitation). Dry-run by default — pass --force to actually delete.
 *
 * Only entity types the admin has opted into (auto_delete = true in
 * /admin/settings/data-retention) are touched; GDPR caps are applied at save
 * time. The same logic runs weekly via the scheduler (EnforceRetentionMessage).
 *
 *   php bin/console app:enforce-retention            # dry-run report
 *   php bin/console app:enforce-retention --force    # actually delete
 */
#[AsCommand(
    name: 'app:enforce-retention',
    description: 'Enforce per-tenant data-retention policies (GDPR Art. 5(1)(e)). Dry-run unless --force.',
)]
class EnforceRetentionCommand
{
    public function __construct(
        private readonly RetentionEnforcementService $retentionEnforcementService,
    ) {
    }

    public function __invoke(
        #[Option(description: 'Actually delete expired records (otherwise dry-run report only)', name: 'force')]
        bool $force = false,
        ?SymfonyStyle $symfonyStyle = null,
    ): int {
        $symfonyStyle->title('Data-retention enforcement (GDPR Art. 5(1)(e))');
        $symfonyStyle->writeln($force
            ? '<fg=red>FORCE mode — expired records will be deleted.</>'
            : '<fg=cyan>DRY RUN — no changes. Pass --force to apply.</>');

        $report = $this->retentionEnforcementService->enforce($force);

        $actionable = array_filter($report, static fn (array $r): bool => $r['expired'] > 0 || $r['note'] !== null);

        if ($actionable === []) {
            $symfonyStyle->success('No records past their retention period.');
            return Command::SUCCESS;
        }

        $rows = [];
        $totalExpired = 0;
        $totalDeleted = 0;
        foreach ($actionable as $entry) {
            $rows[] = [
                $entry['tenant'] ?? '-',
                $entry['entity_type'],
                $entry['expired'],
                $entry['deleted'],
                $entry['note'] ?? '',
            ];
            $totalExpired += $entry['expired'];
            $totalDeleted += $entry['deleted'];
        }

        $symfonyStyle->table(['Tenant', 'Entity type', 'Expired', 'Deleted', 'Note'], $rows);

        if ($force) {
            $symfonyStyle->success(sprintf('Deleted %d record(s) across %d policy line(s).', $totalDeleted, count($actionable)));
        } else {
            $symfonyStyle->note(sprintf('%d record(s) would be deleted. Re-run with --force to apply.', $totalExpired));
        }

        return Command::SUCCESS;
    }
}
