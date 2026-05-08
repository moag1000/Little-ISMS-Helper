<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Step 3 — Roles & Responsibilities.
 *
 * Collects role assignments (CISO/ISB, DPO, BCM-Officer, IT Operations
 * Lead) and the per-business-function owner slots (P1 Risk-Owner — see
 * `07-phase4-sprint-reconciliation.md` §1.5 + §3 W2 line 196).
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
        foreach ($roles as $roleKey => $userId) {
            if (!is_string($roleKey) || $roleKey === '') {
                continue;
            }
            $userId = is_numeric($userId) ? (int) $userId : null;
            if ($userId !== null && $userId <= 0) {
                $userId = null;
            }
            $normalisedRoles[$roleKey] = $userId;
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
        // -> user id (nullable when intentionally left blank).
        $functionOwners = $input['function_owners'] ?? [];
        if (!is_array($functionOwners)) {
            $errors['function_owners'][] = 'policy_wizard.error.function_owners_invalid';
            $functionOwners = [];
        }
        $normalisedFunctionOwners = [];
        foreach ($functionOwners as $functionKey => $userId) {
            if (!is_string($functionKey) || $functionKey === '') {
                continue;
            }
            if (!in_array($functionKey, self::FUNCTION_SLOTS, true)) {
                $errors['function_owners'][] = 'policy_wizard.error.function_slot_unknown';
                continue;
            }
            $userId = is_numeric($userId) ? (int) $userId : null;
            if ($userId !== null && $userId <= 0) {
                $userId = null;
            }
            $normalisedFunctionOwners[$functionKey] = $userId;
        }

        // Approval chain
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

        // Self-approval guard — author cannot be in the approval chain.
        $authorId = $run->getStartedByUser()?->getId();
        if ($authorId !== null && in_array($authorId, $normalisedChain, true)) {
            $errors['approval_chain'][] = 'policy_wizard.error.self_approval_forbidden';
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
            foreach ($owners as $functionKey => $userId) {
                if (is_string($functionKey) && $userId !== null) {
                    $affected[] = $functionKey;
                }
            }
            $affected = array_values(array_unique($affected));
            $run->setAffectedFunctions($affected !== [] ? $affected : null);
        }
    }
}
