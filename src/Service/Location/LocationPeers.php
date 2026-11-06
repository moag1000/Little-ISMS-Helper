<?php

declare(strict_types=1);

namespace App\Service\Location;

/**
 * Peer comparison for site owners: a site is compared with comparable sites,
 * never with people.
 *
 * Peer group, first rule that yields at least {@see self::MIN_GROUP} sites:
 *   1. a shared tag (custom groups such as "Werke Lateinamerika"),
 *   2. the same location type and a protection need at most one step apart,
 *   3. a protection need at most one step apart, any type.
 * Smaller groups get no comparison: with two sites the other one would be
 * identifiable at once. The country is no peer group; it mixes types and is
 * usually too small. It stays a level and a filter on the map.
 *
 * Pure function over {@see LocationPostureService::forTenant()} rows.
 */
final class LocationPeers
{
    public const int MIN_GROUP = 3;

    /**
     * @param list<array<string, mixed>> $rows posture rows
     *
     * @return array<int, array{
     *     basis: string, tag: ?string, size: int, rate: ?int, medianRate: ?int, medianScore: int,
     *     rank: ?string, solvedAt: array<string, array{id: int, name: string}>
     * }> site id => comparison; sites without a large enough group are missing
     */
    public static function compare(array $rows): array
    {
        $result = [];
        foreach ($rows as $site) {
            [$basis, $tag, $group] = self::group($site, $rows);
            if ($group === []) {
                continue;
            }

            $rates = array_values(array_filter(array_map(static fn (array $s): ?int => $s['checksRate'], $group), 'is_int'));
            $result[(int) $site['id']] = [
                'basis' => $basis,
                'tag' => $tag,
                'size' => count($group),
                'rate' => $site['checksRate'],
                'medianRate' => $rates !== [] ? (int) round(self::median($rates)) : null,
                'medianScore' => (int) round(self::median(array_map(static fn (array $s): int => (int) $s['score'], $group))),
                'rank' => $site['checksRate'] !== null ? self::rank((int) $site['checksRate'], $rates) : null,
                'solvedAt' => self::solvedAt($site, $group),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed>       $site
     * @param list<array<string, mixed>> $rows
     *
     * @return array{0: string, 1: ?string, 2: list<array<string, mixed>>} basis, tag, group incl. the site
     */
    private static function group(array $site, array $rows): array
    {
        $tags = $site['tags'] ?? [];
        sort($tags);
        foreach ($tags as $tag) {
            $group = array_values(array_filter($rows, static fn (array $s): bool => in_array($tag, $s['tags'] ?? [], true)));
            if (count($group) >= self::MIN_GROUP) {
                return ['tag', $tag, $group];
            }
        }

        $near = static fn (array $s): bool => abs((int) $s['protection'] - (int) $site['protection']) <= 1;
        $group = array_values(array_filter($rows, static fn (array $s): bool => $s['type'] === $site['type'] && $near($s)));
        if (count($group) >= self::MIN_GROUP) {
            return ['type', null, $group];
        }

        $group = array_values(array_filter($rows, $near));
        if (count($group) >= self::MIN_GROUP) {
            return ['band', null, $group];
        }

        return ['none', null, []];
    }

    /** @param list<int> $values */
    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * Third of the group the site's rate falls into (higher is better).
     *
     * @param list<int> $rates
     */
    private static function rank(int $rate, array $rates): string
    {
        $better = count(array_filter($rates, static fn (int $r): bool => $r > $rate));
        $position = $better / max(1, count($rates));

        return match (true) {
            $position < 1 / 3 => 'top',
            $position < 2 / 3 => 'middle',
            default => 'bottom',
        };
    }

    /**
     * For each open check: the peer site with the best rate that passes it, so
     * the owner can see how others solved it.
     *
     * @param array<string, mixed>       $site
     * @param list<array<string, mixed>> $group
     *
     * @return array<string, array{id: int, name: string}>
     */
    private static function solvedAt(array $site, array $group): array
    {
        usort($group, static fn (array $a, array $b): int => ($b['checksRate'] ?? -1) <=> ($a['checksRate'] ?? -1));
        $solved = [];
        foreach ($site['gaps'] as $check) {
            foreach ($group as $peer) {
                if ($peer['id'] !== $site['id'] && ($peer['checks'][$check] ?? null) === true) {
                    $solved[$check] = ['id' => (int) $peer['id'], 'name' => (string) $peer['name']];
                    break;
                }
            }
        }

        return $solved;
    }
}
