<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\AuditLogger;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * F7 Field-Level RBAC resolver.
 *
 * Holds a declarative MAP of sensitive fields → minimum required role.
 * All internal roles (ROLE_MANAGER and above) see all fields.
 * External/read-only roles (ROLE_AUDITOR, ROLE_KONZERN_AUDITOR) are
 * denied the sensitive field groups defined here.
 *
 * ISO 27001 A.5.18 least-privilege + A.8.15 logging.
 *
 * ── Sensitive-field MAP ────────────────────────────────────────────────
 * Risk::owner          → ROLE_MANAGER  (identity of risk owner — User ref)
 * Risk::ownerPerson    → ROLE_MANAGER  (identity of risk owner — Person ref)
 * Risk::ownerDeputies  → ROLE_MANAGER  (deputy Person list — reveals names)
 * Risk::sle            → ROLE_MANAGER  (Single Loss Expectancy €, F46 ALE)
 * Risk::aro            → ROLE_MANAGER  (Annual Rate of Occurrence, F46 ALE)
 * Risk::ale            → ROLE_MANAGER  (computed ALE = SLE×ARO, F46)
 *
 * Threshold rationale: ROLE_AUDITOR and ROLE_KONZERN_AUDITOR both inherit
 * only ROLE_USER (not ROLE_MANAGER) per security.yaml role_hierarchy.
 * ROLE_MANAGER is the lowest internal operational role NOT reachable by
 * external read-only roles, so it is the correct threshold for sensitive
 * personal / monetary fields without over-restricting any internal role.
 *
 * NOTE: FTE entities (FteTrackingMetric, FteCalibrationConstant) store
 * minutes-per-operation — not salary or monetary data. No FTE field is in
 * this MAP. The FteTrackingVoter already requires ROLE_MANAGER for VIEW,
 * which is above the external-auditor level; that entity-level gate is
 * sufficient and correct for the FTE module.
 *
 * ── Deny-log deduplication ────────────────────────────────────────────
 * A deny event is logged at most ONCE per (entityClass, fieldKey) pair
 * per resolver instance (= per request in the DI container). This
 * prevents log flooding when a template checks the same field multiple
 * times (e.g. in a list rendering 50 Risk rows).
 */
final class FieldVisibilityResolver
{
    public const string ACTION_FIELD_ACCESS_DENIED = 'field.access.denied';

    /**
     * MAP: entityClass (short name) → fieldKey → minimum required role.
     *
     * Only genuinely sensitive fields are listed here.
     * Any field NOT in this MAP is treated as non-sensitive and visible
     * to all authenticated users — additive, non-breaking default.
     *
     * @var array<string, array<string, string>>
     */
    private const array FIELD_ROLE_MAP = [
        'Risk' => [
            'owner'         => 'ROLE_MANAGER', // riskOwner (User entity ref) — reveals person identity
            'ownerPerson'   => 'ROLE_MANAGER', // riskOwnerPerson (Person ref) — reveals person identity
            'ownerDeputies' => 'ROLE_MANAGER', // riskOwnerDeputyPersons — deputy list, same PII concern
            'sle'           => 'ROLE_MANAGER', // singleLossExpectancy €  — F46 monetary field
            'aro'           => 'ROLE_MANAGER', // annualRateOfOccurrence  — F46 monetary field
            'ale'           => 'ROLE_MANAGER', // annualLossExpectancy (computed SLE×ARO) — F46
        ],
    ];

    /** Deny-log deduplication: "EntityClass::fieldKey" strings already logged this request. */
    private array $loggedDenies = [];

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Returns true when $user may view $fieldKey on $entityClass.
     *
     * Defaults to true for fields not listed in the MAP (additive,
     * non-breaking — internal roles see all fields unchanged).
     * Returns false for null user on any restricted field.
     *
     * @param string             $entityClass Short class name, e.g. 'Risk'
     * @param string             $fieldKey    Key from FIELD_ROLE_MAP, e.g. 'owner'
     * @param UserInterface|null $user        Currently authenticated user, or null
     */
    public function canViewField(string $entityClass, string $fieldKey, ?UserInterface $user): bool
    {
        $requiredRole = self::FIELD_ROLE_MAP[$entityClass][$fieldKey] ?? null;

        // Field not in the sensitive MAP → always visible (non-breaking default).
        if ($requiredRole === null) {
            return true;
        }

        // No authenticated user → deny restricted field.
        if ($user === null) {
            return false;
        }

        $reachable = $this->roleHierarchy->getReachableRoleNames($user->getRoles());

        if (in_array($requiredRole, $reachable, true)) {
            return true;
        }

        // ── Deny path: log once per (entityClass, fieldKey) per request ──
        $dedupKey = $entityClass . '::' . $fieldKey;
        if (!in_array($dedupKey, $this->loggedDenies, true)) {
            $this->loggedDenies[] = $dedupKey;
            $this->auditLogger->logCustom(
                action: self::ACTION_FIELD_ACCESS_DENIED,
                entityType: $entityClass,
                description: sprintf(
                    'Field "%s" access denied for role(s) [%s] (requires %s)',
                    $fieldKey,
                    implode(', ', $user->getRoles()),
                    $requiredRole,
                ),
            );
        }

        return false;
    }

    /**
     * Returns the full sensitive-field MAP for inspection / tests.
     *
     * @return array<string, array<string, string>>
     */
    public static function getFieldRoleMap(): array
    {
        return self::FIELD_ROLE_MAP;
    }
}
