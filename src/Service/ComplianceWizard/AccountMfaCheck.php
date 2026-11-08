<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Responsibility\ResponsibilityResolver;

/**
 * The `account_mfa` compliance-wizard check (NIS-2 Art. 21 (2) j, ISO 27001
 * A.8.5): share of the organisation's privileged accounts that sign in with a
 * second factor — the tool's own accounts as a second signal beside the
 * control's status. Privileged = active users reaching one of `roles`
 * (default ROLE_ADMIN, role hierarchy). Accounts that sign in through SSO
 * leave the factor to the identity provider and do not count.
 * No privileged local account is "no data".
 */
final class AccountMfaCheck
{
    public const array DEFAULT_ROLES = ['ROLE_ADMIN'];

    public function __construct(
        private readonly ResponsibilityResolver $responsibility,
    ) {
    }

    /**
     * @param array<string, mixed> $check config key `roles` (privileged roles)
     * @return array{score: float, details: array<string, mixed>, gap: array<string, mixed>|null}
     */
    public function check(array $check, ?Tenant $tenant): array
    {
        $roles = array_values(array_filter((array) ($check['roles'] ?? self::DEFAULT_ROLES), 'is_string'));
        $local = [];
        $sso = 0;
        if ($tenant instanceof Tenant && $roles !== []) {
            foreach ($this->responsibility->usersReaching($tenant, ...$roles) as $user) {
                if (self::signsInLocally($user)) {
                    $local[] = $user;
                } else {
                    $sso++;
                }
            }
        }
        $details = ['type' => 'account_mfa', 'roles' => $roles, 'sso' => $sso];
        if ($local === []) {
            return NoDataResult::build('wizard.gap.no_privileged_accounts', 'user_management_index', $check, $details + ['total' => 0]);
        }

        $withMfa = count(array_filter($local, static fn (User $u): bool => $u->hasMfaEnabled()));
        $total = count($local);
        $gap = null;
        if ($withMfa < $total) {
            $gap = [
                'title' => 'wizard.gap.accounts_without_mfa',
                'description' => 'wizard.gap.accounts_without_mfa_desc',
                'description_params' => ['%open%' => $total - $withMfa, '%total%' => $total],
                'priority' => $check['priority'] ?? 'critical',
                'route' => $check['route'] ?? 'user_management_index',
            ];
        }

        return [
            'score' => round($withMfa / $total * 100, 1),
            'details' => $details + ['total' => $total, 'with_mfa' => $withMfa],
            'gap' => $gap,
        ];
    }

    private static function signsInLocally(User $user): bool
    {
        return in_array($user->getAuthProvider(), [null, '', 'local'], true);
    }
}
