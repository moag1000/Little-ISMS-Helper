<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Repository\PersonRepository;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Step 3 — Roles & Responsibilities.
 *
 * Collects role assignments (CISO/ISB, DPO, BCM-Officer, IT Operations
 * Lead) and the per-business-function owner slots (P1 Risk-Owner — see
 * `07-phase4-sprint-reconciliation.md` §1.5 + §3 W2 line 196).
 *
 * Person-Rollout (2026-05-08): the four governance roles + the six
 * function-owner slots store **Person.id** (not User.id) — long-term
 * role-holders may be external advisors without a system login. The
 * approval-chain at the bottom continues to store User.id integers
 * because every approver must own an authenticated session for the
 * audit-trail.
 *
 * Backwards-compat: legacy submissions that send a User.id where a
 * Person.id is now expected are auto-resolved via
 * {@see PersonRepository::findOneByLinkedUserId()}. When the User has
 * no linked Person, the integer is dropped and a soft warning surfaces
 * in the wizard ("Please assign a Person to this role"). The validator
 * does NOT auto-create Persons (would silently mutate tenant data).
 *
 * Self-approval guard: refuses to accept the same user-id as both the
 * author (run starter) and any approver in the chain. Junior P1 guard
 * rail.
 */
final class RolesStep extends AbstractStep
{
    /**
     * Default function-owner slots — every key maps to a business
     * function the wizard may surface. The list mirrors the §6 Step 3
     * + Risk-Owner persona review's expected coverage.
     *
     * @var list<string>
     */
    public const FUNCTION_SLOTS = [
        'sales',
        'operations',
        'rnd',
        'hr',
        'it_operations',
        'finance',
    ];

    public const REQUIRED_ROLES = ['ciso', 'dpo'];

    public function __construct(
        private readonly ?PersonRepository $personRepository = null,
        private readonly ?\App\Repository\UserRepository $userRepository = null,
    ) {
    }

    public function key(): string
    {
        return WizardStepKeys::STEP_ROLES;
    }

    public function validate(WizardRun $run, array $input): array
    {
        $errors = [];

        $roles = $input['roles'] ?? [];
        if (!is_array($roles)) {
            $errors['roles'][] = 'policy_wizard.error.roles_invalid';
            $roles = [];
        }

        $normalisedRoles = [];
        foreach ($roles as $roleKey => $personId) {
            if (!is_string($roleKey) || $roleKey === '') {
                continue;
            }
            $resolved = $this->resolvePersonId($personId);
            $normalisedRoles[$roleKey] = $resolved;
        }

        // Required roles must have an assignee.
        foreach (self::REQUIRED_ROLES as $required) {
            if (!isset($normalisedRoles[$required]) || $normalisedRoles[$required] === null) {
                $errors['roles'][] = 'policy_wizard.error.role_required.' . $required;
            }
        }

        // BCM officer mandatory only when BCM is in scope.
        $standards = $run->getStandardsAdopted() ?? [];
        if (in_array('bcm', $standards, true)) {
            if (!isset($normalisedRoles['bcm_officer']) || $normalisedRoles['bcm_officer'] === null) {
                $errors['roles'][] = 'policy_wizard.error.role_required.bcm_officer';
            }
        }

        // Function owners (P1 Risk-Owner). Each entry maps function key
        // -> Person id (nullable when intentionally left blank).
        $functionOwners = $input['function_owners'] ?? [];
        if (!is_array($functionOwners)) {
            $errors['function_owners'][] = 'policy_wizard.error.function_owners_invalid';
            $functionOwners = [];
        }
        $normalisedFunctionOwners = [];
        foreach ($functionOwners as $functionKey => $personId) {
            if (!is_string($functionKey) || $functionKey === '') {
                continue;
            }
            if (!in_array($functionKey, self::FUNCTION_SLOTS, true)) {
                $errors['function_owners'][] = 'policy_wizard.error.function_slot_unknown';
                continue;
            }
            $normalisedFunctionOwners[$functionKey] = $this->resolvePersonId($personId);
        }

        // Approval chain — User.id integers (approval-action requires login).
        $approvalChain = $input['approval_chain'] ?? [];
        if (!is_array($approvalChain)) {
            $errors['approval_chain'][] = 'policy_wizard.error.approval_chain_invalid';
            $approvalChain = [];
        }
        $normalisedChain = [];
        foreach ($approvalChain as $approverId) {
            if (!is_numeric($approverId)) {
                continue;
            }
            $id = (int) $approverId;
            if ($id <= 0) {
                continue;
            }
            $normalisedChain[] = $id;
        }
        $normalisedChain = array_values(array_unique($normalisedChain));

        if ($normalisedChain === []) {
            $errors['approval_chain'][] = 'policy_wizard.error.approval_chain_required';
        }

        // Self-approval guard — author cannot be in the approval chain
        // EXCEPT for single-user-tenants (Solo-Berater, GF=CISO, Initial-
        // Setup): there is no second User who could approve. Block only
        // when ≥ 2 active approver-eligible Users exist for the tenant.
        // Audit-trail of single-user self-approval lands at approval time
        // via PolicySectionApprovalService; here the wizard just allows
        // the input through without a UI error.
        $authorId = $run->getStartedByUser()?->getId();
        if ($authorId !== null && in_array($authorId, $normalisedChain, true)) {
            $eligibleApproverCount = $this->userRepository !== null
                ? count($this->userRepository->findApproversInTenant($run->getTenant()))
                : 2; // Defensive: legacy DI without userRepository → strict block
            if ($eligibleApproverCount >= 2) {
                $errors['approval_chain'][] = 'policy_wizard.error.self_approval_forbidden';
            }
        }

        $normalised = [
            'roles' => $normalisedRoles,
            'function_owners' => $normalisedFunctionOwners,
            'approval_chain' => $normalisedChain,
        ];

        return [
            'errors' => $errors,
            'normalised_input' => $normalised,
        ];
    }

    public function persist(WizardRun $run, array $input): void
    {
        parent::persist($run, $input);

        // Hoist the keys of populated function-owner slots onto
        // WizardRun.affectedFunctions (P1 Risk-Owner — see W1 entity
        // field). Slot=null means "no owner assigned" so the function
        // is NOT in the affected list.
        $owners = $input['function_owners'] ?? [];
        if (is_array($owners)) {
            $affected = [];
            foreach ($owners as $functionKey => $personId) {
                if (is_string($functionKey) && $personId !== null) {
                    $affected[] = $functionKey;
                }
            }
            $affected = array_values(array_unique($affected));
            $run->setAffectedFunctions($affected !== [] ? $affected : null);
        }
    }

    /**
     * Coerce + resolve a submitted role id.
     *
     * Pure shape: any non-numeric / zero / negative input collapses to
     * null. When a {@see PersonRepository} is wired AND the submitted
     * id does NOT match an existing Person, we attempt to resolve it
     * as a User.id and substitute the linked Person. If the User has
     * no linked Person, the value is preserved as-is so the form can
     * surface the orphan and prompt the admin to create the Person.
     *
     * Guarded so unit tests without a repository wired still work
     * (the validator falls back to "trust the integer").
     */
    private function resolvePersonId(mixed $raw): ?int
    {
        if (!is_numeric($raw)) {
            return null;
        }
        $id = (int) $raw;
        if ($id <= 0) {
            return null;
        }

        if (!$this->personRepository instanceof PersonRepository) {
            return $id;
        }

        $person = $this->personRepository->find($id);
        if ($person !== null) {
            return $id;
        }

        // Backwards-compat: legacy id might be a User.id. Try to map
        // it back to its linked Person.
        $linked = $this->personRepository->findOneByLinkedUserId($id);
        if ($linked !== null) {
            return $linked->getId();
        }

        return $id;
    }
}
