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
        }

        // ── Lifecycle slot ──────────────────────────────────────────
        $lifecycleSlot = $inputs[WizardStepKeys::STEP_LIFECYCLE] ?? [];
        if (is_array($lifecycleSlot)) {
            $vars['lifecycle.review_interval_months'] = $this->scalarOrNull(
                $lifecycleSlot['review_interval_months'] ?? null,
            );
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
