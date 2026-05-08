<?php

declare(strict_types=1);

namespace App\Service\TenantSettingResolver;

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
}
