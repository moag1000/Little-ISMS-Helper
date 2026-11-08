<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;
use Twig\Markup;

/**
 * Output escaping helpers (S10, Gate 92 `check_raw_output.py`).
 *
 * Components print every slot escaped; HTML reaches a slot only as Twig Markup.
 *
 *   `|fa_html`  marks reviewed HTML as Markup so a component prints it as HTML.
 *               Use it on translator HTML (`'k'|trans({'%x%': rec|e})|fa_html`) —
 *               never on record text. Gate 92 treats it like `|raw`: parameters must
 *               be escaped, anything else needs an allowlist entry with a reason.
 *               Prefer a `{% set x %}…{% endset %}` capture for mixed markup: it
 *               escapes the record values inside it and yields Markup by itself.
 *   `|fa_json`  JSON for inline scripts and single-quoted attributes. Hex-escapes
 *               < > & ' " so record text can close neither the script nor the
 *               attribute. Replaces `|json_encode|raw`.
 */
final class OutputEscapingExtension
{
    #[AsTwigFilter('fa_html')]
    public function html(mixed $value): Markup
    {
        if ($value instanceof Markup) {
            return $value;
        }

        return new Markup((string) ($value ?? ''), 'UTF-8');
    }

    #[AsTwigFilter('fa_json')]
    public function json(mixed $value, int $flags = 0): Markup
    {
        $json = json_encode(
            $value,
            $flags | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return new Markup($json === false ? 'null' : $json, 'UTF-8');
    }
}
