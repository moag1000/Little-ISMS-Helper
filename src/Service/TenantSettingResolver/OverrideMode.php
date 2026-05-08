<?php

declare(strict_types=1);

namespace App\Service\TenantSettingResolver;

/**
 * Policy-Wizard W1 — Override-Mode-Matrix per architecture §7.3.
 *
 * Defines, for a given setting key, how a child tenant in the holding
 * hierarchy may override the value coming from an ancestor.
 *
 * Mode names match the ISB-Practitioner review terminology in
 * `docs/plans/policy-wizard/05-architecture.md` §7.3.
 */
enum OverrideMode: string
{
    /**
     * Child cannot modify in any direction. Parent value wins;
     * any child value is silently discarded by the resolver and
     * surfaces as a blocked-relax attempt in the audit log.
     */
    case ForbiddenToChange = 'forbidden_to_change';

    /**
     * Child can tighten, never loosen. For ordinal numerics this
     * means child >= parent; for booleans, parent=true forces
     * child=true. Child stays = ok.
     */
    case ForbiddenToRelax = 'forbidden_to_relax';

    /**
     * Parent value is the floor; child may go higher (numerically).
     * Examples: cryptography minimum-key-length.
     */
    case FloorOnly = 'floor_only';

    /**
     * Parent value is the ceiling; child may go lower (numerically).
     * Examples: review-interval-months, backup-RPO-hours,
     * risk-appetite-tier (lower number = more conservative).
     */
    case CeilingOnly = 'ceiling_only';

    /**
     * Child fully autonomous. Parent value (if any) is ignored.
     */
    case Free = 'free';
}
