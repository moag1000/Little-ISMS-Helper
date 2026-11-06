<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\SectionExtension;

use Psr\Log\LoggerInterface;

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
    public function __construct(
        private readonly iterable $catalogues,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

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
     * Appends the `body_extension` prose of every adopted standard to an ISO 27001
     * topic body. DORA (own heading and validity tags in DocumentGenerator) and the
     * `document_section` catalogues (GDPR) are not handled here. A section whose
     * translation is missing is skipped rather than rendered as a raw key.
     *
     * @param list<string>                                  $standards WizardRun::getStandardsAdopted()
     * @param callable(string $key, string $standard): string $translate returns $key when untranslated
     *
     * @return array{body: string, applied: list<string>}
     */
    public function appendBodyExtensions(string $body, string $topic, array $standards, callable $translate): array
    {
        $applied = [];
        foreach ($this->catalogues as $catalogue) {
            $standard = $catalogue->getStandard();
            if ($standard === 'dora' || $topic === '' || !in_array($standard, $standards, true)) {
                continue;
            }
            foreach ($catalogue->sectionsForTopic($topic) as $section) {
                if ($section->renderMode !== 'body_extension') {
                    continue;
                }
                $text = $translate($section->bodyTranslationKey, $standard);
                if ($text === '' || $text === $section->bodyTranslationKey) {
                    // Never print a raw key into a policy — but say so, a misnested
                    // translation file must not silently drop a standard's text.
                    $this->logger?->warning('Policy section text missing', [
                        'standard' => $standard,
                        'key' => $section->bodyTranslationKey,
                    ]);
                    continue;
                }
                $body = rtrim($body) . "\n\n" . trim($text) . "\n";
                $applied[] = $standard;
            }
        }

        return ['body' => $body, 'applied' => array_values(array_unique($applied))];
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
