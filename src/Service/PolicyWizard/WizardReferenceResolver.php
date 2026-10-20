<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Repository\PersonRepository;
use App\Repository\UserRepository;

/**
 * Resolves the User / Person references stored in a wizard run's inputs to
 * display strings for the review summary and the generation confirmation.
 *
 * The inputs only hold ids (approval chain, DPO / BCM officer, approvers,
 * role owners); this class collects them and resolves each kind in a single
 * findBy() so the templates never query per row.
 */
final class WizardReferenceResolver
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PersonRepository $personRepository,
    ) {
    }

    /**
     * Walk the wizard inputs to collect every User.id reference that the
     * review-summary will display. Returns DEDUPLICATED list of ints.
     *
     * @param array<string, mixed> $inputs
     * @return list<int>
     */
    public function collectReferencedUserIds(array $inputs): array
    {
        $ids = [];

        // RolesStep.approval_chain — list<int>
        $roles = $inputs[WizardStepKeys::STEP_ROLES] ?? [];
        if (is_array($roles)) {
            $chain = $roles['approval_chain'] ?? [];
            if (is_array($chain)) {
                foreach ($chain as $id) {
                    if (is_int($id) && $id > 0) {
                        $ids[] = $id;
                    }
                }
            }
        }

        // OperationalBaselinesStep.dpo_user_id + .bcm_officer_user_id
        $op = $inputs[WizardStepKeys::STEP_OPERATIONAL_BASELINES] ?? [];
        if (is_array($op)) {
            foreach (['dpo_user_id', 'bcm_officer_user_id'] as $key) {
                $val = $op[$key] ?? null;
                if (is_int($val) && $val > 0) {
                    $ids[] = $val;
                }
            }
        }

        // LifecycleStep.default_approver_user_id + .approver_per_template[*]
        $lc = $inputs[WizardStepKeys::STEP_LIFECYCLE] ?? [];
        if (is_array($lc)) {
            $defApp = $lc['default_approver_user_id'] ?? null;
            if (is_int($defApp) && $defApp > 0) {
                $ids[] = $defApp;
            }
            $perTpl = $lc['approver_per_template'] ?? [];
            if (is_array($perTpl)) {
                foreach ($perTpl as $userId) {
                    if (is_int($userId) && $userId > 0) {
                        $ids[] = $userId;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Walk the wizard inputs to collect every Person.id reference that
     * the review-summary will display.
     *
     * @param array<string, mixed> $inputs
     * @return list<int>
     */
    public function collectReferencedPersonIds(array $inputs): array
    {
        $ids = [];
        $roles = $inputs[WizardStepKeys::STEP_ROLES] ?? [];
        if (is_array($roles)) {
            foreach (['roles', 'function_owners'] as $bucket) {
                $map = $roles[$bucket] ?? [];
                if (!is_array($map)) {
                    continue;
                }
                foreach ($map as $personId) {
                    if (is_int($personId) && $personId > 0) {
                        $ids[] = $personId;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Resolve User-ids to "Full Name <email>" display strings in ONE
     * findBy() call. Missing ids fall back to the placeholder format
     * "User #<id>" so the template can still render.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function resolveUserDisplays(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->userRepository->findBy(['id' => $ids]) as $u) {
            $name = trim($u->getFullName());
            $email = (string) ($u->getEmail() ?? '');
            $display = $name !== '' && $email !== ''
                ? sprintf('%s <%s>', $name, $email)
                : ($name !== '' ? $name : $email);
            $map[(int) $u->getId()] = $display !== '' ? $display : ('User #' . $u->getId());
        }
        return $map;
    }

    /**
     * Resolve Person-ids to display strings.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function resolvePersonDisplays(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->personRepository->findBy(['id' => $ids]) as $p) {
            $name = trim((string) $p->getFullName());
            $map[(int) $p->getId()] = $name !== '' ? $name : ('Person #' . $p->getId());
        }
        return $map;
    }
}
