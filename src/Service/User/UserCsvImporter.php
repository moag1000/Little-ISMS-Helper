<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use App\Security\UserAdministrationGuard;
use App\Service\AuditLogger;
use App\Service\PasswordPolicyResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * CSV user import of the user administration (`/admin/users/import`).
 *
 * Expected columns: email, first_name, last_name, password, is_active, roles.
 * Same rules as the user form (S14): roles only within the actor's role
 * ceiling ({@see UserAdministrationGuard::mayAssignRoleName()}), a given
 * password must meet the organisation's minimum length
 * ({@see PasswordPolicyResolver}); an empty password column becomes a random
 * one (the person resets it). Every imported user belongs to the importing
 * person's current organisation — the super admin's too (W9: users without an
 * organisation are invisible to every organisation and outside the tenant
 * filter); one audit batch for the file.
 */
final class UserCsvImporter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly RoleRepository $roleRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserAdministrationGuard $userAdministrationGuard,
        private readonly PasswordPolicyResolver $passwordPolicyResolver,
        private readonly TranslatorInterface $translator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Reads the rows after the header line and persists + flushes the users.
     *
     * @param resource $handle
     * @return array{imported: int, errors: list<string>}
     */
    public function import($handle): array
    {
        fgetcsv($handle, escape: '\\'); // header

        /** @var list<User> $imported */
        $imported = [];
        $errors = [];
        $seenEmails = [];
        $actorTenant = $this->userAdministrationGuard->getActorTenant();

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            try {
                $error = $this->importRow($row, $seenEmails, $actorTenant, $errors, $imported);
                if ($error !== null) {
                    $errors[] = $error;
                }
            } catch (Throwable $e) {
                $errors[] = $this->trans('user.validation.import_row_failed', ['%error%' => $e->getMessage()]);
            }
        }

        $this->entityManager->flush();
        if ($imported !== []) {
            $this->auditLogger->logBulk(
                'user.imported',
                'User',
                ['count' => count($imported), 'source' => 'csv'],
                array_map(static fn (User $u): array => [
                    'action' => 'user.imported',
                    'entity_id' => $u->getId(),
                    'new_values' => [
                        'is_active' => $u->isActive(),
                        'roles' => array_values(array_map(static fn ($r): ?string => $r->getName(), $u->getCustomRoles()->toArray())),
                    ],
                ], $imported),
                sprintf('CSV import: %d users', count($imported)),
            );
        }

        return ['imported' => count($imported), 'errors' => $errors];
    }

    /**
     * @param array<int, string|null> $row
     * @param array<string, true> $seenEmails
     * @param list<string> $errors receives non-fatal notes (skipped roles)
     * @param list<User> $imported receives the persisted user
     * @return string|null error that skips the row, null = imported
     */
    private function importRow(array $row, array &$seenEmails, ?Tenant $actorTenant, array &$errors, array &$imported): ?string
    {
        $email = $row[0] ?? null;
        $firstName = $row[1] ?? null;
        $lastName = $row[2] ?? null;
        $password = (string) ($row[3] ?? '');
        $isActive = ($row[4] ?? 'yes') === 'yes';
        $roleNames = isset($row[5]) ? explode(',', (string) $row[5]) : [];

        if (!$email || !$firstName || !$lastName) {
            return $this->trans('user.validation.import_missing_fields');
        }

        // Invalid address or the same e-mail twice in one file (both rows would hit the unique key).
        $email = trim($email);
        $emailKey = mb_strtolower($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || isset($seenEmails[$emailKey])) {
            return $this->trans(
                isset($seenEmails[$emailKey]) ? 'user.validation.import_duplicate_in_file' : 'user.validation.import_invalid_email',
                ['%email%' => $email],
            );
        }
        $seenEmails[$emailKey] = true;

        // E-mail is unique instance-wide, so the message must not tell a
        // tenant admin whether the account lives in another tenant.
        if ($this->userRepository->findOneBy(['email' => $email]) instanceof User) {
            return $this->trans('user.validation.import_email_unavailable', ['%email%' => $email]);
        }

        if (!$actorTenant instanceof Tenant || !$this->userAdministrationGuard->canAssignTenant($actorTenant)) {
            return $this->trans('user.validation.import_no_organisation', ['%email%' => $email]);
        }
        $tenant = $actorTenant;

        $minLength = $this->minPasswordLength($tenant);
        if ($password !== '' && mb_strlen($password) < $minLength) {
            return $this->trans('user.validation.import_password_too_short', ['%email%' => $email, '%min%' => $minLength]);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setIsActive($isActive);
        $user->setAuthProvider('local');
        $user->setTenant($tenant);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password !== '' ? $password : bin2hex(random_bytes(16))));

        foreach ($roleNames as $roleName) {
            $roleName = trim($roleName);
            $role = $roleName === '' ? null : $this->roleRepository->findOneBy(['name' => $roleName]);
            if ($role === null) {
                continue;
            }
            if (!$this->userAdministrationGuard->mayOfferCustomRole($role)) {
                $errors[] = $this->trans('user.validation.import_role_skipped', ['%role%' => $roleName, '%email%' => $email]);
                continue;
            }
            $user->addCustomRole($role);
        }

        $this->entityManager->persist($user);
        $imported[] = $user;

        return null;
    }

    private function minPasswordLength(Tenant $tenant): int
    {
        return $this->passwordPolicyResolver->resolveFor($tenant);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'user');
    }
}
