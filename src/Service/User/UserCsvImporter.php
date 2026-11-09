<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use App\Security\UserAdministrationGuard;
use App\Service\PasswordPolicyResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
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
 * one (the person resets it). Tenant admins import into their own
 * organisation.
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
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
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

        $imported = 0;
        $errors = [];
        $seenEmails = [];
        $isSuperAdmin = $this->security->isGranted('ROLE_SUPER_ADMIN');
        $actorTenant = $this->userAdministrationGuard->getActorTenant();

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            try {
                $error = $this->importRow($row, $seenEmails, $isSuperAdmin, $actorTenant, $errors);
                if ($error !== null) {
                    $errors[] = $error;
                    continue;
                }
                $imported++;
            } catch (Throwable $e) {
                $errors[] = $this->trans('user.validation.import_row_failed', ['%error%' => $e->getMessage()]);
            }
        }

        $this->entityManager->flush();

        return ['imported' => $imported, 'errors' => $errors];
    }

    /**
     * @param array<int, string|null> $row
     * @param array<string, true> $seenEmails
     * @param list<string> $errors receives non-fatal notes (skipped roles)
     * @return string|null error that skips the row, null = imported
     */
    private function importRow(array $row, array &$seenEmails, bool $isSuperAdmin, ?Tenant $actorTenant, array &$errors): ?string
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

        $tenant = null;
        if (!$isSuperAdmin) {
            if (!$this->userAdministrationGuard->canAssignTenant($actorTenant)) {
                return $this->trans('user.validation.import_no_organisation', ['%email%' => $email]);
            }
            $tenant = $actorTenant;
        }

        $minLength = $this->minPasswordLength($tenant ?? $actorTenant);
        if ($password !== '' && mb_strlen($password) < $minLength) {
            return $this->trans('user.validation.import_password_too_short', ['%email%' => $email, '%min%' => $minLength]);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setIsActive($isActive);
        $user->setAuthProvider('local');
        if ($tenant !== null) {
            $user->setTenant($tenant);
        }
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

        return null;
    }

    private function minPasswordLength(?Tenant $tenant): int
    {
        return $tenant instanceof Tenant ? $this->passwordPolicyResolver->resolveFor($tenant) : 8;
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'user');
    }
}
