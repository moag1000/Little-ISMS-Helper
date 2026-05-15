<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\WizardRun;
use BadMethodCallException;

/**
 * Policy-Wizard W2-A — null implementation of {@see DocumentGeneratorInterface}.
 *
 * Lets the WizardOrchestrator be wired up + tested in W2 without the
 * real generation pipeline. `generate()` throws so accidental calls
 * during partial-feature integration fail loudly. The orchestrator's
 * `complete()` method explicitly catches the BadMethodCallException
 * and returns an empty `document_ids` list (W2 contract).
 *
 * Replace this service binding in `services.yaml` once W3 lands the
 * real DocumentGenerator implementation.
 */
final class DocumentGeneratorStub implements DocumentGeneratorInterface
{
    public function generate(WizardRun $run): array
    {
        throw new BadMethodCallException(
            'Document generation is deferred to Policy-Wizard W3. '
            . 'See docs/plans/policy-wizard/05-architecture.md §8 + §11. '
            . '@todo 2026-05-14: replace stub with real DocumentGenerator once W3 lands.',
        );
    }
}
