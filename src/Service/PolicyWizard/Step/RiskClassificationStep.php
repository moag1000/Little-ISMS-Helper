<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\WizardRun;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Step 4 — Risk & Classification.
 *
 * Inputs:
 * - Risk-appetite tier (1=very conservative, 5=aggressive — explicit
 *   direction per Junior-Implementer P1 guard rail).
 * - Data-classification scheme (3 vs 4 levels).
 * - Schutzbedarf scheme (BSI default — 3 levels, only when BSI is
 *   adopted).
 * - Annex A applicability map (control_ref => bool).
 * - Review interval months — HARD CAPPED at 24 (Junior P1 guard rail;
 *   architecture §6 Step 4).
 */
final class RiskClassificationStep extends AbstractStep
{
    public const TIER_MIN = 1;
    public const TIER_MAX = 5;

    public const REVIEW_INTERVAL_HARD_CAP_MONTHS = 24;

    public const DATA_CLASSIFICATION_3 = 3;
    public const DATA_CLASSIFICATION_4 = 4;

    public function key(): string
    {
        return WizardStepKeys::STEP_RISK_CLASSIFICATION;
    }

    public function validate(WizardRun $run, array $input): array
    {
        $errors = [];

        // Risk-appetite tier — 1=very conservative, 5=aggressive.
        $tier = $input['risk_appetite_tier'] ?? null;
        if ($tier === null || $tier === '') {
            $errors['risk_appetite_tier'][] = 'policy_wizard.error.risk_appetite_required';
            $tier = null;
        } else {
            if (!is_numeric($tier)) {
                $errors['risk_appetite_tier'][] = 'policy_wizard.error.risk_appetite_invalid';
                $tier = null;
            } else {
                $tier = (int) $tier;
                if ($tier < self::TIER_MIN || $tier > self::TIER_MAX) {
                    $errors['risk_appetite_tier'][] = 'policy_wizard.error.risk_appetite_out_of_range';
                    $tier = null;
                }
            }
        }

        // Data classification: 3-level or 4-level.
        $dataLevels = $input['data_classification_levels'] ?? self::DATA_CLASSIFICATION_3;
        if (!is_numeric($dataLevels)) {
            $errors['data_classification_levels'][] = 'policy_wizard.error.data_classification_invalid';
            $dataLevels = self::DATA_CLASSIFICATION_3;
        } else {
            $dataLevels = (int) $dataLevels;
            if (!in_array($dataLevels, [self::DATA_CLASSIFICATION_3, self::DATA_CLASSIFICATION_4], true)) {
                $errors['data_classification_levels'][] = 'policy_wizard.error.data_classification_invalid';
                $dataLevels = self::DATA_CLASSIFICATION_3;
            }
        }

        // Schutzbedarf only relevant when BSI is in scope.
        $schutzbedarfLevels = $input['schutzbedarf_levels'] ?? null;
        $standards = $run->getStandardsAdopted() ?? [];
        if (in_array('bsi', $standards, true)) {
            $schutzbedarfLevels = is_numeric($schutzbedarfLevels) ? (int) $schutzbedarfLevels : 3;
            if ($schutzbedarfLevels < 2 || $schutzbedarfLevels > 4) {
                $errors['schutzbedarf_levels'][] = 'policy_wizard.error.schutzbedarf_levels_invalid';
                $schutzbedarfLevels = 3;
            }
        } else {
            $schutzbedarfLevels = null;
        }

        // Annex A applicability — map of "A.5.15" => bool.
        $annexA = $input['annex_a_applicability'] ?? [];
        if (!is_array($annexA)) {
            $errors['annex_a_applicability'][] = 'policy_wizard.error.annex_a_invalid';
            $annexA = [];
        }
        $normalisedAnnex = [];
        foreach ($annexA as $controlRef => $applicable) {
            if (!is_string($controlRef) || $controlRef === '') {
                continue;
            }
            $normalisedAnnex[$controlRef] = (bool) $applicable;
        }

        // Review interval — hard cap at 24 months.
        $reviewInterval = $input['review_interval_months'] ?? 12;
        if (!is_numeric($reviewInterval)) {
            $errors['review_interval_months'][] = 'policy_wizard.error.review_interval_invalid';
            $reviewInterval = 12;
        } else {
            $reviewInterval = (int) $reviewInterval;
            if ($reviewInterval < 1) {
                $errors['review_interval_months'][] = 'policy_wizard.error.review_interval_invalid';
                $reviewInterval = 12;
            } elseif ($reviewInterval > self::REVIEW_INTERVAL_HARD_CAP_MONTHS) {
                $errors['review_interval_months'][] = 'policy_wizard.error.review_interval_too_long';
                $reviewInterval = self::REVIEW_INTERVAL_HARD_CAP_MONTHS;
            }
        }

        $normalised = [
            'risk_appetite_tier' => $tier,
            'risk_appetite_direction' => 'lower_is_more_conservative',
            'data_classification_levels' => $dataLevels,
            'schutzbedarf_levels' => $schutzbedarfLevels,
            'annex_a_applicability' => $normalisedAnnex,
            'review_interval_months' => $reviewInterval,
        ];

        return [
            'errors' => $errors,
            'normalised_input' => $normalised,
        ];
    }
}
