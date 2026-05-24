<?php

declare(strict_types=1);

namespace App\AlvaHint\FormRule;

use App\AlvaHint\AlvaFormHint;
use App\AlvaHint\AlvaHintFormRuleInterface;
use App\Entity\User;

/**
 * P-19 Form-Step-Inline-Hint — BusinessProcess form: RPO/RTO imbalance.
 *
 * Fires when the user enters a Recovery-Point-Objective (RPO, max
 * tolerated data loss in hours) that is larger than half of the
 * Recovery-Time-Objective (RTO, max tolerated downtime in hours). The
 * heuristic — RPO > RTO/2 — flags configurations where backups would
 * effectively not finish before the system is required back online,
 * meaning the BC-strategy will silently miss its own targets.
 *
 * Reference: ISO 22301 Cl. 8.2.2 "the organization shall determine ...
 * the maximum acceptable outage and the time within which products /
 * services are to be resumed". Pre-save heads-up only (tier `warning`).
 */
final class BusinessProcessRecoveryChainImbalanceInlineRule implements AlvaHintFormRuleInterface
{
    public function key(): string
    {
        return 'business_process.form.rpo_exceeds_rto_half';
    }

    public function entityType(): string
    {
        return 'business_process';
    }

    public function requiredModules(): array
    {
        return ['bcm'];
    }

    public function requiredRoles(): array
    {
        return [];
    }

    public function supports(array $payload, User $user): bool
    {
        $rpo = $this->intOrNull($payload['rpo'] ?? null);
        $rto = $this->intOrNull($payload['rto'] ?? null);

        if ($rpo === null || $rto === null) {
            return false;
        }
        // Zero / negative numbers are nonsensical here — only meaningful
        // positive ranges trigger the heuristic.
        if ($rpo <= 0 || $rto <= 0) {
            return false;
        }

        // RPO > RTO / 2 — using integer arithmetic so a 4h RTO with a 3h
        // RPO (3 > 2) fires correctly without floating-point edge cases.
        return ($rpo * 2) > $rto;
    }

    public function evaluate(array $payload, User $user): AlvaFormHint
    {
        $rpo = $this->intOrNull($payload['rpo'] ?? null) ?? 0;
        $rto = $this->intOrNull($payload['rto'] ?? null) ?? 0;

        return new AlvaFormHint(
            key: $this->key(),
            field: 'rpo',
            tier: 'warning',
            titleTranslationKey: 'alva_hint.form.business_process_recovery_chain_imbalance.title',
            bodyTranslationKey: 'alva_hint.form.business_process_recovery_chain_imbalance.body',
            translationDomain: 'alva',
            bodyParams: [
                '%rpo%' => $rpo,
                '%rto%' => $rto,
            ],
            mood: 'warning',
        );
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }
}
