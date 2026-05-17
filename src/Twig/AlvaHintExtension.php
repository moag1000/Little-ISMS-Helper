<?php

declare(strict_types=1);

namespace App\Twig;

use App\AlvaHint\AlvaHint;
use App\AlvaHint\AlvaHintService;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig bindings for AlvaHintService.
 *
 * - alva_hint(entity)       — entity-scoped hint (existing, show-pages)
 * - alva_hints(?page)       — tenant-global hints (index/dashboard/wizard)
 * - alva_hint_token_key(h)  — dismissal token string
 *
 * Templates use global hints as:
 *   {% set _hints = alva_hints('asset_index') %}
 *   {% for h in _hints %}{{ _fa_alva_hint.render(h) }}{% endfor %}
 */
final class AlvaHintExtension
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
     * Return up to 3 tenant-global hints for the given page context.
     * Pass null to skip page filtering (returns all active global hints).
     *
     * @return list<AlvaHint>
     */
    #[AsTwigFunction('alva_hints')]
    public function alvaHints(?string $page = null): array
    {
        return $this->alvaHintService->getTenantGlobalHints($page);
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
