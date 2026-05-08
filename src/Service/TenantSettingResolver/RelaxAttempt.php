<?php

declare(strict_types=1);

namespace App\Service\TenantSettingResolver;

/**
 * Policy-Wizard W1 — descriptor for a single blocked relax attempt
 * detected during a resolution walk.
 *
 * Emitted by TenantSettingResolver and forwarded to the
 * ChangeAttemptLoggerInterface for persistence (e.g. into the
 * `TenantPolicySettingChangeAttempt` table introduced by W1-A).
 *
 * The §7.4 drift detection scenario:
 * Konzern raises crypto floor 128 → 256. A subsidiary still has 192
 * stored. On next read the resolver clamps the result up to 256,
 * emits a RelaxAttempt for the subsidiary tenant id, and the logger
 * records the drift so the wizard can flag it on the landing page.
 */
final class RelaxAttempt
{
    public function __construct(
        public readonly int|string|null $tenantId,
        public readonly string $key,
        public readonly mixed $attemptedValue,
        public readonly mixed $enforcedValue,
        public readonly OverrideMode $mode,
        public readonly int|string|null $blockedByTenantId,
    ) {
    }
}
