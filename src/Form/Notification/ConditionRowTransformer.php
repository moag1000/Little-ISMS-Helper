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
 *
 * The original is found through the row's hidden `rowKey` (registered in the
 * form-wide {@see ConditionRowOriginals}), not through the collection index,
 * so it survives rows removed in the middle and re-indexed submissions. The
 * key never reaches the stored condition.
 *
 * @implements DataTransformerInterface<mixed, mixed>
 */
final class ConditionRowTransformer implements DataTransformerInterface
{
    public const string ROW_KEY = 'rowKey';

    public function __construct(
        private readonly ConditionRowOriginals $originals,
    ) {
    }

    public function transform(mixed $row): mixed
    {
        if (is_array($row) && is_array($row['value'] ?? null)) {
            $original = array_values($row['value']);
            $row[self::ROW_KEY] = $this->originals->register($original);
            $row['value'] = self::render($original);
        }

        return $row;
    }

    public function reverseTransform(mixed $row): mixed
    {
        if (!is_array($row)) {
            return $row;
        }
        $original = $this->originals->get($row[self::ROW_KEY] ?? null);
        unset($row[self::ROW_KEY]);
        if (!is_string($row['value'] ?? null)) {
            return $row;
        }
        $text = $row['value'];
        if ($original !== null && $text === self::render($original)) {
            $row['value'] = $original;

            return $row;
        }
        if (($row['op'] ?? null) !== 'in') {
            return $row;
        }

        $known = [];
        foreach ($original ?? [] as $item) {
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
        /** @var list<array{string, bool}> $raw */
        $raw = [];
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
                $raw[] = [$cell, $quoted];
                $cell = '';
                $quoted = false;
            } elseif (!$quoted || trim($ch) !== '') {
                $cell .= $ch;
            }
        }
        $raw[] = [$cell, $quoted];
        foreach ($raw as [$value, $wasQuoted]) {
            $value = $wasQuoted ? $value : trim($value);
            if ($wasQuoted || $value !== '') {
                $cells[] = $value;
            }
        }

        return $cells;
    }
}
