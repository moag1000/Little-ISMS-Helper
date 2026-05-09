<?php

declare(strict_types=1);

namespace App\Service\TenantSettingResolver;

/**
 * Policy-Wizard W1 — no-op logger used when the W1-A
 * `TenantPolicySettingChangeAttempt` repository is not yet wired in.
 * Keeps the resolver functional in environments where drift-attempts
 * are intentionally not persisted (e.g. unit tests).
 */
final class NullChangeAttemptLogger implements ChangeAttemptLoggerInterface
{
    public function log(RelaxAttempt $attempt): void
    {
        // intentionally empty
    }
}
