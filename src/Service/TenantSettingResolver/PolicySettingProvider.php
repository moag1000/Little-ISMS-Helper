<?php

declare(strict_types=1);

namespace App\Service\TenantSettingResolver;

use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Policy-Wizard setting-key registry + safe-resolution helper.
 *
 * Currently a thin facade over {@see TenantSettingResolver} that
 * exposes the policy-wizard keys as constants and applies defensive
 * fallbacks (return the default on any resolver error). New keys
 * should land here so consumers can refer to a single source of truth
 * instead of stringly-typed lookups.
 *
 * W5-A: introduces `bsi.tier_filter` — see {@see SETTING_BSI_TIER_FILTER}.
 * W6-B: introduces `iso27701.enabled` + `iso27701.version` — see
 *       {@see SETTING_ISO27701_ENABLED} and
 *       {@see SETTING_ISO27701_VERSION}.
 */
final class PolicySettingProvider
{
    /**
     * BSI Vorgehensweise filter applied by the DocumentGenerator. The
     * tenant's choice decides whether the wizard ships only Basis-
     * Pflicht-Set (`basis_only`), Basis + Standard (`up_to_standard`),
     * or every BSI template including the high-effort Kern-Absicherung
     * (`kern_full`).
     *
     * Allowed values: see {@see TIER_FILTER_VALUES}.
     * Default: {@see TIER_FILTER_BASIS_ONLY}.
     */
    public const string SETTING_BSI_TIER_FILTER = 'bsi.tier_filter';

    public const string TIER_FILTER_BASIS_ONLY = 'basis_only';
    public const string TIER_FILTER_UP_TO_STANDARD = 'up_to_standard';
    public const string TIER_FILTER_KERN_FULL = 'kern_full';

    /** @var list<string> */
    public const array TIER_FILTER_VALUES = [
        self::TIER_FILTER_BASIS_ONLY,
        self::TIER_FILTER_UP_TO_STANDARD,
        self::TIER_FILTER_KERN_FULL,
    ];

    public const string TIER_FILTER_DEFAULT = self::TIER_FILTER_BASIS_ONLY;

    /**
     * ISO 27701 PIMS opt-in flag — when true, the document generator
     * emits clause-level mapping tags (`iso27701:5.1`, `iso27701:7.2.8`,
     * etc.) for every policy template that carries a clause mapping.
     *
     * Default: false (PIMS is a parallel addon, opt-in like DORA per
     * `06-dpo-input.md` §3.3).
     */
    public const string SETTING_ISO27701_ENABLED = 'iso27701.enabled';

    /**
     * ISO 27701 edition driving the clause set. ISO/IEC 27701:2025
     * superseded :2019 in 2025-09 and renumbered several clauses
     * (notably 6.13 was a sub-clause `6.13.1.5` in 2019). Tenants on
     * legacy audit cycles can keep the 2019 numbering active until
     * their next surveillance audit.
     *
     * Allowed values: see {@see ISO27701_VERSIONS}.
     * Default: {@see ISO27701_VERSION_DEFAULT} (= 2025).
     */
    public const string SETTING_ISO27701_VERSION = 'iso27701.version';

    public const string ISO27701_VERSION_2019 = '2019';
    public const string ISO27701_VERSION_2025 = '2025';

    /** @var list<string> */
    public const array ISO27701_VERSIONS = [
        self::ISO27701_VERSION_2019,
        self::ISO27701_VERSION_2025,
    ];

    public const string ISO27701_VERSION_DEFAULT = self::ISO27701_VERSION_2025;

    public function __construct(
        private readonly TenantSettingResolver $resolver,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Resolve `bsi.tier_filter` for the tenant, falling back to the
     * default on any error or unknown stored value. Never throws —
     * production code paths cannot regress because of a setting-pipeline
     * outage.
     */
    public function resolveBsiTierFilter(?Tenant $tenant): string
    {
        if (!$tenant instanceof Tenant) {
            return self::TIER_FILTER_DEFAULT;
        }
        try {
            $resolution = $this->resolver->resolveFor(
                $tenant,
                self::SETTING_BSI_TIER_FILTER,
                self::TIER_FILTER_DEFAULT,
            );
            $value = $resolution->getValue();
            if (is_string($value) && in_array($value, self::TIER_FILTER_VALUES, true)) {
                return $value;
            }
            return self::TIER_FILTER_DEFAULT;
        } catch (Throwable $error) {
            $this->logger->warning(
                'PolicySettingProvider: bsi.tier_filter resolution failed; using default',
                [
                    'tenant_id' => $tenant->getId(),
                    'default' => self::TIER_FILTER_DEFAULT,
                    'error' => $error->getMessage(),
                ],
            );
            return self::TIER_FILTER_DEFAULT;
        }
    }

    /**
     * Decide whether a template marked with the given `bsi_tier` should
     * be emitted under the resolved tier filter:
     *
     *   filter = basis_only      → only `basis` (or NULL) ships
     *   filter = up_to_standard  → `basis` + `standard` (or NULL)
     *   filter = kern_full       → every tier ships
     *
     * Templates without a `bsi_tier` (NULL) ship in every filter mode —
     * only BSI-tiered rows are gated. Unknown filter values fall back
     * to {@see TIER_FILTER_DEFAULT} so a misconfigured tenant cannot
     * accidentally over-ship.
     */
    public function tierAllowedUnderFilter(?string $bsiTier, string $filter): bool
    {
        if ($bsiTier === null) {
            return true;
        }
        $effective = in_array($filter, self::TIER_FILTER_VALUES, true)
            ? $filter
            : self::TIER_FILTER_DEFAULT;

        return match ($effective) {
            self::TIER_FILTER_KERN_FULL => true,
            self::TIER_FILTER_UP_TO_STANDARD => $bsiTier !== 'kern',
            // basis_only — anything not 'basis' is gated
            default => $bsiTier === 'basis',
        };
    }

    /**
     * Resolve `iso27701.enabled` for the tenant. Returns the default
     * (false) on any error. Never throws.
     */
    public function isIso27701Enabled(?Tenant $tenant): bool
    {
        if (!$tenant instanceof Tenant) {
            return false;
        }
        try {
            $resolution = $this->resolver->resolveFor(
                $tenant,
                self::SETTING_ISO27701_ENABLED,
                false,
            );
            $value = $resolution->getValue();
            return $value === true || $value === 1 || $value === '1' || $value === 'true';
        } catch (Throwable $error) {
            $this->logger->warning(
                'PolicySettingProvider: iso27701.enabled resolution failed; using default',
                [
                    'tenant_id' => $tenant->getId(),
                    'default' => false,
                    'error' => $error->getMessage(),
                ],
            );
            return false;
        }
    }

    /**
     * Resolve `iso27701.version` for the tenant, falling back to
     * {@see ISO27701_VERSION_DEFAULT} (= 2025) on any error or unknown
     * stored value. Never throws.
     */
    public function resolveIso27701Version(?Tenant $tenant): string
    {
        if (!$tenant instanceof Tenant) {
            return self::ISO27701_VERSION_DEFAULT;
        }
        try {
            $resolution = $this->resolver->resolveFor(
                $tenant,
                self::SETTING_ISO27701_VERSION,
                self::ISO27701_VERSION_DEFAULT,
            );
            $value = $resolution->getValue();
            if (is_string($value) && in_array($value, self::ISO27701_VERSIONS, true)) {
                return $value;
            }
            return self::ISO27701_VERSION_DEFAULT;
        } catch (Throwable $error) {
            $this->logger->warning(
                'PolicySettingProvider: iso27701.version resolution failed; using default',
                [
                    'tenant_id' => $tenant->getId(),
                    'default' => self::ISO27701_VERSION_DEFAULT,
                    'error' => $error->getMessage(),
                ],
            );
            return self::ISO27701_VERSION_DEFAULT;
        }
    }

    /**
     * Build the list of `iso27701:<clause>` tags that should be
     * appended to a document generated from the given template, given
     * the tenant's PIMS opt-in state.
     *
     * Returns an empty list when:
     *   • the tenant has not enabled `iso27701.enabled`, OR
     *   • the template carries no clause mapping for the resolved version.
     *
     * The version-fallback rule is asymmetric per `06-dpo-input.md`
     * §3.2: when the resolved version is 2025 but the template only
     * declares 2019 clauses (legacy seed), we fall back to the 2019
     * list rather than emit nothing — and vice-versa. This keeps the
     * tag pipeline working through partial migrations.
     *
     * Pure function over (template, tenant) — safe to call from any
     * code path, no side effects on the entity.
     *
     * @return list<string> e.g. `['iso27701:5.1','iso27701:7.2.8']`
     */
    public function tagDocumentWithIso27701(PolicyTemplate $template, ?Tenant $tenant): array
    {
        if (!$this->isIso27701Enabled($tenant)) {
            return [];
        }
        $clauses = $this->resolveClausesForTemplate($template, $this->resolveIso27701Version($tenant));
        if ($clauses === []) {
            return [];
        }
        $tags = [];
        foreach ($clauses as $clause) {
            $tags[] = 'iso27701:' . $clause;
        }
        return $tags;
    }

    /**
     * Pick the right clause list given the resolved `iso27701.version`
     * with asymmetric fallback when the template only declares one
     * version (legacy / partial seed).
     *
     * @return list<string>
     */
    private function resolveClausesForTemplate(PolicyTemplate $template, string $version): array
    {
        $primary = $version === self::ISO27701_VERSION_2019
            ? $template->getIso27701Clauses2019()
            : $template->getIso27701Clauses2025();
        if (is_array($primary) && $primary !== []) {
            return array_values($primary);
        }
        // Fallback: opposite-version mapping if primary is empty/null.
        $fallback = $version === self::ISO27701_VERSION_2019
            ? $template->getIso27701Clauses2025()
            : $template->getIso27701Clauses2019();
        if (is_array($fallback) && $fallback !== []) {
            return array_values($fallback);
        }
        return [];
    }
}
