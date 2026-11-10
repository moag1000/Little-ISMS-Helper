<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\ScheduledReport;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

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

    /** Reached through the role hierarchy: CISO, ISB, DPO, … inherit it (S16). */
    private const QUALIFYING_ROLE = 'ROLE_MANAGER';

    /**
     * Report types whose audience holds another role than MANAGER (S32): the board one-pager is for the top
     * management, which does not inherit ROLE_MANAGER (ReportAccessVoter::BOARD_ONE_PAGER lets it download).
     */
    private const AUDIENCE_ROLES = [
        ScheduledReport::TYPE_BOARD => ['ROLE_TOP_MGMT'],
    ];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ?RoleHierarchyInterface $roleHierarchy = null,
    ) {
    }

    /**
     * @return array{valid: list<string>, dropped: list<array{email:string, reason:string}>}
     */
    public function filter(ScheduledReport $report): array
    {
        return $this->filterEmails($report->getRecipients(), $report->getTenantId(), $report->getReportType());
    }

    /**
     * Whether a user may own or receive reports of the organisation: same organisation, active, MANAGER (or the
     * report type's audience role). Null when they qualify, else the reason.
     */
    public function disqualification(User $user, ?int $targetTenantId, ?string $reportType = null): ?string
    {
        $userTenantId = $user->getTenant()?->getId();
        if ($userTenantId === null || $targetTenantId === null || $userTenantId !== $targetTenantId) {
            return self::REASON_CROSS_TENANT;
        }
        if (!$user->isActive()) {
            return self::REASON_INACTIVE;
        }

        return $this->hasQualifyingRole($user, $reportType) ? null : self::REASON_ROLE_TOO_LOW;
    }

    /**
     * Same tenant + role + active check for any list of free-entered addresses
     * (notification digests, ...). A null tenant id qualifies nobody.
     *
     * @param iterable<mixed> $emails
     * @return array{valid: list<string>, dropped: list<array{email:string, reason:string}>}
     */
    public function filterEmails(iterable $emails, ?int $targetTenantId, ?string $reportType = null): array
    {
        $valid = [];
        $dropped = [];

        foreach ($emails as $email) {
            $email = trim((string) $email);
            if ($email === '') {
                continue;
            }

            $user = $this->userRepository->findOneBy(['email' => $email]);
            if (!$user instanceof User) {
                $dropped[] = ['email' => $email, 'reason' => self::REASON_UNKNOWN_USER];
                continue;
            }

            $reason = $this->disqualification($user, $targetTenantId, $reportType);
            if ($reason !== null) {
                $dropped[] = ['email' => $email, 'reason' => $reason];
                continue;
            }

            // The address of the account, not the text the manager typed (case, spaces).
            $valid[] = (string) ($user->getEmail() ?? $email);
        }

        return ['valid' => array_values(array_unique($valid)), 'dropped' => $dropped];
    }

    /**
     * Validate a single email for form-time checks.
     *
     * @return string|null null if valid, otherwise the failure reason constant
     */
    public function validateSingle(string $email, int $tenantId, ?string $reportType = null): ?string
    {
        $email = trim($email);
        if ($email === '') {
            return self::REASON_UNKNOWN_USER;
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            return self::REASON_UNKNOWN_USER;
        }

        return $this->disqualification($user, $tenantId, $reportType);
    }

    private function hasQualifyingRole(User $user, ?string $reportType = null): bool
    {
        $accepted = [self::QUALIFYING_ROLE, ...(self::AUDIENCE_ROLES[$reportType ?? ''] ?? [])];
        $roles = $user->getRoles();
        if ($this->roleHierarchy !== null) {
            $roles = $this->roleHierarchy->getReachableRoleNames($roles);
        } else {
            // Without hierarchy (unit tests): ADMIN and SUPER_ADMIN reach MANAGER in security.yaml.
            $roles = [...$roles, ...(array_intersect($roles, ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']) !== [] ? [self::QUALIFYING_ROLE] : [])];
        }

        return array_intersect($accepted, $roles) !== [];
    }
}
