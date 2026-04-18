<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\ScheduledReport;
use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Scheduled-report recipient filter.
 *
 * ISB MINOR-4 / DSGVO Art. 32 + ISO 27001:2022 A.5.34:
 * Only tenant-internal users with role MANAGER or higher may receive
 * compliance reports by e-mail. Anything else is dropped with a reason
 * the caller is expected to audit-log.
 */
final class RecipientFilter
{
    public const REASON_UNKNOWN_USER = 'unknown_user';
    public const REASON_CROSS_TENANT = 'cross_tenant_forbidden';
    public const REASON_ROLE_TOO_LOW = 'role_too_low';
    public const REASON_INACTIVE = 'inactive_user';

    private const QUALIFYING_ROLES = [
        'ROLE_MANAGER',
        'ROLE_ADMIN',
        'ROLE_SUPER_ADMIN',
    ];

    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * @return array{valid: list<string>, dropped: list<array{email:string, reason:string}>}
     */
    public function filter(ScheduledReport $report): array
    {
        $valid = [];
        $dropped = [];
        $targetTenantId = $report->getTenantId();

        foreach ($report->getRecipients() as $email) {
            $email = trim((string) $email);
            if ($email === '') {
                continue;
            }

            $user = $this->userRepository->findOneBy(['email' => $email]);
            if (!$user instanceof User) {
                $dropped[] = ['email' => $email, 'reason' => self::REASON_UNKNOWN_USER];
                continue;
            }

            $userTenantId = $user->getTenant()?->getId();
            if ($userTenantId === null || $userTenantId !== $targetTenantId) {
                $dropped[] = ['email' => $email, 'reason' => self::REASON_CROSS_TENANT];
                continue;
            }

            if (method_exists($user, 'isActive') && !$user->isActive()) {
                $dropped[] = ['email' => $email, 'reason' => self::REASON_INACTIVE];
                continue;
            }

            if (!$this->hasQualifyingRole($user)) {
                $dropped[] = ['email' => $email, 'reason' => self::REASON_ROLE_TOO_LOW];
                continue;
            }

            $valid[] = $email;
        }

        return ['valid' => array_values(array_unique($valid)), 'dropped' => $dropped];
    }

    /**
     * Validate a single email for form-time checks.
     *
     * @return string|null null if valid, otherwise the failure reason constant
     */
    public function validateSingle(string $email, int $tenantId): ?string
    {
        $email = trim($email);
        if ($email === '') {
            return self::REASON_UNKNOWN_USER;
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            return self::REASON_UNKNOWN_USER;
        }

        $userTenantId = $user->getTenant()?->getId();
        if ($userTenantId === null || $userTenantId !== $tenantId) {
            return self::REASON_CROSS_TENANT;
        }

        if (method_exists($user, 'isActive') && !$user->isActive()) {
            return self::REASON_INACTIVE;
        }

        if (!$this->hasQualifyingRole($user)) {
            return self::REASON_ROLE_TOO_LOW;
        }

        return null;
    }

    private function hasQualifyingRole(User $user): bool
    {
        foreach ($user->getRoles() as $role) {
            if (in_array($role, self::QUALIFYING_ROLES, true)) {
                return true;
            }
        }
        return false;
    }
}
