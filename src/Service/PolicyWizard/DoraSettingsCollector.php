<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\WizardRun;

/**
 * Policy-Wizard W4-A — DoraSettingsCollector.
 *
 * Pulls DORA-specific settings out of {@see WizardRun}::inputs slot
 * `operational_baselines.dora` and returns a flat
 * `[varName => value]` map for {@see VariableCollector}-side
 * composition. Used by DocumentGenerator render-time substitution and
 * by the DORA-extension section append in §3 of the W4-A spec.
 *
 * Inputs (architecture §6 Step 5b):
 *   - entity_type             — credit_institution / investment_firm /
 *                               crypto_asset_provider / trading_venue /
 *                               insurance / other
 *   - significance            — bool (drives Art. 24-27 TLPT scope)
 *   - competent_authority     — BaFin / EZB / EIOPA / national / other
 *   - concentration_thresholds— JSON map per critical-function tier
 *
 * Validity-from is a hard-coded constant (`2025-01-17`) per DORA Art. 64
 * — the date is regulatory and not tenant-overridable.
 *
 * The collector returns flat keys with the `dora.` prefix so the
 * variable namespace stays distinct from the ISO `tenant.*` and
 * `roles.*` namespaces fed by {@see VariableCollector}. Empty inputs
 * resolve to `null` (NOT empty strings) so substitutions for missing
 * settings render as a blank — never as a leftover `{{ }}` marker
 * (architecture §11.2 invariant).
 */
final class DoraSettingsCollector
{
    public const string DORA_VALIDITY_FROM = '2025-01-17';

    /** @var list<string> */
    public const array ALLOWED_ENTITY_TYPES = [
        'credit_institution',
        'investment_firm',
        'crypto_asset_provider',
        'trading_venue',
        'insurance',
        'other',
    ];

    /** @var list<string> */
    public const array ALLOWED_AUTHORITIES = [
        'BaFin',
        'EZB',
        'EIOPA',
        'national',
        'other',
    ];

    /**
     * Collect every DORA-specific variable known for this run.
     *
     * Returns a flat map ready for merge into {@see VariableCollector}'s
     * output. Keys are namespaced under `dora.*`.
     *
     * @return array<string, scalar|null>
     *
     * @throws InvalidArgumentException when `entity_type` is supplied
     *         but does not match {@see ALLOWED_ENTITY_TYPES}. We let
     *         the exception bubble — the wizard validation step
     *         catches invalid input long before the collector is
     *         called; reaching this code path with an invalid value
     *         indicates a bypass and is worth surfacing loudly.
     */
    public function collectFor(WizardRun $run): array
    {
        $inputs = $run->getInputs() ?? [];
        $opsSlot = $inputs[WizardStepKeys::STEP_OPERATIONAL_BASELINES] ?? [];
        $doraBlock = is_array($opsSlot) && is_array($opsSlot['dora'] ?? null)
            ? $opsSlot['dora']
            : [];

        $entityType = $this->normaliseString($doraBlock['entity_type'] ?? null);
        if ($entityType !== null && !in_array($entityType, self::ALLOWED_ENTITY_TYPES, true)) {
            throw new \App\Exception\InvalidArgument\InvalidArgumentException(sprintf(
                'DoraSettingsCollector: unknown entity_type "%s"; allowed: %s',
                $entityType,
                implode(', ', self::ALLOWED_ENTITY_TYPES),
            ));
        }

        $significance = $this->normaliseSignificance($doraBlock['significance'] ?? null);

        $authority = $this->normaliseString($doraBlock['competent_authority'] ?? null);
        if ($authority !== null && !in_array($authority, self::ALLOWED_AUTHORITIES, true)) {
            // Unknown authority is treated as 'other' — still recorded
            // verbatim so downstream rendering can show the raw value.
            // No exception: tenant-side mis-configuration of the NCA
            // string is a soft-warning case, not a hard validation.
        }

        $thresholds = $this->normaliseThresholds($doraBlock['concentration_thresholds'] ?? null);

        return [
            'dora.entity_type' => $entityType,
            'dora.significance' => $significance,
            'dora.competent_authority' => $authority,
            'dora.concentration_thresholds' => $thresholds,
            'dora.validity_from' => self::DORA_VALIDITY_FROM,
        ];
    }

    private function normaliseString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function normaliseSignificance(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            return match ($lower) {
                'true', 'yes', '1', 'y' => true,
                'false', 'no', '0', 'n', '' => false,
                default => null,
            };
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        return null;
    }

    /**
     * Concentration thresholds collapse to a JSON-encoded scalar so
     * substitution markers can render the raw map without leaking PHP
     * array notation. Returning the JSON string keeps the variable
     * scalar-typed (matches {@see VariableCollector::collectFor}).
     */
    private function normaliseThresholds(mixed $value): ?string
    {
        if ($value === null || $value === [] || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }
        if (is_array($value)) {
            try {
                return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            } catch (\JsonException) {
                return null;
            }
        }
        return null;
    }
}
