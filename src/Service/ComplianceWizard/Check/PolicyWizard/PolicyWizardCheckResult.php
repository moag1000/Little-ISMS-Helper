<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard;

/**
 * Immutable result of a Policy-Wizard Compliance-Check.
 *
 * Mirrors the shape that {@see \App\Service\ComplianceWizardService::runCheck()}
 * already emits for legacy switch-driven check-types: a numeric `score` (0-100),
 * a `details` array consumable by the wizard UI and an optional `gap` payload
 * surfaced inside the gap-list. Keeping the shape compatible lets the existing
 * wizard merge Policy-Wizard checks into a category result without a special
 * adapter — see W3-D / `07-phase4-sprint-reconciliation.md` §3.
 */
final class PolicyWizardCheckResult
{
    /**
     * @param array<string, mixed>      $details
     * @param array<string, mixed>|null $gap
     */
    public function __construct(
        public readonly string $checkId,
        public readonly float $score,
        public readonly bool $passed,
        public readonly array $details = [],
        public readonly ?array $gap = null,
    ) {
    }

    /**
     * Compatibility helper: emit the same array shape that legacy
     * `runCheck()` consumers expect.
     *
     * @return array{check_id: string, score: float, passed: bool, details: array<string, mixed>, gap: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'check_id' => $this->checkId,
            'score' => $this->score,
            'passed' => $this->passed,
            'details' => $this->details,
            'gap' => $this->gap,
        ];
    }
}
