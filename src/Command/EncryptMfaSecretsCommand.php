<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MfaToken;
use App\Service\MfaEncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-time migration command to encrypt existing plaintext TOTP secrets.
 *
 * PenTest Finding PT-003: TOTP secrets stored in cleartext.
 * Run once after deploying the MfaEncryptionService.
 *
 * Usage:
 *   php bin/console app:encrypt-mfa-secrets --dry-run   # Preview
 *   php bin/console app:encrypt-mfa-secrets              # Execute
 */
#[AsCommand(
    name: 'app:encrypt-mfa-secrets',
    description: 'Encrypt existing plaintext TOTP secrets in the database',
)]
class EncryptMfaSecretsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MfaEncryptionService $mfaEncryptionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        $io->title('MFA Secret Encryption Migration');

        if ($dryRun) {
            $io->note('DRY RUN — no changes will be made');
        }

        $tokens = $this->entityManager->getRepository(MfaToken::class)->findBy(['tokenType' => 'totp']);
        $total = count($tokens);
        $encrypted = 0;
        $alreadyEncrypted = 0;
        $noSecret = 0;

        foreach ($tokens as $token) {
            $secret = $token->getSecret();

            if ($secret === null || $secret === '') {
                $noSecret++;
                continue;
            }

            if ($this->mfaEncryptionService->isEncrypted($secret)) {
                $alreadyEncrypted++;
                continue;
            }

            if (!$dryRun) {
                $token->setSecret($this->mfaEncryptionService->encrypt($secret));
            }

            $encrypted++;
        }

        if (!$dryRun && $encrypted > 0) {
            $this->entityManager->flush();
        }

        $io->table(
            ['Metric', 'Count'],
            [
                ['Total TOTP tokens', (string) $total],
                ['Encrypted (this run)', (string) $encrypted],
                ['Already encrypted', (string) $alreadyEncrypted],
                ['No secret (skipped)', (string) $noSecret],
            ]
        );

        if ($dryRun) {
            $io->warning(sprintf('%d secrets would be encrypted. Run without --dry-run to apply.', $encrypted));
        } elseif ($encrypted > 0) {
            $io->success(sprintf('%d TOTP secrets encrypted successfully.', $encrypted));
        } else {
            $io->success('All TOTP secrets are already encrypted.');
        }

        return Command::SUCCESS;
    }
}
