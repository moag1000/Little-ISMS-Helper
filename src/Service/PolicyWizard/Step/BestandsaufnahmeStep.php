<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\ExistingDocumentInventoryService;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Policy-Wizard W4-C — Step 0 "Bestandsaufnahme" for brownfield tenants.
 *
 * The user is shown the tenant's existing governance-grade documents and
 * decides per row what should happen during the upcoming wizard run:
 *
 *   - replace          → new wizard output supersedes the legacy doc
 *   - keep             → legacy doc stays; matching topic is SKIPPED
 *   - merge_into_topic → legacy content is appended as a section to a
 *                        single new wizard topic
 *   - split_to_topics  → manual split required (W4 raises an Alva-Hint;
 *                        the wizard does not auto-split in this sprint)
 *
 * Persisted shape under `WizardRun.inputs.bestandsaufnahme`:
 *
 *   {
 *       "decisions": {
 *           "<existing_document_id>": {
 *               "action": "replace" | "keep" | "merge_into_topic" | "split_to_topics",
 *               "target_topic":  "<key>" | null,
 *               "target_topics": ["<k1>","<k2>"] | null,
 *               "rationale":     "free-text"
 *           },
 *           ...
 *       }
 *   }
 *
 * Applicability rules (see {@see self::isApplicable}):
 *   - run mode == MODE_FULL (sandbox + targeted skip)
 *   - tenant has at least one existing governance document NOT carrying
 *     the `policy-wizard-generated` tag
 *   - the user has not yet completed this step in the current run
 */
final class BestandsaufnahmeStep extends AbstractStep
{
    public const ALLOWED_ACTIONS = [
        ExistingDocumentInventoryService::ACTION_REPLACE,
        ExistingDocumentInventoryService::ACTION_KEEP,
        ExistingDocumentInventoryService::ACTION_MERGE_INTO_TOPIC,
        ExistingDocumentInventoryService::ACTION_SPLIT_TO_TOPICS,
    ];

    public function __construct(
        private readonly ExistingDocumentInventoryService $inventoryService,
    ) {
    }

    public function key(): string
    {
        return WizardStepKeys::STEP_BESTANDSAUFNAHME;
    }

    /**
     * Step is only applicable for brownfield full-mode runs.
     *
     * Skips when:
     *   - mode != full
     *   - tenant has no governance docs at all (greenfield)
     *   - all governance docs are already wizard-generated
     *   - the step is already persisted in `inputs` (already completed)
     */
    public function isApplicable(WizardRun $run): bool
    {
        if ($run->getMode() !== WizardStepKeys::MODE_FULL) {
            return false;
        }

        // Already-completed guard: a non-empty persisted slot means the
        // user already committed decisions; the orchestrator must NOT
        // re-route them back to Step 0.
        $persisted = $this->readSlot($run, $this->key());
        if (($persisted['decisions'] ?? null) !== null && $persisted['decisions'] !== []) {
            return false;
        }

        $tenant = $run->getTenant();
        if ($tenant === null) {
            return false;
        }

        $rows = $this->inventoryService->inventoryFor($tenant);
        // Greenfield short-circuit: no existing governance documents at all.
        if ($rows === []) {
            return false;
        }

        // Brownfield gate: at least one row must NOT be a wizard-generated
        // document. If everything is already wizard-managed there is
        // nothing to triage.
        foreach ($rows as $row) {
            if (($row['hasPolicyWizardTag'] ?? false) === false) {
                return true;
            }
        }
        return false;
    }

    public function validate(WizardRun $run, array $input): array
    {
        $errors = [];
        $decisionsRaw = $input['decisions'] ?? [];
        if (!is_array($decisionsRaw)) {
            $decisionsRaw = [];
        }

        // Compute the inventory-id set for this tenant so we can refuse
        // decisions referencing foreign or vanished documents.
        $tenant = $run->getTenant();
        $validIds = [];
        if ($tenant !== null) {
            foreach ($this->inventoryService->inventoryFor($tenant) as $row) {
                $validIds[(int) $row['id']] = true;
            }
        }

        $normalised = ['decisions' => []];

        foreach ($decisionsRaw as $documentIdRaw => $payload) {
            if (!is_array($payload)) {
                continue;
            }
            $documentId = (int) $documentIdRaw;
            if ($documentId <= 0) {
                continue;
            }
            if ($validIds !== [] && !isset($validIds[$documentId])) {
                $errors['decisions.' . $documentId][] = 'policy_wizard.error.bestandsaufnahme.unknown_document';
                continue;
            }

            $action = is_string($payload['action'] ?? null) ? $payload['action'] : '';
            if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
                $errors['decisions.' . $documentId][] = 'policy_wizard.error.bestandsaufnahme.action_invalid';
                continue;
            }

            $targetTopic = null;
            $targetTopics = null;

            if ($action === ExistingDocumentInventoryService::ACTION_MERGE_INTO_TOPIC) {
                $cand = $payload['target_topic'] ?? null;
                if (!is_string($cand) || trim($cand) === '') {
                    $errors['decisions.' . $documentId][] = 'policy_wizard.error.bestandsaufnahme.target_topic_required';
                } else {
                    $targetTopic = trim($cand);
                }
            }

            if ($action === ExistingDocumentInventoryService::ACTION_SPLIT_TO_TOPICS) {
                $cand = $payload['target_topics'] ?? null;
                if (!is_array($cand) || $cand === []) {
                    $errors['decisions.' . $documentId][] = 'policy_wizard.error.bestandsaufnahme.target_topics_required';
                } else {
                    $clean = array_values(array_filter(array_map(
                        static fn ($v): string => is_string($v) ? trim($v) : '',
                        $cand,
                    ), static fn (string $v): bool => $v !== ''));
                    if ($clean === []) {
                        $errors['decisions.' . $documentId][] = 'policy_wizard.error.bestandsaufnahme.target_topics_required';
                    } else {
                        $targetTopics = $clean;
                    }
                }
            }

            $rationaleRaw = $payload['rationale'] ?? '';
            $rationale = is_string($rationaleRaw) ? trim($rationaleRaw) : '';
            if (mb_strlen($rationale) > 1000) {
                $errors['decisions.' . $documentId][] = 'policy_wizard.error.bestandsaufnahme.rationale_too_long';
                $rationale = mb_substr($rationale, 0, 1000);
            }

            $normalised['decisions'][$documentId] = [
                'action' => $action,
                'target_topic' => $targetTopic,
                'target_topics' => $targetTopics,
                'rationale' => $rationale,
            ];
        }

        // Require a decision for every inventoried row (the "Continue"
        // button must not let the user skip rows silently).
        if ($validIds !== []) {
            foreach (array_keys($validIds) as $expectedId) {
                if (!isset($normalised['decisions'][$expectedId])
                    && !isset($errors['decisions.' . $expectedId])) {
                    $errors['decisions.' . $expectedId][] = 'policy_wizard.error.bestandsaufnahme.decision_required';
                }
            }
        }

        return [
            'errors' => $errors,
            'normalised_input' => $normalised,
        ];
    }

    public function defaults(WizardRun $run): array
    {
        // Pull whatever was persisted (nothing on first render).
        $persisted = $this->readSlot($run, $this->key());
        return [
            'decisions' => is_array($persisted['decisions'] ?? null)
                ? $persisted['decisions']
                : [],
        ];
    }
}
