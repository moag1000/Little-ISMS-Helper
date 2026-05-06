<?php

declare(strict_types=1);

namespace App\AlvaHint;

use InvalidArgumentException;

/**
 * Immutable hint payload returned by AlvaHintRule::build().
 *
 * The DTO keeps presentation concerns (translation keys, button label,
 * variant, mood) but never holds the rule logic itself. The Twig macro
 * `_components/_fa_alva_hint.html.twig` consumes it as-is.
 *
 * - `key`: stable identifier used for dismissal + audit log + DOM ids.
 * - `priorityTier`: 1 = Pflicht (regulatory), 2 = audit gap, 3 = efficiency.
 *   Tier 1 is forced non-dismissible: a 72h-DSGVO-countdown must not be
 *   wishable away. The constructor enforces this invariant.
 * - `requiredRoles`: roles the current user must hold for the hint /
 *   action to be relevant. Empty = unconditional.
 * - `mood`: Alva-mascot mood the surrounding card should reflect. Maps
 *   to the AlvaMoodExtension catalogue.
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
        /** @var array<int, string>  e.g. ['ROLE_MANAGER'] */
        public array $requiredRoles = [],
        public string $mood = 'thinking',
    ) {
        if ($this->priorityTier < 1 || $this->priorityTier > 3) {
            throw new InvalidArgumentException('priorityTier must be 1, 2, or 3.');
        }
        if ($this->priorityTier === 1 && $this->dismissible) {
            throw new InvalidArgumentException(
                'Tier-1 (regulatory) hints must not be dismissible — pass dismissible: false.',
            );
        }
    }
}
