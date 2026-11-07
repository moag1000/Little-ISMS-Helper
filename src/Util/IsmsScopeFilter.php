<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Component\HttpFoundation\Request;

/**
 * List filter "?scope=in|out" for records that carry the ISO 27001 4.3 scope flag
 * (Asset, Location, BusinessProcess, Supplier).
 */
final class IsmsScopeFilter
{
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
