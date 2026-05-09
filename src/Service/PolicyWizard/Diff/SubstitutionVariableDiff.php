<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Diff;

/**
 * Policy-Wizard W7-C — variable-level diff helper.
 *
 * Flattens nested substitution-variable maps into dot-notation paths
 * (e.g. `tenant.legal_name`, `roles.dpo.fullName`) and compares the
 * pre/post snapshots key-by-key. Returns a flat list of changes that the
 * `PolicyDiffService` and the diff-UI table can consume directly.
 *
 * Skip rules:
 *  - Keys starting with an underscore (`_hash`, `_template_version`,
 *    `_title`, `_thin_host`, `_iso_control`, `_cross_references`) are
 *    treated as system-internal bookkeeping and excluded from the diff
 *    surface — auditors care about substantive substitutions, not the
 *    generator's own version-bookmarks.
 */
final class SubstitutionVariableDiff
{
    public const string CHANGE_ADDED = 'added';
    public const string CHANGE_REMOVED = 'removed';
    public const string CHANGE_MODIFIED = 'modified';

    /**
     * Compute the variable-level diff between two snapshots.
     *
     * @param array<string, mixed>|null $previousVars
     * @param array<string, mixed>|null $currentVars
     * @return list<array{key: string, change_type: string, oldValue: mixed, newValue: mixed}>
     */
    public static function diff(?array $previousVars, ?array $currentVars): array
    {
        $previous = self::flatten($previousVars ?? []);
        $current = self::flatten($currentVars ?? []);

        $allKeys = array_unique([...array_keys($previous), ...array_keys($current)]);
        sort($allKeys);

        $out = [];
        foreach ($allKeys as $key) {
            $hadOld = array_key_exists($key, $previous);
            $hasNew = array_key_exists($key, $current);
            $oldValue = $previous[$key] ?? null;
            $newValue = $current[$key] ?? null;

            if (!$hadOld && $hasNew) {
                $out[] = [
                    'key' => $key,
                    'change_type' => self::CHANGE_ADDED,
                    'oldValue' => null,
                    'newValue' => $newValue,
                ];
                continue;
            }
            if ($hadOld && !$hasNew) {
                $out[] = [
                    'key' => $key,
                    'change_type' => self::CHANGE_REMOVED,
                    'oldValue' => $oldValue,
                    'newValue' => null,
                ];
                continue;
            }
            // Both sides present — only emit when the value actually changed.
            if (!self::valuesEqual($oldValue, $newValue)) {
                $out[] = [
                    'key' => $key,
                    'change_type' => self::CHANGE_MODIFIED,
                    'oldValue' => $oldValue,
                    'newValue' => $newValue,
                ];
            }
        }
        return $out;
    }

    /**
     * Flatten a nested array into dot-notation keys. System-internal keys
     * (`_hash`, `_title`, …) are dropped at the top level so they do not
     * leak into the variable-level diff surface.
     *
     * @param array<int|string, mixed> $vars
     * @return array<string, mixed>
     */
    public static function flatten(array $vars, string $prefix = ''): array
    {
        $out = [];
        foreach ($vars as $rawKey => $value) {
            $key = (string) $rawKey;
            // Skip system-internal markers only at the top level — a deep
            // value like `audit._reference_id` is a domain key for us.
            if ($prefix === '' && str_starts_with($key, '_')) {
                continue;
            }
            $path = $prefix === '' ? $key : $prefix . '.' . $key;
            if (is_array($value) && !self::isList($value)) {
                $out += self::flatten($value, $path);
                continue;
            }
            $out[$path] = $value;
        }
        return $out;
    }

    /**
     * @param array<int|string, mixed> $arr
     */
    private static function isList(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_is_list($arr);
    }

    private static function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        if (is_array($a) && is_array($b)) {
            return json_encode($a) === json_encode($b);
        }
        return $a === $b;
    }
}
