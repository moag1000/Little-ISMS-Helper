<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\WizardRun;

/**
 * Policy-Wizard W2-A — contract for document generation.
 *
 * Real implementation lands in W3 (variable substitution + SoA link +
 * approval workflow kick-off). For W2 the wizard depends on this
 * interface and is wired to the `DocumentGeneratorStub` which throws
 * `BadMethodCallException` so partial-flow tests fail loudly when they
 * hit the not-yet-implemented surface.
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
