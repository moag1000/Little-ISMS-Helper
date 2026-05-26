<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * FrameworkReferencesTransformer — round-trips Control.frameworkReferences
 * between the entity shape `array<framework_slug, list<reference_id>>` and
 * the flattened submit shape used by the custom widget.
 *
 * Entity shape (`array<string, list<string>>`):
 *   {iso27001: ['A.5.1', 'A.5.2'], bsi: ['ORP.1.A1'], nist: ['AC-1']}
 *
 * View / submit shape (`array<string, string>` keyed by slug, comma-list value):
 *   {iso27001: 'A.5.1,A.5.2', bsi: 'ORP.1.A1', nist: 'AC-1'}
 *
 * The form template renders a TomSelect-tag input per framework with name
 * `control[frameworkReferences][<slug>]` (comma-separated string). On
 * submit this transformer normalises back to the list-per-slug form.
 *
 * Empty (no slugs, or all slugs empty) maps to null.
 *
 * @implements DataTransformerInterface<array<string, list<string>>|null, array<string, string>>
 */
final class FrameworkReferencesTransformer implements DataTransformerInterface
{
    /**
     * Entity → view: emit one comma-joined string per slug. Unknown shapes
     * are coerced as best-effort to avoid crashing on legacy data.
     *
     * @param array<string, list<string>>|null $value
     * @return array<string, string>
     */
    public function transform(mixed $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $slug => $refs) {
            if (!is_string($slug) && !is_int($slug)) {
                continue;
            }
            $slug = (string) $slug;

            if (is_string($refs)) {
                // Legacy: caller stored a single string per slug.
                $out[$slug] = $refs;
                continue;
            }
            if (!is_array($refs)) {
                continue;
            }

            $cleaned = array_values(array_filter(
                array_map(static fn(mixed $r): string => trim((string) $r), $refs),
                static fn(string $r): bool => $r !== '',
            ));
            $out[$slug] = implode(',', $cleaned);
        }

        return $out;
    }

    /**
     * View → entity: split comma-separated strings back into lists, drop
     * empty slugs entirely. Empty input maps to null.
     *
     * @param array<string, string>|null $value
     * @return array<string, list<string>>|null
     */
    public function reverseTransform(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new TransformationFailedException('frameworkReferences view-model must be an array.');
        }
        if ($value === []) {
            return null;
        }

        $out = [];
        foreach ($value as $slug => $raw) {
            if (!is_string($slug) && !is_int($slug)) {
                continue;
            }
            $slug = (string) $slug;
            if ($slug === '') {
                continue;
            }

            $raw = is_array($raw) ? implode(',', array_map(static fn(mixed $r): string => (string) $r, $raw)) : (string) $raw;
            $parts = array_values(array_filter(
                array_map('trim', explode(',', $raw)),
                static fn(string $r): bool => $r !== '',
            ));
            if ($parts === []) {
                continue;
            }
            $out[$slug] = $parts;
        }

        return $out === [] ? null : $out;
    }
}
