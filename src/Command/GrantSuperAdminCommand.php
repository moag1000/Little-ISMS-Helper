<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Operator recovery (3.15.3 security release): grant ROLE_SUPER_ADMIN
 * (instance operator) to an existing account.
 *
 * Since the hotfix ROLE_ADMIN is scoped to its own tenant tree and only a
 * ROLE_SUPER_ADMIN may grant privileged roles. The upgrade migration
 * promotes the initial setup admin; this command covers installs where that
 * was the wrong account or none existed. Shell access = operator, so no
 * in-app authorisation applies; every grant is written to the audit log.
 */
#[AsCommand(
    name: 'app:user:grant-super-admin',
    description: 'Grant ROLE_SUPER_ADMIN (instance operator) to an existing user',
)]
final class GrantSuperAdminCommand
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(
        #[Argument(description: 'E-mail address of the existing user')]
        string $email,
        SymfonyStyle $io,
    ): int {
        $user = $this->userRepository->findOneBy(['email' => $email]);
        if ($user === null) {
            $io->error(sprintf('No user with e-mail "%s" found.', $email));

            return Command::FAILURE;
        }

        $storedRoles = $user->getStoredRoles();
        if (in_array('ROLE_SUPER_ADMIN', $storedRoles, true)) {
            $io->success(sprintf('%s already holds ROLE_SUPER_ADMIN — nothing changed.', $email));

            return Command::SUCCESS;
        }

        $io->warning([
            'ROLE_SUPER_ADMIN is the instance operator role: access to ALL organisations,',
            'users, instance-wide backups and system roles.',
        ]);
        if (!$io->confirm(sprintf('Grant ROLE_SUPER_ADMIN to %s (#%d)?', $email, (int) $user->getId()), false)) {
            $io->note('Cancelled — nothing changed.');

            return Command::FAILURE;
        }

        $newRoles = [...$storedRoles, 'ROLE_SUPER_ADMIN'];
        $user->setRoles($newRoles);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            'user.super_admin.granted',
            'User',
            $user->getId(),
            ['roles' => $storedRoles],
            ['roles' => $newRoles],
            'ROLE_SUPER_ADMIN granted via CLI app:user:grant-super-admin',
            'cli:app:user:grant-super-admin',
        );

        $io->success(sprintf('Granted ROLE_SUPER_ADMIN to %s.', $email));

        return Command::SUCCESS;
    }
}
