<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\WizardRun;

/**
 * Policy-Wizard — Junior-ISB Wish #5 ("Auditor-Score") readiness scoring.
 *
 * Heuristic traffic-light scoring for a single Policy-Wizard-generated
 * {@see Document}. Surfaced as a badge in the Document index (column)
 * and as a header section in the Document show view, so non-expert
 * implementers (Junior-ISB, 9001 background) get an immediate "is this
 * audit-ready?" signal without having to read the full body.
 *
 * Scoring tiers:
 *   - GREEN  (80-100): all required tailoring fields filled, no
 *                      substitution-variable leakage, climate wording
 *                      present (where required), approver assigned,
 *                      audit-trail (wizard-run + template) intact.
 *   - YELLOW (50-79):  minor issues — some optional fields empty or
 *                      cosmetic warnings.
 *   - RED    (0-49):   major audit blockers — leakage detected,
 *                      missing approver, incomplete substitution
 *                      variables, body too short, no audit trail.
 *
 * The score is informational only — it is NOT a compliance gate.
 * Auditors still review the document on the merits; the score helps
 * the implementer prioritise re-generation of red-flagged docs.
 */
final class AuditorScoreCalculator
{
    /** Minimum body length (description) we consider "non-stub". */
    private const int MIN_DESCRIPTION_LENGTH = 80;

    /** Required substitution-variable keys for an audit-grade render. */
    private const array REQUIRED_VAR_KEYS = [
        'tenant.legal_name',
    ];

    /**
     * Compute the auditor-readiness score for a generated Document.
     *
     * Returns null when the document was NOT produced by the
     * Policy-Wizard (no template provenance) — uploaded / imported
     * documents fall outside the scoring contract.
     */
    public function calculateForDocument(Document $document): ?AuditorScore
    {
        $template = $document->getGeneratedFromTemplate();
        if (!$template instanceof PolicyTemplate) {
            return null;
        }

        $reasons = [];
        $score = 100;

        // 1. Audit-trail batch_id — the WizardRun link is the
        //    auditor's pivot to "which run produced this".
        $run = $document->getGeneratedFromWizardRun();
        if (!$run instanceof WizardRun) {
            $score -= 25;
            $reasons[] = 'audit_trail_missing';
        }

        // 2. Substitution variables — required vars must be present
        //    AND non-empty in the snapshot.
        $vars = $document->getSubstitutionVariables();
        if (!is_array($vars) || $vars === []) {
            $score -= 30;
            $reasons[] = 'substitution_vars_missing';
        } else {
            foreach (self::REQUIRED_VAR_KEYS as $key) {
                $value = $vars[$key] ?? null;
                if (!is_string($value) || trim($value) === '') {
                    $score -= 15;
                    $reasons[] = 'substitution_var_incomplete:' . $key;
                }
            }
        }

        // 3. Body / description length — virtual docs store the first
        //    paragraph in description. A near-empty description is a
        //    strong signal of a stub render.
        $description = (string) ($document->getDescription() ?? '');
        if (strlen(trim($description)) < self::MIN_DESCRIPTION_LENGTH) {
            $score -= 15;
            $reasons[] = 'body_too_short';
        }

        // 4. Substitution leakage — raw Twig markers in the
        //    description signal an unresolved variable.
        if ($description !== '' && $this->containsTwigMarkers($description)) {
            $score -= 30;
            $reasons[] = 'substitution_leakage';
        }

        // 5. Climate-change wording — only required for ISO 27001
        //    top-level / leitlinie templates with the wording flag ON.
        if ($this->requiresClimateWording($template) && !$this->hasClimateWording($description)) {
            $score -= 10;
            $reasons[] = 'climate_wording_missing';
        }

        // 6. Approval chain — approved status earns full credit;
        //    in_review counts as partial; draft is a yellow flag.
        $status = strtolower($document->getStatus());
        if ($status === 'draft') {
            $score -= 5;
            $reasons[] = 'still_in_draft';
        }

        // 7. Required tailoring variables declared on the template.
        $required = $template->getRequiredVariables() ?? [];
        if (is_array($required) && $required !== [] && is_array($vars)) {
            $missing = [];
            foreach ($required as $key) {
                if (!is_string($key)) {
                    continue;
                }
                $val = $vars[$key] ?? null;
                if ($val === null || (is_string($val) && trim($val) === '')) {
                    $missing[] = $key;
                }
            }
            if ($missing !== []) {
                $score -= min(20, count($missing) * 5);
                $reasons[] = 'tailoring_vars_missing:' . implode(',', $missing);
            }
        }

        // Clamp to [0, 100] then assign tier.
        $score = max(0, min(100, $score));
        $tier = match (true) {
            $score >= 80 => AuditorScore::TIER_GREEN,
            $score >= 50 => AuditorScore::TIER_YELLOW,
            default => AuditorScore::TIER_RED,
        };

        return new AuditorScore(
            tier: $tier,
            score: $score,
            reasons: $reasons,
        );
    }

    /**
     * Compute scores for a batch of documents — keyed by Document.id.
     * Documents without a template provenance are silently skipped.
     *
     * @param iterable<Document> $documents
     * @return array<int, AuditorScore>
     */
    public function calculateForBatch(iterable $documents): array
    {
        $out = [];
        foreach ($documents as $document) {
            $id = $document->getId();
            if ($id === null) {
                continue;
            }
            $score = $this->calculateForDocument($document);
            if ($score === null) {
                continue;
            }
            $out[$id] = $score;
        }
        return $out;
    }

    private function containsTwigMarkers(string $body): bool
    {
        return (bool) preg_match('/\{\{\s|\{%\s|\{#\s/', $body);
    }

    private function requiresClimateWording(PolicyTemplate $template): bool
    {
        return $template->getStandard() === 'iso27001'
            && $template->getTopic() === 'top_level'
            && $template->isClimateChangeWording();
    }

    private function hasClimateWording(string $body): bool
    {
        if ($body === '') {
            return false;
        }
        // DE = literal "Klimawandel"; EN = case-insensitive "climate change".
        return str_contains($body, 'Klimawandel')
            || stripos($body, 'climate change') !== false;
    }
}
