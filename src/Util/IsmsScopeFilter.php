<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Component\HttpFoundation\Request;

/**
 * ISO 27001 4.3 scope flag (Asset, Location, BusinessProcess, Supplier):
 * the list filter "?scope=in|out", and {@see inScope()} for numbers that
 * describe the ISMS (management KPIs, SoA, reports) — those count only what
 * lies inside the scope.
 */
final class IsmsScopeFilter
{
    /**
     * @template T of object
     * @param iterable<T> $items entities with isInIsmsScope()
     * @return list<T>
     */
    public static function inScope(iterable $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if ($item->isInIsmsScope()) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @template T of object
     * @param list<T> $items entities with isInIsmsScope()
     * @return list<T>
     */
    public static function apply(array $items, Request $request): array
    {
        $scope = (string) $request->query->get('scope', '');
        if ($scope !== 'in' && $scope !== 'out') {
            return $items;
        }

        return array_values(array_filter(
            $items,
            static fn (object $e): bool => $e->isInIsmsScope() === ($scope === 'in'),
        ));
    }
}
