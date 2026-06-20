<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\SectionExtension;

/**
 * Value-object that represents one standard's contribution to an ISO 27001
 * topic policy — either as a full {@see DocumentSection} row (GDPR-style)
 * or as an appended prose block (DORA-style).
 *
 * Produced by {@see StandardSectionCatalogueInterface::sectionsForTopic()}.
 */
final readonly class SectionExtension
{
    /**
     * @param list<string> $controlRefs Standard control / article ids this section satisfies.
     */
    public function __construct(
        public string $sectionKey,
        public string $standard,           // 'gdpr', 'dora', …
        public array  $controlRefs,        // standard control/article ids this section satisfies
        public string $approvalRole,       // 'ciso' | 'dpo' | 'joint'
        public string $bodyTranslationKey,
        public string $renderMode,         // 'document_section' (GDPR-style rows) | 'body_extension' (DORA-style prose)
    ) {}
}
