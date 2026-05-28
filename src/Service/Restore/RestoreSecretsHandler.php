<?php

declare(strict_types=1);

namespace App\Service\Restore;

use App\Entity\User;
use App\Service\BackupEncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Handles sensitive-data operations during a restore:
 *  - markSystemSettingsForDecryption — flags rows for deferred per-row decryption (best_effort mode)
 *  - decryptSystemSettingRow         — per-row decryption in best_effort deferred mode
 *  - decryptSystemSettingsValues     — eager batch decryption (strict mode)
 *  - setAdminPassword                — sets first admin-user password after restore
 */
class RestoreSecretsHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly UserPasswordHasherInterface $userPasswordHasher,
        private readonly ?BackupEncryptionService $backupEncryption = null,
    ) {
    }

    /**
     * Mark SystemSettings rows for deferred per-row decryption in best_effort mode.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function markSystemSettingsForDecryption(array $rows): array
    {
        if ($this->backupEncryption === null) {
            return $rows;
        }

        foreach ($rows as &$row) {
            $value = $row['value'] ?? null;
            if ($this->backupEncryption->isEncrypted($value)) {
                $row['__needs_decrypt__'] = true;
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Decrypt a single SystemSettings row in best_effort deferred mode.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     * @throws \RuntimeException When decryption fails.
     */
    public function decryptSystemSettingRow(array $row): array
    {
        if ($this->backupEncryption === null) {
            unset($row['__needs_decrypt__']);
            return $row;
        }

        $value = $row['value'] ?? null;
        if ($this->backupEncryption->isEncrypted($value)) {
            $row['value'] = $this->backupEncryption->decryptValue($value);
        }
        unset($row['__needs_decrypt__']);

        return $row;
    }

    /**
     * Decrypt sensitive SystemSettings values (eager/strict mode).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException When decryption fails.
     */
    public function decryptSystemSettingsValues(array $rows): array
    {
        if ($this->backupEncryption === null) {
            return $rows;
        }

        foreach ($rows as &$row) {
            $value = $row['value'] ?? null;
            if (!$this->backupEncryption->isEncrypted($value)) {
                continue;
            }
            $row['value'] = $this->backupEncryption->decryptValue($value);
        }
        unset($row);

        return $rows;
    }

    /**
     * Hash and apply a password to all User entities when $adminPassword is given.
     * Called from within restoreEntity loop (User entity type).
     *
     * @param object $entity      The User entity being restored
     * @param array  $data        Serialized data for this user row
     * @param string $adminPassword  Plain-text password
     * @param array  $warnings    Reference to warnings array (mutated in-place)
     */
    public function applyAdminPasswordToUser(
        object $entity,
        array $data,
        string $adminPassword,
        array &$warnings,
    ): void {
        $this->logger->info('Processing User entity password', [
            'user_email' => $data['email'] ?? 'unknown',
            'user_id' => $data['id'] ?? 'unknown',
            'admin_password_length' => strlen($adminPassword),
            'entity_class' => $entity::class,
        ]);

        try {
            $hashedPassword = $this->userPasswordHasher->hashPassword($entity, $adminPassword);

            $this->logger->debug('Password hashed successfully', [
                'user_email' => $data['email'] ?? 'unknown',
                'hashed_length' => strlen($hashedPassword),
            ]);

            $reflection = new \ReflectionClass($entity);
            if ($reflection->hasProperty('password')) {
                $reflection->getProperty('password')->setValue($entity, $hashedPassword);
                $this->logger->info('Password set for restored user', [
                    'user_email' => $data['email'] ?? 'unknown',
                    'user_id' => $data['id'] ?? 'unknown',
                ]);
                $warnings[] = sprintf(
                    'User "%s" restored with setup password. User should change password after first login.',
                    $data['email'] ?? 'ID: ' . ($data['id'] ?? 'unknown')
                );
            } else {
                $this->logger->error('User entity has no password property', [
                    'user_email' => $data['email'] ?? 'unknown',
                ]);
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to hash/set password for restored user', [
                'user_email' => $data['email'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $warnings[] = sprintf(
                'WARNING: Could not set password for user "%s": %s',
                $data['email'] ?? 'ID: ' . ($data['id'] ?? 'unknown'),
                $e->getMessage()
            );
        }
    }

    /**
     * Set password for the first admin user after restore completes.
     *
     * @param string $password   Plain text password
     * @param array  $warnings   Reference to warnings array (mutated in-place)
     */
    public function setAdminPassword(string $password, array &$warnings): void
    {
        try {
            $adminUser = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
                ->where('u.roles LIKE :role')
                ->setParameter('role', '%ROLE_ADMIN%')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($adminUser === null) {
                $adminUser = $this->entityManager->getRepository(User::class)->findOneBy([], ['id' => 'ASC']);
            }

            if ($adminUser !== null) {
                $hashedPassword = $this->userPasswordHasher->hashPassword($adminUser, $password);
                $adminUser->setPassword($hashedPassword);
                $this->entityManager->flush();

                $warnings[] = sprintf('Admin-Passwort wurde für Benutzer "%s" gesetzt.', $adminUser->getEmail());
                $this->logger->info('Set admin password after restore', ['user_email' => $adminUser->getEmail()]);
            } else {
                $warnings[] = 'Kein Benutzer gefunden, um Admin-Passwort zu setzen.';
            }
        } catch (Exception $e) {
            $warnings[] = sprintf('Fehler beim Setzen des Admin-Passworts: %s', $e->getMessage());
            $this->logger->error('Failed to set admin password', ['error' => $e->getMessage()]);
        }
    }
}
