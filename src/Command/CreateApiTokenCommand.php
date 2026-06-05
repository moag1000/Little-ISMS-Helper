<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ApiToken;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * F6 — mint a read-only API Bearer token for a user. The plaintext is shown
 * ONCE; only its SHA-256 hash is persisted.
 */
#[AsCommand(
    name: 'app:api-token:create',
    description: 'Create a read-only API Bearer token for a user (F6)',
)]
final class CreateApiTokenCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email of the user the token is issued for')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'Human-readable label', 'API token')
            ->addOption('expires-days', null, InputOption::VALUE_REQUIRED, 'Expiry in days (omit for no expiry)');
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

        $plain = bin2hex(random_bytes(32));

        $token = new ApiToken();
        $token->setUser($user);
        $token->setTenant($user->getTenant());
        $token->setTokenHash(hash('sha256', $plain));
        $token->setLabel((string) $input->getOption('label'));

        $expiresDays = $input->getOption('expires-days');
        if ($expiresDays !== null && (string) $expiresDays !== '') {
            $token->setExpiresAt((new DateTimeImmutable())->modify(sprintf('+%d days', (int) $expiresDays)));
        }

        $this->em->persist($token);
        $this->em->flush();

        $io->success('Read-only API token created. Store it now — it will not be shown again:');
        $io->writeln('  ' . $plain);
        $io->writeln('');
        $io->writeln('  Usage:  curl -H "Authorization: Bearer ' . $plain . '" https://<host>/api/...');

        return Command::SUCCESS;
    }
}
