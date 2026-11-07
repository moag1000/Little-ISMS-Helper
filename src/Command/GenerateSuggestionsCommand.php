<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TenantRepository;
use App\Service\Suggestion\SuggestionGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Nightly suggestion run (scheduler, 05:30 — before the 07:00 digest): every
 * suggestion kind for every active organisation. No setup needed; part of
 * App\Schedule::COMMAND_TASKS.
 */
#[AsCommand(
    name: 'app:suggestions:generate',
    description: 'Fill the suggestion queue (derivations, inheritance, AI) for all organisations',
)]
class GenerateSuggestionsCommand
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly SuggestionGenerator $generator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Only these kinds (comma-separated keys)', name: 'kind')]
        ?string $kind = null,
    ): int {
        $only = $kind !== null && $kind !== '' ? array_map('trim', explode(',', $kind)) : null;
        $failed = false;
        foreach ($this->tenants->findActive() as $tenant) {
            $result = $this->generator->run($tenant, $only);
            $io->writeln(sprintf(
                '%s: %d new, %d superseded%s',
                (string) $tenant->getName(),
                $result['created'],
                $result['superseded'],
                $result['failed'] !== [] ? ', failed: ' . implode(', ', $result['failed']) : '',
            ));
            $failed = $failed || $result['failed'] !== [];
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
