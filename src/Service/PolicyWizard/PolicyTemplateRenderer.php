<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\PolicyTemplate;
use BadMethodCallException;

/**
 * Renders a PolicyTemplate body into the final policy document text,
 * substituting `{{ variables }}` from the wizard run inputs.
 *
 * Implements two cross-cutting audit-defangs from
 * `docs/plans/policy-wizard/05-architecture.md` §11:
 *
 *   §11.2 (P1 Auditor reversal): variable-substitution markers are
 *   HIDDEN by default. `{{ tenant.legal_name }}` substitutes to the
 *   final value (`MyCompany GmbH`); the original variable name MUST
 *   NOT appear in the rendered output. A separate machine-readable
 *   manifest records the source of each substitution for audit.
 *
 *   §6 Step 2 / Phase 4-C gate §7-#6 bullet "climate-change wording":
 *   for every `iso27001` PolicyTemplate the climate-change wording
 *   is hardcoded ON — there is no UI toggle and the renderer always
 *   includes the section.
 *
 * IMPLEMENTATION NOTE: this class is a stub for sprint W1. The
 * actual rendering pipeline (Twig + variable manifest + random
 * sampler validator) ships in W3. The stub throws
 * `BadMethodCallException` so the W1-D fixture can `markTestSkipped()`
 * cleanly.
 *
 * @phpstan-type RenderResult array{
 *   body: string,
 *   substitution_manifest: array<string, string>,
 *   climate_change_section_included: bool,
 *   leftover_markers_detected: bool,
 * }
 */
class PolicyTemplateRenderer
{
    /**
     * Render the template body for a given variable bag, returning the
     * resolved text + audit manifest.
     *
     * @param array<string, scalar|null> $variables
     * @return RenderResult
     */
    public function render(PolicyTemplate $template, array $variables): array
    {
        throw new BadMethodCallException(
            'PolicyTemplateRenderer::render not yet implemented — W3 ticket. '
            . 'See docs/plans/policy-wizard/05-architecture.md §11.2 + §6 Step 2.',
        );
    }

    /**
     * Returns true when this template must always include the
     * climate-change wording section (hardcoded for `iso27001`
     * standard).
     */
    public function isClimateChangeWordingMandatory(PolicyTemplate $template): bool
    {
        throw new BadMethodCallException(
            'PolicyTemplateRenderer::isClimateChangeWordingMandatory not yet implemented — W3 ticket.',
        );
    }
}
