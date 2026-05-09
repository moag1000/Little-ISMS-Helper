<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use RuntimeException;

/**
 * W1 audit-defang gap #3 — thrown by
 * {@see SubstitutionLeakageDetector::assertNoLeaks} when the rendered
 * Document body still contains unresolved Twig markers (`{{ … }}`,
 * `{% … %}` or `{# … #}`). Per the External-Auditor persona-review
 * (`docs/plans/policy-wizard/persona-reviews/06-external-auditor-review.md`
 * lines 180-185), a leaked variable name in a published policy is a
 * direct ISO 27001 Cl. 7.5.2 (documented information) finding —
 * "approved evidence" must read as clean prose, not as generator
 * transparency.
 *
 * The exception preserves the structured leak list so callers
 * (DocumentGenerator, audit log, dev-tools) can surface every leak in
 * one round-trip rather than failing on the first hit.
 */
final class SubstitutionLeakageException extends RuntimeException
{
    /**
     * @param list<array{token: string, line: int, position: int}> $leaks
     */
    public function __construct(
        public readonly array $leaks,
        ?string $message = null,
    ) {
        $summary = $message ?? sprintf(
            'Substitution leakage detected: %d unresolved Twig marker(s) in rendered body — first leak: "%s" at line %d.',
            count($leaks),
            $leaks[0]['token'] ?? '?',
            $leaks[0]['line'] ?? 0,
        );
        parent::__construct($summary);
    }
}
