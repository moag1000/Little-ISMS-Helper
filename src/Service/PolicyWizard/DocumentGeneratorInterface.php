<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\WizardRun;

/**
 * Policy-Wizard W2-A — contract for document generation.
 *
 * config/services.yaml aliases this interface to the real
 * {@see DocumentGenerator} (W3: variable substitution + SoA link +
 * approval workflow kick-off). The W2 `DocumentGeneratorStub`, which
 * throws `BadMethodCallException`, remains for unit tests that
 * instantiate it directly.
 *
 * @phpstan-type GenerationResult array{
 *   document_ids: list<int>,
 *   sandbox_preview: array<string, mixed>|null,
 * }
 */
interface DocumentGeneratorInterface
{
    /**
     * Generate documents for a completed wizard run. For sandbox runs
     * implementations must NOT persist Document entities — they return
     * a `sandbox_preview` payload instead and an empty `document_ids`
     * list.
     *
     * @return GenerationResult
     */
    public function generate(WizardRun $run): array;
}
