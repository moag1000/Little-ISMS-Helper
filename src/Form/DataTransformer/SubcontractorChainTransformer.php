<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Two-way transformer for {@see \App\Entity\Supplier::$subcontractorChain}.
 *
 * The column stores a JSON list whose entries are either:
 *   - plain strings (legacy, "Provider A"), or
 *   - structured rows
 *     `{tier:int, name:string, lei:string, country:string, service:string,
 *       criticality:''|'low'|'medium'|'high'|'critical'}`.
 *
 * View model accepts BOTH:
 *   1. a JSON array (starts with `[`), which is parsed and per-entry-validated; or
 *   2. a newline-separated list of provider names (legacy convenience UX).
 *
 * Empty input round-trips as `null`.
 */
final class SubcontractorChainTransformer implements DataTransformerInterface
{
    private const VALID_CRITICALITY = ['low', 'medium', 'high', 'critical'];

    /**
     * @param array<int, mixed>|null $value
     */
    public function transform(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '';
        }
        if (!is_array($value)) {
            return '';
        }
        // JSON-encode so the Stimulus editor picks up structured rows;
        // legacy strings are preserved verbatim inside the encoded array.
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<mixed>|null
     */
    public function reverseTransform(mixed $value): ?array
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        if (str_starts_with($raw, '[')) {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new TransformationFailedException(
                    sprintf('Subcontractor-chain JSON parse error: %s', $e->getMessage())
                );
            }
            if (!is_array($decoded)) {
                throw new TransformationFailedException('Top-level subcontractor-chain JSON must be an array.');
            }
            $list = [];
            foreach ($decoded as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    $list[] = trim($entry);
                    continue;
                }
                if (is_array($entry)) {
                    $name = isset($entry['name']) ? trim((string) $entry['name']) : '';
                    if ($name === '') {
                        continue;
                    }
                    $tier = isset($entry['tier']) ? max(1, min(5, (int) $entry['tier'])) : 1;
                    $crit = isset($entry['criticality']) && in_array($entry['criticality'], self::VALID_CRITICALITY, true)
                        ? $entry['criticality']
                        : '';
                    $list[] = [
                        'tier' => $tier,
                        'name' => $name,
                        'lei' => isset($entry['lei']) ? trim((string) $entry['lei']) : '',
                        'country' => isset($entry['country']) ? strtoupper(substr(trim((string) $entry['country']), 0, 2)) : '',
                        'service' => isset($entry['service']) ? trim((string) $entry['service']) : '',
                        'criticality' => $crit,
                    ];
                }
            }
            return $list === [] ? null : $list;
        }
        // newline-delimited convenience format
        $list = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $raw) ?: []),
            static fn(string $v): bool => $v !== '',
        ));
        return $list === [] ? null : $list;
    }
}
