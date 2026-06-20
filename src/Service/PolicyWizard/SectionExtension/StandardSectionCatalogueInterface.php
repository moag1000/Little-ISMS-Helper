<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\SectionExtension;

/**
 * Contract for per-standard section catalogues used by the Policy Wizard.
 *
 * Each catalogue knows the standard token it represents (as it appears in
 * {@see \App\Service\PolicyWizard\WizardRun::getStandardsAdopted()}) and can
 * map any ISO 27001 topic key to zero or more {@see SectionExtension} DTOs.
 *
 * Implementations:
 *  - {@see \App\Service\PolicyWizard\GdprSectionCatalogue}  — 'gdpr', document_section render mode
 *  - {@see \App\Service\PolicyWizard\DoraExtensionCatalogue} — 'dora', body_extension render mode
 */
interface StandardSectionCatalogueInterface
{
    /**
     * Standard token as it appears in WizardRun::getStandardsAdopted()
     * (e.g. 'gdpr', 'dora', 'nis2').
     */
    public function getStandard(): string;

    /**
     * Returns every {@see SectionExtension} this standard contributes to the
     * given ISO 27001 topic policy. Returns an empty list when the topic has
     * no extension for this standard.
     *
     * @return list<SectionExtension>
     */
    public function sectionsForTopic(string $isoTopic): array;
}
