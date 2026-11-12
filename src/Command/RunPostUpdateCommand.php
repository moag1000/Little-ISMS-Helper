<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Update\PostUpdateRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The update-based Quick-Fix steps (migrations, workflow definitions, upload protection) when the
 * deployed code is newer than the last run. The scheduler calls it every 15 minutes; operators
 * need not. See {@see PostUpdateRunner}.
 */
#[AsCommand(
    name: 'app:update:run',
    description: 'Apply pending migrations and other post-deploy steps when the code version changed',
)]
final class RunPostUpdateCommand
{
    public function __construct(
        private readonly PostUpdateRunner $runner,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Run even when nothing changed or the automatic run is switched off')]
        bool $force = false,
    ): int {
        $result = $this->runner->run('scheduler', $force);
        $io->writeln(sprintf('%s (%s)', $result['ran'] ? 'Ran' : 'Skipped', $result['status']));

        return $result['status'] === 'failed' ? Command::FAILURE : Command::SUCCESS;
    }
}
