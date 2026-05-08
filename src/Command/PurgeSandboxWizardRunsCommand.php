<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\WizardRunRepository;
use App\Service\PolicyWizard\Mode\SandboxModeHandler;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Policy-Wizard W2-C — purge sandbox wizard runs older than 7 days.
 *
 * Architecture §6.4: sandbox runs are auto-purged after 7 days. This
 * command finds every {@see \App\Entity\WizardRun} with
 * `status='sandbox'` AND `startedAt < now() - 7 days` and removes
 * them. The default cut-off mirrors {@see SandboxModeHandler::PURGE_AFTER_DAYS}
 * but can be overridden via `--days` for ops emergencies.
 *
 * Cron-friendly — designed to run daily, e.g.:
 *   0 3 * * * cd /path/to/project && php bin/console app:policy-wizard:purge-sandboxes
 *
 * Sandbox runs never persisted any Documents or TenantPolicySetting
 * changes, so removal is non-destructive — the only effect is freeing
 * disk space and clearing the user's "in-progress" sandbox list.
 */
#[AsCommand(
    name: 'app:policy-wizard:purge-sandboxes',
    description: 'Delete sandbox-mode policy-wizard runs older than 7 days (architecture §6.4).',
    help: <<<'TXT'
The <info>%command.name%</info> command removes Policy-Wizard sandbox runs that
have outlived their 7-day retention window.

Sandbox runs are short-lived previews that never write Documents or
TenantPolicySetting rows — they only carry the ephemeral
`inputs.sandbox_preview` payload. Once the user has reviewed the preview
they can either re-run in Full mode (commits) or abandon (purged here).

<info>Examples:</info>

  # Preview which runs would be deleted
  <info>php bin/console %command.name% --dry-run</info>

  # Default: purge runs older than 7 days
  <info>php bin/console %command.name%</info>

  # Custom retention (ops override)
  <info>php bin/console %command.name% --days=14</info>

<info>Recommended cron:</info>
  <comment>0 3 * * * cd /path && php bin/console %command.name% >> /var/log/policy-wizard-sandbox-purge.log 2>&1</comment>
TXT
)]
final class PurgeSandboxWizardRunsCommand
{
    public function __construct(
        private readonly WizardRunRepository $wizardRunRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        #[Option(description: 'Show which sandbox runs would be deleted without actually deleting them', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Retention window in days (defaults to 7 per architecture §6.4)', name: 'days')]
        ?int $days = null,
        SymfonyStyle $io = new SymfonyStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput(),
        ),
    ): int {
        $retention = $days ?? SandboxModeHandler::PURGE_AFTER_DAYS;
        if ($retention < 1) {
            $io->error(sprintf('Retention must be >= 1 day, %d given.', $retention));
            return Command::FAILURE;
        }

        $cutoff = new DateTimeImmutable(sprintf('-%d days', $retention));

        $io->title('Policy-Wizard Sandbox Run Purge');
        $io->table(
            ['Parameter', 'Value'],
            [
                ['Retention Window', sprintf('%d days', $retention)],
                ['Cutoff (UTC)', $cutoff->format('Y-m-d H:i:s')],
                ['Mode', $dryRun ? 'DRY RUN (no deletes)' : 'EXECUTE'],
            ],
        );

        $candidates = $this->wizardRunRepository->findSandboxOlderThan($cutoff);
        $count = count($candidates);

        if ($count === 0) {
            $io->success('No sandbox runs older than the cut-off — nothing to do.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Found %d sandbox run(s) for purge', $count));
        if ($io->isVerbose()) {
            $rows = [];
            foreach ($candidates as $run) {
                $rows[] = [
                    $run->getId(),
                    $run->getTenantId() ?? 'n/a',
                    $run->getStartedAt()?->format('Y-m-d H:i:s') ?? 'n/a',
                    $run->getStep(),
                ];
            }
            $io->table(['ID', 'Tenant', 'Started At', 'Last Step'], $rows);
        }

        if ($dryRun) {
            $io->note(sprintf(
                '%d sandbox run(s) would be deleted. Re-run without --dry-run to execute.',
                $count,
            ));
            return Command::SUCCESS;
        }

        foreach ($candidates as $run) {
            $this->entityManager->remove($run);
        }
        $this->entityManager->flush();

        $io->success(sprintf('Purged %d sandbox run(s) older than %s.', $count, $cutoff->format('Y-m-d')));
        return Command::SUCCESS;
    }
}
