<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\PolicyTemplate;

/**
 * W1 audit-defang gap #1 — Tailoring-field minimum-quality validator.
 *
 * Per the External-Auditor persona-review (`docs/plans/policy-wizard/
 * persona-reviews/06-external-auditor-review.md` lines 168-173) "tailoring
 * fields with 1-character placeholders or copy-pasted boilerplate" are
 * the strongest tell that the human did not actually engage with the
 * wizard. The validator pre-blocks step navigation when any required
 * tailoring input fails one of:
 *
 *   1. min_length        (default 30 chars after trim)
 *   2. max_repetitions   (default 3 of the same lower-cased word)
 *   3. reject_lorem_ipsum (default ON; rejects substring matches of
 *      "lorem ipsum", "tbd", "todo", "xxx", "placeholder", "n/a")
 *
 * Per-template overrides live on
 * {@see PolicyTemplate::getRequiredVariables} via a `tailoring_constraints`
 * entry shaped like:
 *
 *   [
 *     'key'   => 'tailoring_constraints',
 *     'type'  => 'map',
 *     'value' => [
 *       'scope_statement' => [
 *         'min_length'       => 60,
 *         'max_repetitions'  => 5,
 *         'reject_lorem_ipsum' => false,
 *       ],
 *     ],
 *   ]
 *
 * Validator output:
 *   ['passed' => bool, 'violations' => list<string>]
 *
 * `violations` carries i18n keys (NOT rendered text) so the controller
 * can surface them via the `policy_wizard` translation domain. The
 * fixed key set is:
 *   - policy_wizard.error.tailoring_quality.too_short
 *   - policy_wizard.error.tailoring_quality.repetition
 *   - policy_wizard.error.tailoring_quality.placeholder
 *   - policy_wizard.error.tailoring_quality.custom
 *
 * The validator is stateless — no DI, no state — so steps may either
 * inject it via constructor (preferred for testability) or instantiate
 * it inline.
 */
final class TailoringFieldQualityValidator
{
    public const int DEFAULT_MIN_LENGTH = 30;
    public const int DEFAULT_MAX_REPETITIONS = 3;
    public const bool DEFAULT_REJECT_LOREM_IPSUM = true;

    /**
     * Lower-cased substrings that count as "obvious placeholder text"
     * when reject_lorem_ipsum is on. Order matters only for the test
     * matchers; the validator exits on the first hit.
     *
     * @var list<string>
     */
    public const PLACEHOLDER_NEEDLES = [
        'lorem ipsum',
        'tbd',
        'todo',
        'xxx',
        'placeholder',
        'n/a',
    ];

    /**
     * Validate a single tailoring-field value.
     *
     * @param string|null $value the user input as the step received it.
     *        `null` and empty strings always count as "too short" —
     *        callers that want to allow blank fields must short-circuit
     *        before delegating here.
     * @param array{
     *     min_length?: int,
     *     max_repetitions?: int,
     *     reject_lorem_ipsum?: bool,
     *     custom_pattern?: string,
     *     custom_message?: string,
     * } $constraints per-field constraint overrides; missing keys fall
     *        back to the DEFAULT_* class constants.
     *
     * @return array{passed: bool, violations: list<string>}
     */
    public function validateTailoringInput(
        string $fieldKey,
        ?string $value,
        array $constraints = [],
    ): array {
        $violations = [];
        $minLength = isset($constraints['min_length']) && is_int($constraints['min_length'])
            ? $constraints['min_length']
            : self::DEFAULT_MIN_LENGTH;
        $maxRepetitions = isset($constraints['max_repetitions']) && is_int($constraints['max_repetitions'])
            ? $constraints['max_repetitions']
            : self::DEFAULT_MAX_REPETITIONS;
        $rejectLoremIpsum = isset($constraints['reject_lorem_ipsum']) && is_bool($constraints['reject_lorem_ipsum'])
            ? $constraints['reject_lorem_ipsum']
            : self::DEFAULT_REJECT_LOREM_IPSUM;

        $trimmed = $value === null ? '' : trim($value);

        if (mb_strlen($trimmed) < $minLength) {
            $violations[] = 'policy_wizard.error.tailoring_quality.too_short';
        }

        if ($trimmed !== '' && $this->hasExcessiveRepetition($trimmed, $maxRepetitions)) {
            $violations[] = 'policy_wizard.error.tailoring_quality.repetition';
        }

        if ($rejectLoremIpsum && $trimmed !== '' && $this->containsPlaceholderText($trimmed)) {
            $violations[] = 'policy_wizard.error.tailoring_quality.placeholder';
        }

        if (
            isset($constraints['custom_pattern'])
            && is_string($constraints['custom_pattern'])
            && $constraints['custom_pattern'] !== ''
            && $trimmed !== ''
            && !@preg_match($constraints['custom_pattern'], $trimmed)
        ) {
            $violations[] = 'policy_wizard.error.tailoring_quality.custom';
        }

        $unique = array_values(array_unique($violations));

        return [
            'passed'     => $unique === [],
            'violations' => $unique,
            'field_key'  => $fieldKey,
        ];
    }

    /**
     * Resolve the per-field constraints map authored on a PolicyTemplate.
     * Returns an empty array when the template carries no override —
     * the validator then applies the DEFAULT_* constants.
     *
     * @return array<string, array<string, mixed>>
     */
    public function resolveTemplateConstraints(PolicyTemplate $template): array
    {
        $required = $template->getRequiredVariables() ?? [];
        foreach ($required as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['key'] ?? null) !== 'tailoring_constraints') {
                continue;
            }
            $value = $entry['value'] ?? null;
            if (!is_array($value)) {
                continue;
            }
            $clean = [];
            foreach ($value as $fieldKey => $fieldConstraints) {
                if (!is_string($fieldKey) || $fieldKey === '') {
                    continue;
                }
                if (!is_array($fieldConstraints)) {
                    continue;
                }
                $clean[$fieldKey] = $fieldConstraints;
            }
            return $clean;
        }
        return [];
    }

    /**
     * Tokenise on whitespace + punctuation and check whether any single
     * lower-cased token appears more than $maxRepetitions times. Tokens
     * shorter than 3 chars are skipped — common stop-words ("a", "an",
     * "im", "der") would otherwise trigger noise.
     */
    private function hasExcessiveRepetition(string $value, int $maxRepetitions): bool
    {
        if ($maxRepetitions <= 0) {
            return false;
        }
        $tokens = preg_split('/[\s\p{P}]+/u', mb_strtolower($value)) ?: [];
        $counts = [];
        foreach ($tokens as $token) {
            if ($token === '' || mb_strlen($token) < 3) {
                continue;
            }
            $counts[$token] = ($counts[$token] ?? 0) + 1;
            if ($counts[$token] > $maxRepetitions) {
                return true;
            }
        }
        return false;
    }

    /**
     * Lower-cased substring sweep over the placeholder needle list.
     */
    private function containsPlaceholderText(string $value): bool
    {
        $lower = mb_strtolower($value);
        foreach (self::PLACEHOLDER_NEEDLES as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        return false;
    }
}
