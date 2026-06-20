<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\SectionExtension;

/**
 * Registry that collects every {@see StandardSectionCatalogueInterface}
 * implementation via the `app.policy_section_catalogue` service tag
 * (auto-wired by the `_instanceof` rule in `config/services.yaml`).
 *
 * {@see \App\Service\PolicyWizard\DocumentGenerator} injects this registry
 * and iterates it once per standard in `WizardRun::getStandardsAdopted()`
 * instead of calling two hard-coded private methods. The registry itself
 * stays stateless — it is a pure pass-through to the tagged iterator.
 *
 * Look-up semantics:
 *  - {@see forStandard()} returns the catalogue whose
 *    {@see StandardSectionCatalogueInterface::getStandard()} matches, or
 *    null when no catalogue handles that standard. This allows
 *    DocumentGenerator to silently skip unknown standards rather than
 *    throwing — consistent with the existing "no-op on unknown standard"
 *    behaviour.
 *  - {@see all()} exposes the full collection so tests + commands can
 *    introspect which catalogues are registered.
 */
final class SectionExtensionRegistry
{
    /**
     * @param iterable<StandardSectionCatalogueInterface> $catalogues
     *        Tagged iterator collected via `!tagged_iterator app.policy_section_catalogue`.
     */
    public function __construct(private readonly iterable $catalogues) {}

    /**
     * Returns the catalogue responsible for the given standard token, or
     * null when no registered catalogue handles that standard.
     *
     * @param string $standard Standard token as stored in
     *        {@see \App\Entity\WizardRun::getStandardsAdopted()} (e.g. 'gdpr', 'dora').
     */
    public function forStandard(string $standard): ?StandardSectionCatalogueInterface
    {
        foreach ($this->catalogues as $catalogue) {
            if ($catalogue->getStandard() === $standard) {
                return $catalogue;
            }
        }
        return null;
    }

    /**
     * All registered catalogues in registration order.
     *
     * @return iterable<StandardSectionCatalogueInterface>
     */
    public function all(): iterable
    {
        return $this->catalogues;
    }
}
