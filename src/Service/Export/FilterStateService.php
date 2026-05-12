<?php

declare(strict_types=1);

namespace App\Service\Export;

use Symfony\Component\HttpFoundation\Request;

/**
 * F19.1 — FilterStateService
 *
 * Captures the active filter-state from an HTTP request and serialises it
 * into a stable format suitable for passing to EntityListExporter.
 *
 * Supported filter-params (per entity list-view):
 *   status, severity, assignedTo, owner, category, framework,
 *   dateFrom, dateTo, tags, search, page (excluded from export)
 */
final class FilterStateService
{
    /** Query parameters that should not be included in export filter-state. */
    private const EXCLUDED_PARAMS = ['page', 'limit', 'sort', 'direction', '_token', 'export'];

    /**
     * Capture all active filter-params from the request for a given entity type.
     *
     * @return array<string, string|array<string>>
     */
    public function captureFilters(Request $request, string $entityType): array
    {
        $filters = [];

        foreach ($request->query->all() as $key => $value) {
            if (in_array($key, self::EXCLUDED_PARAMS, true)) {
                continue;
            }
            if ($value === '' || $value === null || $value === []) {
                continue;
            }
            $filters[$key] = $value;
        }

        $filters['_entity_type'] = $entityType;

        return $filters;
    }

    /**
     * Serialise filter-state into a stable URL-safe string (for cache-key / logging).
     *
     * @param array<string, string|array<string>> $filters
     */
    public function serializeForExport(array $filters): string
    {
        $clean = $filters;
        unset($clean['_entity_type']);
        ksort($clean);

        return http_build_query($clean);
    }

    /**
     * Return true when any non-trivial filter is active.
     *
     * @param array<string, string|array<string>> $filters
     */
    public function hasActiveFilters(array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if ($key === '_entity_type') {
                continue;
            }
            if ($value !== '' && $value !== [] && $value !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reconstruct a filter array from a serialised string (query-string format).
     *
     * @return array<string, string|array<string>>
     */
    public function deserialize(string $serialized, string $entityType): array
    {
        parse_str($serialized, $filters);
        $filters['_entity_type'] = $entityType;
        return $filters;
    }
}
