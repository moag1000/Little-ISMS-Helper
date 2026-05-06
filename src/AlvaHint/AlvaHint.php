<?php

declare(strict_types=1);

namespace App\AlvaHint;

/**
 * Immutable hint payload returned by AlvaHintRule::build().
 *
 * The DTO keeps presentation concerns (translation keys, button label,
 * variant) but never holds the rule logic itself. The Twig macro
 * `_components/_fa_alva_hint.html.twig` consumes it as-is.
 *
 * - `key`: stable identifier matching the rule (used for dismissal +
 *   audit log + DOM ids); examples: "asset.protection_inheritance",
 *   "incident.gdpr_72h"
 * - `priorityTier`: 1 = Pflicht (regulatory), 2 = audit gap, 3 = efficiency.
 *   The service emits at most one hint per page, lower tier wins.
 * - `dismissible`: regulatory-deadline hints (tier 1, time-bound) opt out.
 * - `actionRoute` / `actionRouteParams` / `actionCsrfIntent`: enough info
 *   for the macro to render a CSRF-protected POST form. Optional — some
 *   hints are pure information.
 */
final readonly class AlvaHint
{
    public function __construct(
        public string $key,
        public string $titleTranslationKey,
        public string $bodyTranslationKey,
        /** @var array<string, mixed> */
        public array $bodyTranslationParams = [],
        public string $translationDomain = 'alva',
        public string $variant = 'info',
        public int $priorityTier = 3,
        public bool $dismissible = true,
        public string $entityType = '',
        public int $entityId = 0,
        public ?string $actionLabelTranslationKey = null,
        public ?string $actionRoute = null,
        /** @var array<string, mixed> */
        public array $actionRouteParams = [],
        public ?string $actionCsrfIntent = null,
    ) {
    }
}
