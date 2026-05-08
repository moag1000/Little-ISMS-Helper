<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Step 2 — Organisation & Scope.
 *
 * Captures legal name, scope statement, primary address and the list
 * of in-scope sites. Climate-change wording is HARDCODED ON for ISO
 * 27001 (architecture §6 Step 2; Auditor P1) — the wizard does NOT
 * surface a toggle. It is recorded as `climate_change_wording=true` on
 * the inputs purely for the §11 auditor manifest.
 */
final class OrganisationScopeStep extends AbstractStep
{
    public function key(): string
    {
        return WizardStepKeys::STEP_ORG_SCOPE;
    }

    public function validate(WizardRun $run, array $input): array
    {
        $errors = [];

        $legalName = isset($input['legal_name']) && is_string($input['legal_name'])
            ? trim($input['legal_name'])
            : '';
        if ($legalName === '') {
            $errors['legal_name'][] = 'policy_wizard.error.legal_name_required';
        } elseif (strlen($legalName) > 191) {
            $errors['legal_name'][] = 'policy_wizard.error.legal_name_too_long';
        }

        $scopeStatement = isset($input['scope_statement']) && is_string($input['scope_statement'])
            ? trim($input['scope_statement'])
            : '';
        if ($scopeStatement === '') {
            $errors['scope_statement'][] = 'policy_wizard.error.scope_statement_required';
        } elseif (strlen($scopeStatement) < 30) {
            // Auditor §11.1 — tailoring fields need real text; a one-word
            // scope is an obvious rubber-stamp tell.
            $errors['scope_statement'][] = 'policy_wizard.error.scope_statement_too_short';
        }

        $primaryAddress = isset($input['primary_address']) && is_string($input['primary_address'])
            ? trim($input['primary_address'])
            : '';

        $sites = $input['site_ids'] ?? [];
        if (!is_array($sites)) {
            $errors['site_ids'][] = 'policy_wizard.error.site_ids_invalid';
            $sites = [];
        }
        $sites = array_values(array_unique(array_filter(array_map(
            static fn ($v): ?int => is_numeric($v) ? (int) $v : null,
            $sites,
        ))));

        // Climate-change wording always-on for any run that adopts ISO
        // 27001. Hardcoded — see Auditor P1 reversal in §6 Step 2.
        $climateOn = in_array('iso27001', $run->getStandardsAdopted() ?? [], true);

        $normalised = [
            'legal_name' => $legalName,
            'scope_statement' => $scopeStatement,
            'primary_address' => $primaryAddress !== '' ? $primaryAddress : null,
            'site_ids' => $sites,
            'climate_change_wording' => $climateOn,
        ];

        return [
            'errors' => $errors,
            'normalised_input' => $normalised,
        ];
    }
}
