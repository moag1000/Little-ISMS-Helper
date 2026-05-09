<?php

declare(strict_types=1);

namespace App\Service\TenantSettingResolver;

/**
 * Policy-Wizard W1 — sink for blocked relax-attempt records.
 *
 * The resolver detects "drift" cases where a child tenant has a stored
 * value that no longer satisfies a parent's override-mode (e.g. parent
 * just raised the crypto floor; child's old value is now too low).
 * Each detection produces a RelaxAttempt that this interface persists
 * for audit + the §7.4 push-down dashboard.
 *
 * W1-A introduces the `TenantPolicySettingChangeAttempt` entity; its
 * Doctrine repository will implement this interface. Until then, a
 * NullChangeAttemptLogger keeps the resolver functional and tests
 * deterministic.
 */
interface ChangeAttemptLoggerInterface
{
    public function log(RelaxAttempt $attempt): void;
}
