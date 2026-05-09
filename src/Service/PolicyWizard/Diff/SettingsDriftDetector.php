<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Diff;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Repository\TenantPolicySettingRepository;

/**
 * Policy-Wizard W7-C — Settings-Drift detector.
 *
 * Per the ISB practitioner review (`05-isb-practitioner-review.md` lines
 * 184-189) generated policies need a "settings have changed since this
 * was generated" indicator on the Document index. Today drift is only
 * detected on the NEXT wizard-run; this detector surfaces it eagerly
 * so users can see "re-run wizard" CTAs without round-tripping through
 * the wizard.
 *
 * Strategy:
 *  - Walk every tenant-derived value in `Document.substitutionVariables`
 *    that we know how to re-derive from the current tenant + setting
 *    table state (legal_name, scope_statement, isms.* settings).
 *  - When ANY re-derived value diverges from the stored snapshot →
 *    `detectDriftFor()` returns true.
 *  - System keys (`_hash`, `_template_version`, …) are NEVER consulted
 *    — they encode generator-internal bookkeeping and would always
 *    differ across re-runs.
 *
 * Defensive: missing relations (no tenant, no settings repo, no snapshot)
 * never throw; they degrade to "no drift" so a partially-wired Document
 * row does not light up the badge spuriously.
 */
final class SettingsDriftDetector
{
    /**
     * Setting keys we re-resolve from {@see TenantPolicySettingRepository}
     * to compare against the stored snapshot. Mirrors the
     * `VariableCollector::settingValueAsString` lookup so the detector
     * stays honest about what the wizard actually substitutes.
     *
     * @var array<string, string> snapshot-key => settings-key
     */
    private const array SETTING_KEY_MAP = [
        'tenant.scope_statement' => 'isms.scope_statement',
    ];

    public function __construct(
        private readonly ?TenantPolicySettingRepository $settingRepository = null,
    ) {
    }

    /**
     * Whether the given Document has settings drift relative to the
     * supplied tenant. Returns false safely when no snapshot exists or
     * when the relations needed to re-derive are missing.
     */
    public function detectDriftFor(Document $document, ?Tenant $tenant = null): bool
    {
        $tenant ??= $document->getTenant();
        if ($tenant === null) {
            return false;
        }
        $snapshot = $document->getSubstitutionVariables();
        if (!is_array($snapshot) || $snapshot === []) {
            return false;
        }

        // 1) Tenant-direct fields (legal_name) — re-derive from the
        //    Tenant entity itself.
        if (array_key_exists('tenant.legal_name', $snapshot)) {
            $current = $tenant->getLegalName() ?? $tenant->getName();
            if (!$this->snapshotMatchesCurrent($snapshot['tenant.legal_name'], $current)) {
                return true;
            }
        }

        // 2) Tenant-id pin — Document moved between tenants? That is
        //    drift by any reasonable definition.
        if (array_key_exists('tenant.id', $snapshot)) {
            $currentId = $tenant->getId();
            if ($currentId !== null && (int) $snapshot['tenant.id'] !== $currentId) {
                return true;
            }
        }

        // 3) Setting-table-backed values — only consulted when the
        //    repo is wired. Tests / lightweight DI graphs can omit it
        //    and still get sensible drift detection on the direct fields.
        if ($this->settingRepository !== null) {
            foreach (self::SETTING_KEY_MAP as $snapKey => $settingKey) {
                if (!array_key_exists($snapKey, $snapshot)) {
                    continue;
                }
                $stored = $this->settingRepository->findOneByTenantAndKey($tenant, $settingKey);
                $currentValue = $this->extractSettingValue($stored?->getValue());
                if (!$this->snapshotMatchesCurrent($snapshot[$snapKey], $currentValue)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract the comparable scalar from a TenantPolicySetting value.
     * The schema accepts both raw scalars and the `{value: …}` wrapper,
     * mirroring the mirror-image accessor in VariableCollector.
     */
    private function extractSettingValue(mixed $rawSettingValue): mixed
    {
        if ($rawSettingValue === null) {
            return null;
        }
        if (is_array($rawSettingValue)) {
            if (array_key_exists('value', $rawSettingValue)) {
                return $rawSettingValue['value'];
            }
            return null;
        }
        return $rawSettingValue;
    }

    /**
     * Pragmatic equality: numeric-aware (int/float coercion) and
     * empty-string ↔ null tolerant so a "" snapshot vs a current null
     * does not count as drift.
     */
    private function snapshotMatchesCurrent(mixed $snapshot, mixed $current): bool
    {
        if (($snapshot === null || $snapshot === '') && ($current === null || $current === '')) {
            return true;
        }
        if (is_numeric($snapshot) && is_numeric($current)) {
            return (float) $snapshot === (float) $current;
        }
        return $snapshot === $current;
    }
}
