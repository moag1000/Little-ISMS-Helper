<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;

/**
 * Two-way transformer for JSON list-of-strings columns whose UI is a textarea
 * accepting comma-, semicolon-, OR newline-separated tokens
 * (Supplier.processingLocations uses this for country/location codes).
 *
 * View model:  "DE, IE, US" / "DE\nIE\nUS"
 * Norm model:  list<string> (deduplicated and empty-filtered) or null
 *
 * Empty / non-array inputs round-trip as `null`.
 */
final class CommaOrLinesListTransformer implements DataTransformerInterface
{
    /**
     * @param array<int, mixed>|null $value
     */
    public function transform(mixed $value): string
    {
        if (!is_array($value) || $value === []) {
            return '';
        }
        // Filter to scalars; legacy data may contain nested structures.
        $flat = array_values(array_filter($value, 'is_scalar'));
        return implode(', ', array_map('strval', $flat));
    }

    /**
     * @return list<string>|null
     */
    public function reverseTransform(mixed $value): ?array
    {
        $raw = (string) ($value ?? '');
        if (trim($raw) === '') {
            return null;
        }
        $parts = array_values(array_filter(
            array_map('trim', preg_split('/[,;\r\n]+/', $raw) ?: []),
            static fn(string $v): bool => $v !== '',
        ));
        return $parts === [] ? null : $parts;
    }
}
