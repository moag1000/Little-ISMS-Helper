<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\WizardRun;
use BadMethodCallException;

/**
 * Policy-Wizard — null implementation of {@see DocumentGeneratorInterface}.
 *
 * Lets the WizardOrchestrator be wired up + tested without the
 * real generation pipeline. `generate()` throws so accidental calls
 * fail loudly. The orchestrator's `complete()` method explicitly
 * catches the BadMethodCallException and returns an empty
 * `document_ids` list. Production wiring aliases the interface to
 * {@see DocumentGenerator} in `services.yaml`.
 */
final class DocumentGeneratorStub implements DocumentGeneratorInterface
{
    public function generate(WizardRun $run): array
    {
        throw new BadMethodCallException(
            'DocumentGeneratorStub does not generate documents; it exists for tests only. '
            . 'Inject ' . DocumentGenerator::class . ' (the production alias of DocumentGeneratorInterface) instead.',
        );
    }
}
