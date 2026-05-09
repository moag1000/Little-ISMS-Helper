<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\Location;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardRun;
use App\Repository\LocationRepository;
use App\Service\PolicyWizard\TailoringFieldQualityValidator;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Step 2 — Organisation & Scope.
 *
 * Captures legal name, scope statement, primary address and the list
 * of in-scope sites. Climate-change wording is HARDCODED ON for ISO
 * 27001 (architecture §6 Step 2; Auditor P1) — the wizard does NOT
 * surface a toggle. It is recorded as `climate_change_wording=true` on
 * the inputs purely for the §11 auditor manifest.
 *
 * W1 audit-defang gap #1 — runs every free-text input through the
 * {@see TailoringFieldQualityValidator} so 1-character placeholders or
 * "lorem ipsum" copy-pasta block forward navigation.
 *
 * W2 user-feedback (May 2026) — `defaults()` now pre-fills `legal_name`
 * + `primary_address` from the Tenant configuration so the user does
 * not retype data already in the system, and exposes the list of
 * tenant-scoped Locations the current user can see so the in-scope
 * sites field can render as a permission-aware multi-select instead of
 * a raw ID text input.
 */
final class OrganisationScopeStep extends AbstractStep
{
    public function __construct(
        private readonly ?TailoringFieldQualityValidator $tailoringValidator = null,
        private readonly ?LocationRepository $locationRepository = null,
    ) {
    }

    public function key(): string
    {
        return WizardStepKeys::STEP_ORG_SCOPE;
    }

    /**
     * Pre-fill defaults sourced from existing tenant configuration so
     * the operator does not retype information already captured during
     * tenant setup.
     *
     * Pre-fill rules (applied only when the corresponding field is
     * still empty in the persisted slot — user input always wins):
     *  - `legal_name`     ← `Tenant::getLegalName()`,
     *                       fallback to `Tenant::getName()`.
     *  - `primary_address` ← address of the first active office /
     *                        building / headquarters Location for the
     *                        tenant, when one exists.
     *
     * Additional payload exposed to the template:
     *  - `prefilled_legal_name` (bool)       — render hint when true.
     *  - `prefilled_primary_address` (bool)  — render hint when true.
     *  - `available_locations` (list<array>) — `{ id, label }` rows
     *                                          for the in-scope-sites
     *                                          multi-select. Filtered
     *                                          to the visible-for-user
     *                                          set so cross-tenant or
     *                                          archived rows never leak.
     *
     * @return array<string, mixed>
     */
    public function defaults(WizardRun $run): array
    {
        $persisted = parent::defaults($run);
        $tenant = $run->getTenant();
        $user = $run->getStartedByUser();

        $legalNamePersisted = isset($persisted['legal_name']) && is_string($persisted['legal_name'])
            ? trim($persisted['legal_name'])
            : '';
        $addressPersisted = isset($persisted['primary_address']) && is_string($persisted['primary_address'])
            ? trim($persisted['primary_address'])
            : '';

        $prefilledLegalName = false;
        $prefilledPrimaryAddress = false;

        if ($legalNamePersisted === '' && $tenant instanceof Tenant) {
            $tenantLegalName = $tenant->getLegalName();
            if (!is_string($tenantLegalName) || trim($tenantLegalName) === '') {
                // Fallback to the human-readable name when the
                // dedicated legal-name column is still null.
                $tenantLegalName = $tenant->getName();
            }
            if (is_string($tenantLegalName) && trim($tenantLegalName) !== '') {
                $persisted['legal_name'] = trim($tenantLegalName);
                $prefilledLegalName = true;
            }
        }

        if ($addressPersisted === '' && $tenant instanceof Tenant) {
            $candidate = $this->derivePrimaryAddress($tenant, $user);
            if ($candidate !== null) {
                $persisted['primary_address'] = $candidate;
                $prefilledPrimaryAddress = true;
            }
        }

        $availableLocations = $this->collectAvailableLocations($tenant, $user);

        $persisted['prefilled_legal_name'] = $prefilledLegalName;
        $persisted['prefilled_primary_address'] = $prefilledPrimaryAddress;
        $persisted['available_locations'] = $availableLocations;

        return $persisted;
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

        // W1 audit-defang gap #1 — tailoring-field minimum quality.
        // ONLY for narrative fields (scope_statement). Legal name is a
        // proper noun (e.g. "Mordor Inc.", 11 chars) and must NOT trip
        // the 30-char min_length default — that bug rejected valid org
        // names with "tailoring_quality.too_short".
        if ($this->tailoringValidator !== null) {
            $result = $this->tailoringValidator->validateTailoringInput('scope_statement', $scopeStatement);
            if (!$result['passed']) {
                foreach ($result['violations'] as $violationKey) {
                    $errors['scope_statement'][] = $violationKey;
                }
            }
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

    /**
     * Pick the most-likely "headquarters" address from the tenant's
     * Locations: first active office / building / datacenter / HQ-like
     * row with a non-empty `address`. Falls back to null when nothing
     * matches — the user simply enters the address by hand.
     */
    private function derivePrimaryAddress(Tenant $tenant, ?User $user): ?string
    {
        if ($this->locationRepository === null || !$user instanceof User) {
            return null;
        }

        $locations = $this->locationRepository->findVisibleForUserAndTenant($user, $tenant);
        if ($locations === []) {
            return null;
        }

        // Preference order — earlier types win.
        $preferredTypes = ['building', 'office', 'datacenter', 'server_room'];

        foreach ($preferredTypes as $type) {
            foreach ($locations as $loc) {
                if ($loc->getLocationType() === $type) {
                    $addr = $this->normaliseAddress($loc->getAddress());
                    if ($addr !== null) {
                        return $addr;
                    }
                }
            }
        }

        // No preferred type matched — accept the first row with an
        // address regardless of type.
        foreach ($locations as $loc) {
            $addr = $this->normaliseAddress($loc->getAddress());
            if ($addr !== null) {
                return $addr;
            }
        }

        return null;
    }

    /**
     * Collapse multi-line addresses to a single line so the derived
     * value cleanly fits a single-line `<input type="text">`. Returns
     * null when the address is empty / not a string.
     */
    private function normaliseAddress(?string $address): ?string
    {
        if (!is_string($address) || trim($address) === '') {
            return null;
        }
        return trim((string) preg_replace('/\s+/', ' ', $address));
    }

    /**
     * Build the `{ id, label }` payload for the in-scope-sites
     * multi-select. Returns an empty list when the run lacks tenant /
     * user context or the LocationRepository is not wired (legacy DI
     * graphs / unit fixtures).
     *
     * @return list<array{id: int, label: string}>
     */
    private function collectAvailableLocations(?Tenant $tenant, ?User $user): array
    {
        if ($this->locationRepository === null
            || !$tenant instanceof Tenant
            || !$user instanceof User
        ) {
            return [];
        }

        $rows = $this->locationRepository->findVisibleForUserAndTenant($user, $tenant);
        $payload = [];
        foreach ($rows as $loc) {
            $id = $loc->getId();
            if ($id === null) {
                continue;
            }
            $payload[] = [
                'id' => $id,
                'label' => $this->locationLabel($loc),
            ];
        }
        return $payload;
    }

    /**
     * Build a single-line picker label: "Name — Address" when address
     * is set, otherwise just "Name". Trims aggressively so the dropdown
     * stays readable.
     */
    private function locationLabel(Location $loc): string
    {
        $name = trim((string) $loc->getName());
        $addr = $loc->getAddress();
        if (is_string($addr) && trim($addr) !== '') {
            // Collapse newlines so the option label stays on one line.
            $addr = trim(preg_replace('/\s+/', ' ', $addr) ?? $addr);
            return sprintf('%s — %s', $name, $addr);
        }
        return $name;
    }
}
