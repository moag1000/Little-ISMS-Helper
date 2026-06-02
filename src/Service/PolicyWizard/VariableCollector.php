<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\TenantPolicySettingRepository;
use App\Repository\UserRepository;

/**
 * Policy-Wizard W3 — VariableCollector.
 *
 * Pulls existing tenant data + WizardRun.inputs into a flat
 * variable bag for body substitution. Centralises the "do not make
 * the user re-type the legal name" promise from architecture §11.2 +
 * §6 Step 2-5.
 *
 * Returns a flat `[varName => value]` map. Keys mirror the
 * `{{ tenant.legal_name }}` style markers used in `policy.*.body`
 * translation strings. The collector NEVER injects raw template
 * markers into the result; markers without a known source resolve
 * to an empty string so the §11.2 "no leftover {{ }}" guarantee
 * holds for every render.
 */
class VariableCollector
{
    public function __construct(
        private readonly TenantPolicySettingRepository $settingRepository,
        private readonly UserRepository $userRepository,
        // Nullable so existing 2-arg test construction keeps working; autowired
        // in production. When present, effective policy-parameter values are
        // merged as {{ policy.* }} interpolation variables.
        private readonly ?\App\Repository\OrganizationSecurityProfileRepository $profileRepository = null,
        private readonly ?\App\Service\PolicyParameter\PolicyProfileManager $profileManager = null,
        private readonly ?\App\Service\PolicyParameter\PolicyParameterVariables $parameterVariables = null,
    ) {
    }

    /**
     * Collect every variable known for this run.
     *
     * @return array<string, scalar|null>
     */
    public function collectFor(WizardRun $run): array
    {
        $tenant = $run->getTenant();
        $inputs = $run->getInputs() ?? [];

        $vars = [];

        // ── Tenant slot ─────────────────────────────────────────────
        $vars['tenant.legal_name'] = $this->stringFromInput(
            $inputs,
            [WizardStepKeys::STEP_ORG_SCOPE, 'legal_name'],
        ) ?? ($tenant?->getLegalName() ?? $tenant?->getName());

        $vars['tenant.scope_statement'] = $this->stringFromInput(
            $inputs,
            [WizardStepKeys::STEP_ORG_SCOPE, 'scope_statement'],
        ) ?? $this->settingValueAsString($run, 'isms.scope_statement');

        $vars['tenant.id'] = $tenant?->getId();

        // ── Roles slot ─────────────────────────────────────────────
        $rolesSlot = $inputs[WizardStepKeys::STEP_ROLES] ?? [];
        $rolesMap = is_array($rolesSlot) && isset($rolesSlot['roles']) && is_array($rolesSlot['roles'])
            ? $rolesSlot['roles']
            : [];

        $vars['roles.ciso.fullName'] = $this->resolveUserFullName($rolesMap['ciso'] ?? null);
        $vars['roles.dpo.fullName'] = $this->resolveUserFullName($rolesMap['dpo'] ?? null);
        $vars['roles.bcm_officer.fullName'] = $this->resolveUserFullName($rolesMap['bcm_officer'] ?? null);
        $vars['roles.it_operations.fullName'] = $this->resolveUserFullName($rolesMap['it_operations'] ?? null);

        // ── Risk + classification slot ──────────────────────────────
        $riskSlot = $inputs[WizardStepKeys::STEP_RISK_CLASSIFICATION] ?? [];
        if (is_array($riskSlot)) {
            $vars['risk.appetite_tier'] = $this->scalarOrNull($riskSlot['risk_appetite_tier'] ?? null);
            $vars['risk.classification_levels'] = $this->scalarOrNull(
                $riskSlot['data_classification_levels'] ?? null,
            );
        } else {
            $vars['risk.appetite_tier'] = null;
            $vars['risk.classification_levels'] = null;
        }

        // ── Operational baselines slot ──────────────────────────────
        $opsSlot = $inputs[WizardStepKeys::STEP_OPERATIONAL_BASELINES] ?? [];
        if (is_array($opsSlot)) {
            $crypto = $opsSlot['crypto_allowlist'] ?? null;
            if (is_array($crypto)) {
                $vars['crypto.algorithms'] = implode(', ', array_map('strval', $crypto));
            }
            $vars['backup.rpo_hours'] = $this->scalarOrNull($opsSlot['backup_rpo_hours'] ?? null);

            // Patch-SLA per severity (assoc critical|high|medium => hours).
            $patchSla = $opsSlot['patch_sla_hours'] ?? null;
            if (is_array($patchSla)) {
                $vars['patch.sla_critical_hours'] = $this->scalarOrNull($patchSla['critical'] ?? null);
                $vars['patch.sla_high_hours'] = $this->scalarOrNull($patchSla['high'] ?? null);
                $vars['patch.sla_medium_hours'] = $this->scalarOrNull($patchSla['medium'] ?? null);
            }

            // Continuity RTO (BCM in scope) — freeform criticality => hours map.
            // Exposed as a readable "tier: Nh, …" summary marker.
            $continuityRto = $opsSlot['continuity_rto_hours'] ?? null;
            if (is_array($continuityRto) && $continuityRto !== []) {
                $pairs = [];
                foreach ($continuityRto as $criticality => $hours) {
                    if (is_scalar($hours)) {
                        $pairs[] = sprintf('%s: %sh', (string) $criticality, (string) $hours);
                    }
                }
                if ($pairs !== []) {
                    $vars['continuity.rto_summary'] = implode(', ', $pairs);
                }
            }

            // New baselines (plan spec: 6 missing fields)
            $vars['access.review_cadence_months'] = $this->scalarOrNull(
                $opsSlot['access_review_cadence_months'] ?? null,
            );
            $vars['mfa.scope'] = $this->scalarOrNull($opsSlot['mfa_scope'] ?? null);

            $logRet = $opsSlot['logging_retention_months'] ?? null;
            if (is_array($logRet)) {
                $vars['logging.retention_security_months'] = $this->scalarOrNull($logRet['security'] ?? null);
                $vars['logging.retention_app_months'] = $this->scalarOrNull($logRet['app'] ?? null);
                $vars['logging.retention_system_months'] = $this->scalarOrNull($logRet['system'] ?? null);
            }

            $vulnScan = $opsSlot['vuln_scan_cadence'] ?? null;
            if (is_array($vulnScan)) {
                $vars['vuln.scan_external_cadence'] = $this->scalarOrNull($vulnScan['external_cadence'] ?? null);
                $vars['vuln.scan_internal_cadence'] = $this->scalarOrNull($vulnScan['internal_cadence'] ?? null);
            }

            $workingModes = $opsSlot['working_modes'] ?? null;
            if (is_array($workingModes) && $workingModes !== []) {
                $vars['working.modes'] = implode(', ', array_map('strval', $workingModes));
            }

            $vars['cloud.onprem_mix_pct'] = $this->scalarOrNull(
                $opsSlot['cloud_onprem_mix_pct'] ?? null,
            );
        }

        // ── Lifecycle slot ──────────────────────────────────────────
        $lifecycleSlot = $inputs[WizardStepKeys::STEP_LIFECYCLE] ?? [];
        if (is_array($lifecycleSlot)) {
            $vars['lifecycle.review_interval_months'] = $this->scalarOrNull(
                $lifecycleSlot['review_interval_months'] ?? null,
            );
        }

        // ── Policy-parameter slot ───────────────────────────────────
        // Effective parameter values (override→profile→baseline→default)
        // become {{ policy.* }} interpolation variables so generated policies
        // render the tenant's audit-defensible values.
        $tenantId = $tenant?->getId();
        if (
            $tenantId !== null
            && $this->profileRepository !== null
            && $this->profileManager !== null
            && $this->parameterVariables !== null
        ) {
            $profile = $this->profileRepository->findForTenant($tenantId);
            if ($profile !== null) {
                $resolved = $this->profileManager->resolveAll($profile);
                foreach ($this->parameterVariables->build($resolved) as $key => $value) {
                    $vars[$key] = is_scalar($value) ? $value : (string) $value;
                }
            }
        }

        // Drop nullables that resolved to empty string AFTER we tried
        // every source. Substitutor uses '' for unknown vars regardless,
        // but downstream hash should ignore explicit nulls so re-runs
        // don't see "added new var" when the var was always missing.
        return array_filter(
            $vars,
            static fn ($v): bool => $v !== null,
        );
    }

    /**
     * @param array<string, mixed> $inputs
     * @param list<string> $path
     */
    private function stringFromInput(array $inputs, array $path): ?string
    {
        $cursor = $inputs;
        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }
        if (!is_string($cursor)) {
            return null;
        }
        $cursor = trim($cursor);
        return $cursor === '' ? null : $cursor;
    }

    private function settingValueAsString(WizardRun $run, string $key): ?string
    {
        $tenant = $run->getTenant();
        if ($tenant === null) {
            return null;
        }
        $setting = $this->settingRepository->findOneByTenantAndKey($tenant, $key);
        if ($setting === null) {
            return null;
        }
        $value = $setting->getValue();
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_array($value) && isset($value['value']) && is_string($value['value'])) {
            return $value['value'] === '' ? null : $value['value'];
        }
        return null;
    }

    private function resolveUserFullName(mixed $userId): ?string
    {
        if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
            return null;
        }
        $id = (int) $userId;
        if ($id <= 0) {
            return null;
        }
        $user = $this->userRepository->find($id);
        if (!$user instanceof User) {
            return null;
        }
        $candidate = trim($user->getFullName());
        if ($candidate !== '') {
            return $candidate;
        }
        return $user->getEmail();
    }

    /**
     * @return scalar|null
     */
    private function scalarOrNull(mixed $value): float|bool|int|string|null
    {
        if ($value === null) {
            return null;
        }
        if (is_scalar($value)) {
            return $value;
        }
        return null;
    }
}
