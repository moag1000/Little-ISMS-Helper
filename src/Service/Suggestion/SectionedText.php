<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

/**
 * One editable text for a draft that fills several fields (e.g. GDPR Art. 33
 * (3) a, c, d): each field is a block headed by its label on a line of its
 * own ("Label:"). The person edits the whole text in the decision dialog;
 * parse() splits it back by the same labels. A block whose heading was
 * removed keeps the drafted value.
 */
final class SectionedText
{
    /** @param array<string, string> $sections label => text */
    public static function compose(array $sections): string
    {
        $blocks = [];
        foreach ($sections as $label => $text) {
            if (trim($text) !== '') {
                $blocks[] = $label . ":\n" . trim($text);
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param array<string, string> $labels field => label
     * @return array<string, string> field => text, only for headings found in the text
     */
    public static function parse(string $text, array $labels): array
    {
        $byHeading = [];
        foreach ($labels as $field => $label) {
            $byHeading[mb_strtolower(trim($label)) . ':'] = $field;
        }
        $result = [];
        $current = null;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $key = mb_strtolower(trim($line));
            if (isset($byHeading[$key])) {
                $current = $byHeading[$key];
                $result[$current] = '';
                continue;
            }
            if ($current !== null) {
                $result[$current] .= $line . "\n";
            }
        }

        return array_map(static fn (string $t): string => trim($t), $result);
    }
}
