<?php

declare(strict_types=1);

namespace App\Twig;

use App\AlvaHint\AlvaHint;
use App\AlvaHint\AlvaHintService;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig binding for AlvaHintService::pickHintFor.
 *
 * Templates use it as:
 *   {% set hint = alva_hint(asset) %}
 *   {% if hint %}{{ _fa_alva_hint.render(hint) }}{% endif %}
 *
 * The function returns null when no rule fires or every matching rule
 * has been dismissed by the current user.
 */
class AlvaHintExtension
{
    public function __construct(
        private readonly AlvaHintService $alvaHintService,
    ) {
    }

    #[AsTwigFunction('alva_hint')]
    public function alvaHint(object $entity): ?AlvaHint
    {
        return $this->alvaHintService->pickHintFor($entity);
    }

    /**
     * Versioned key used as the dismissal token. Convention:
     * "<hint.key>@<hint.version>". Centralises the format so the
     * macro / Stimulus controller / dismissal endpoint stay in sync.
     */
    #[AsTwigFunction('alva_hint_token_key')]
    public function tokenKey(AlvaHint $hint): string
    {
        return $hint->key . '@' . $hint->version;
    }
}
