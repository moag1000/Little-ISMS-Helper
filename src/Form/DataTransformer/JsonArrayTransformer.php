<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Converts an entity array property to/from a pretty-printed JSON string for
 * Textarea editing. Round-trips empty / null as empty string and accepts
 * either a full JSON document or a literal empty string.
 *
 * @implements DataTransformerInterface<array|null, string>
 */
final class JsonArrayTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '';
        }
        if (!is_array($value)) {
            return '';
        }
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function reverseTransform(mixed $value): ?array
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TransformationFailedException(sprintf('JSON parse error: %s', $e->getMessage()));
        }
        if (!is_array($decoded)) {
            throw new TransformationFailedException('Top-level JSON must be an object or array.');
        }
        return $decoded;
    }
}
