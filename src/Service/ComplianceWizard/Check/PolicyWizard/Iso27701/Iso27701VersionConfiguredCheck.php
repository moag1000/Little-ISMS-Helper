<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Iso27701;

use App\Entity\Tenant;
use App\Repository\TenantPolicySettingRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;
use App\Service\TenantSettingResolver\PolicySettingProvider;

/**
 * W6-D / ISO 27701:2025 §3 — confirms the tenant has set the
 * `iso27701.version` setting if `iso27701.enabled` is true.
 *
 * Per `docs/plans/policy-wizard/06-dpo-input.md` §3, ISO 27701:2025
 * superseded the 2019 edition in 2025-09 and renumbered several clauses
 * (notably 6.13 was a sub-clause `6.13.1.5` in 2019). When a tenant
 * opts into the PIMS addon, they MUST declare which edition drives
 * the clause set — otherwise the wizard cannot pick the right tag set
 * and the SoA mapping is undefined.
 *
 * Trigger: `iso27701.enabled = true` (else vacuously satisfied).
 *
 * Evidence: `iso27701.version` setting set to one of the allowed
 * values (`2019` or `2025`).
 *
 * Note: the {@see PolicySettingProvider::resolveIso27701Version()}
 * resolver returns the default `2025` on missing/invalid values, so
 * we look at the raw stored row to detect "unset" vs. "explicitly
 * defaulted". This keeps the audit trail clean — an explicit
 * `version=2025` row is a deliberate choice.
 */
final class Iso27701VersionConfiguredCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'iso27701_version_configured';
    private const STANDARD = 'iso27701';

    public function __construct(
        private readonly PolicySettingProvider $policySettingProvider,
        private readonly TenantPolicySettingRepository $tenantPolicySettingRepository,
    ) {
    }

    public function getCheckId(): string
    {
        return self::CHECK_ID;
    }

    public function getStandard(): string
    {
        return self::STANDARD;
    }

    public function run(?Tenant $tenant): PolicyWizardCheckResult
    {
        if ($tenant === null) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 0.0,
                passed: false,
                details: ['reason' => 'no_tenant'],
            );
        }

        if (!$this->policySettingProvider->isIso27701Enabled($tenant)) {
            // PIMS addon not opted in — version setting irrelevant.
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'iso27701_enabled' => false,
                    'reason' => 'pims_not_enabled',
                ],
            );
        }

        $setting = $this->tenantPolicySettingRepository->findOneByTenantAndKey(
            $tenant,
            PolicySettingProvider::SETTING_ISO27701_VERSION,
        );

        if ($setting === null) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 0.0,
                passed: false,
                details: [
                    'iso27701_enabled' => true,
                    'reason' => 'version_setting_missing',
                ],
                gap: [
                    'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                    'priority' => 'high',
                    'route' => 'app_policy_wizard_index',
                    'translation_domain' => 'policy_wizard',
                ],
            );
        }

        $value = $setting->getValue();
        $allowed = PolicySettingProvider::ISO27701_VERSIONS;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 0.0,
                passed: false,
                details: [
                    'iso27701_enabled' => true,
                    'configured_value' => $value,
                    'allowed_values' => $allowed,
                    'reason' => 'version_setting_invalid',
                ],
                gap: [
                    'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                    'priority' => 'high',
                    'route' => 'app_policy_wizard_index',
                    'translation_domain' => 'policy_wizard',
                ],
            );
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: 100.0,
            passed: true,
            details: [
                'iso27701_enabled' => true,
                'iso27701_version' => $value,
            ],
        );
    }
}
