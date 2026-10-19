<?php

declare(strict_types=1);

namespace App\Form\Notification;

use Symfony\Component\Form\DataTransformerInterface;

/**
 * Array-valued condition values (`in` rules, template-seeded rules) <-> the
 * single text input of {@see ConditionRowType}.
 *
 * Text format: comma-separated cells, CSV-style — a cell containing a comma,
 * quote, newline or edge whitespace is wrapped in double quotes with `"`
 * doubled (`"a,b", c`). Lossless for any scalar item.
 *
 * Type rule (the evaluator compares `in` strictly):
 *  1. Text unchanged since it was rendered -> the ORIGINAL array is restored
 *     verbatim (any item types, nulls, nested arrays, any operator).
 *  2. Text edited -> each cell that matches the text form of an original item
 *     keeps that item's type; any other cell is a string, except a canonical
 *     integer/decimal ("12", "1.5"), which becomes int/float.
 * Stateful on purpose: Symfony builds one transformer per row form.
 *
 * @implements DataTransformerInterface<mixed, mixed>
 */
final class ConditionRowTransformer implements DataTransformerInterface
{
    /** @var list<mixed>|null */
    private ?array $original = null;
    private ?string $rendered = null;

    public function transform(mixed $row): mixed
    {
        if (is_array($row) && is_array($row['value'] ?? null)) {
            $this->original = array_values($row['value']);
            $this->rendered = self::render($this->original);
            $row['value'] = $this->rendered;
        }

        return $row;
    }

    public function reverseTransform(mixed $row): mixed
    {
        if (!is_array($row) || !is_string($row['value'] ?? null)) {
            return $row;
        }
        $text = $row['value'];
        if ($this->original !== null && $text === $this->rendered) {
            $row['value'] = $this->original;

            return $row;
        }
        if (($row['op'] ?? null) !== 'in') {
            return $row;
        }

        $known = [];
        foreach ($this->original ?? [] as $item) {
            $known[self::repr($item)] ??= $item;
        }
        $items = [];
        foreach (self::parse($text) as $cell) {
            $items[] = array_key_exists($cell, $known) ? $known[$cell] : match (true) {
                (string) (int) $cell === $cell => (int) $cell,
                is_numeric($cell) && (string) (float) $cell === $cell => (float) $cell,
                default => $cell,
            };
        }
        $row['value'] = $items;

        return $row;
    }

    private static function repr(mixed $item): string
    {
        return match (true) {
            is_string($item) => $item,
            is_bool($item) => $item ? 'true' : 'false',
            $item === null => 'null',
            is_array($item) => (string) json_encode($item),
            default => (string) $item,
        };
    }

    /** @param list<mixed> $items */
    private static function render(array $items): string
    {
        $cells = [];
        foreach ($items as $item) {
            $cell = self::repr($item);
            if ($cell === '' || preg_match('/[,"\r\n]|^\s|\s$/', $cell) === 1) {
                $cell = '"' . str_replace('"', '""', $cell) . '"';
            }
            $cells[] = $cell;
        }

        return implode(', ', $cells);
    }

    /** @return list<string> empty unquoted cells are skipped, quoted empty ones kept */
    private static function parse(string $text): array
    {
        $cells = [];
        $cell = '';
        $quoted = false;
        $inQuotes = false;
        $len = strlen($text);
        $flush = static function () use (&$cells, &$cell, &$quoted): void {
            $value = $quoted ? $cell : trim($cell);
            if ($quoted || $value !== '') {
                $cells[] = $value;
            }
            $cell = '';
            $quoted = false;
        };
        for ($i = 0; $i < $len; ++$i) {
            $ch = $text[$i];
            if ($inQuotes) {
                if ($ch === '"' && ($text[$i + 1] ?? '') === '"') {
                    $cell .= '"';
                    ++$i;
                } elseif ($ch === '"') {
                    $inQuotes = false;
                } else {
                    $cell .= $ch;
                }
            } elseif ($ch === '"' && trim($cell) === '') {
                $inQuotes = true;
                $quoted = true;
                $cell = '';
            } elseif ($ch === ',') {
                $flush();
            } elseif (!$quoted || trim($ch) !== '') {
                $cell .= $ch;
            }
        }
        $flush();

        return $cells;
    }
}
