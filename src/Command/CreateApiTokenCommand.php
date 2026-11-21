<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mint an API Bearer token for a user: read-only, or with --can-write one the assistant connection (MCP) may
 * write with (rights of the user, switchable per organisation). The plaintext is shown ONCE; only its SHA-256
 * hash is persisted.
 */
#[AsCommand(
    name: 'app:api-token:create',
    description: 'Create an API Bearer token for a user: read-only, or with --can-write also for writing over MCP (F6)',
)]
final class CreateApiTokenCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly \App\Service\ApiTokenManager $tokenManager,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email of the user the token is issued for')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Human-readable label', 'API token')
            ->addOption('can-write', null, InputOption::VALUE_NONE, 'Token may also write over the assistant connection (MCP); without it the token only reads')
            ->addOption('expires-days', null, InputOption::VALUE_REQUIRED, 'Expiry in days (omit for the maximum of 365)');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if ($user === null) {
            $io->error(sprintf('No user found with email "%s".', $email));
            return Command::FAILURE;
        }

        $expiresRaw = $input->getOption('expires-days');
        $expiresDays = ($expiresRaw !== null && (string) $expiresRaw !== '') ? (int) $expiresRaw : null;

        $canWrite = (bool) $input->getOption('can-write');
        $plain = $this->tokenManager->mint($user, (string) $input->getOption('label'), $expiresDays, canWrite: $canWrite);

        $io->success(($canWrite ? 'API token (read and write over MCP)' : 'Read-only API token') . ' created. Store it now — it will not be shown again:');
        $io->writeln('  ' . $plain);
        $io->writeln('');
        $io->writeln('  Usage:  curl -H "Authorization: Bearer ' . $plain . '" https://<host>/api/...');

        return Command::SUCCESS;
    }
}
