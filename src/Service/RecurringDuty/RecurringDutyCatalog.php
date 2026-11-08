<?php

declare(strict_types=1);

namespace App\Service\RecurringDuty;

use App\Entity\Tenant;

/**
 * Recurring duties of the norms that no record carries a date for. Duties
 * with a date field on a record (document review, supplier reassessment, BC
 * plan test, DPIA review, …) have their own deadline source and are not
 * listed here, so nothing is reminded twice.
 *
 * Each entry: module that must be active, scope (null = every organisation,
 * 'nis2' = NIS-2 regulated, 'dora' = DORA obligated), norm reference, default
 * interval from the norm or common practice, fallback roles when no owner is
 * set. Title and explanation: recurring_duty.duty.<key>.title / .body.
 */
final class RecurringDutyCatalog
{
    public const string SCOPE_NIS2 = 'nis2';
    public const string SCOPE_DORA = 'dora';

    /** @var array<string, array{module: string, scope: ?string, norm: string, months: int, roles: list<string>}> */
    public const array DUTIES = [
        'risk_acceptance_criteria' => ['module' => 'risks', 'scope' => null, 'norm' => 'ISO 27001 6.1.2 a', 'months' => 12, 'roles' => ['ROLE_RISK_MANAGER', 'ROLE_CISO']],
        'soa_review' => ['module' => 'controls', 'scope' => null, 'norm' => 'ISO 27001 6.1.3 d', 'months' => 12, 'roles' => ['ROLE_ISB', 'ROLE_CISO']],
        'crypto_key_rotation' => ['module' => 'controls', 'scope' => null, 'norm' => 'ISO 27001 A.8.24', 'months' => 12, 'roles' => ['ROLE_ISB', 'ROLE_CISO']],
        'access_review_campaign' => ['module' => 'access_review', 'scope' => null, 'norm' => 'ISO 27001 A.5.18', 'months' => 6, 'roles' => ['ROLE_ISB', 'ROLE_CISO']],
        'bia_review' => ['module' => 'bcm', 'scope' => null, 'norm' => 'ISO 22301 8.2.2', 'months' => 12, 'roles' => ['ROLE_BCM_OFFICER']],
        'bc_exercise' => ['module' => 'bcm', 'scope' => null, 'norm' => 'ISO 22301 8.5', 'months' => 12, 'roles' => ['ROLE_BCM_OFFICER']],
        'tom_review' => ['module' => 'privacy', 'scope' => null, 'norm' => 'DSGVO Art. 32 (1) d', 'months' => 12, 'roles' => ['ROLE_DPO']],
        'nis2_measures_review' => ['module' => 'nis2_dora', 'scope' => self::SCOPE_NIS2, 'norm' => 'NIS-2 Art. 21', 'months' => 12, 'roles' => ['ROLE_ISB', 'ROLE_CISO']],
        'dora_register_of_information' => ['module' => 'nis2_dora', 'scope' => self::SCOPE_DORA, 'norm' => 'DORA Art. 28 (3)', 'months' => 12, 'roles' => ['ROLE_ISB', 'ROLE_COMPLIANCE_MANAGER']],
        'dora_exit_plans' => ['module' => 'nis2_dora', 'scope' => self::SCOPE_DORA, 'norm' => 'DORA Art. 28 (8)', 'months' => 12, 'roles' => ['ROLE_ISB', 'ROLE_COMPLIANCE_MANAGER']],
    ];

    /** @return array{module: string, scope: ?string, norm: string, months: int, roles: list<string>}|null */
    public static function get(string $key): ?array
    {
        return self::DUTIES[$key] ?? null;
    }

    /** Whether the duty's scope covers the organisation (the module is checked separately). */
    public static function inScope(string $key, Tenant $tenant): bool
    {
        return match (self::DUTIES[$key]['scope'] ?? null) {
            self::SCOPE_NIS2 => $tenant->isNis2Regulated(),
            self::SCOPE_DORA => $tenant->isDoraObligated(),
            default => isset(self::DUTIES[$key]),
        };
    }
}
