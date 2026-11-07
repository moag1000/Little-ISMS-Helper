<?php

declare(strict_types=1);

namespace App\Service\Supplier;

use Doctrine\DBAL\Connection;

/**
 * Turns the former JSON column supplier.subcontractor_chain into
 * dora_subcontractor rows (P5: one list). Used by the migration and by the
 * restore of backups made before it. JSON tier 1 = direct subcontractor
 * (row tier 2); an entry hangs below the nearest earlier entry on a higher level.
 */
final class SubcontractorChainRows
{
    private const array CRITICALITY = ['critical' => 'critical', 'high' => 'critical', 'important' => 'important', 'medium' => 'important'];

    /**
     * @param array<int|string, mixed> $chain
     * @return list<array{tier: int, name: string, lei: ?string, country: ?string, service: ?string, criticality: string, parent: ?int}>
     */
    public static function fromJson(array $chain): array
    {
        $rows = [];
        $lastAtTier = [];
        foreach ($chain as $entry) {
            $entry = is_array($entry) ? $entry : ['name' => is_scalar($entry) ? (string) $entry : ''];
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $jsonTier = min(4, max(1, (int) ($entry['tier'] ?? 1)));
            $country = strtoupper(trim((string) ($entry['country'] ?? '')));
            $criticality = !empty($entry['critical'])
                ? 'critical'
                : (self::CRITICALITY[strtolower((string) ($entry['criticality'] ?? ''))] ?? 'standard');
            $parent = null;
            for ($t = $jsonTier - 1; $t >= 1 && $parent === null; --$t) {
                $parent = $lastAtTier[$t] ?? null;
            }
            $tier = $parent === null ? 2 : min(5, $rows[$parent]['tier'] + 1);
            $rows[] = [
                'tier' => $tier,
                'name' => mb_substr($name, 0, 255),
                'lei' => ($lei = trim((string) ($entry['lei'] ?? ''))) !== '' ? mb_substr($lei, 0, 20) : null,
                'country' => strlen($country) === 2 ? $country : null,
                'service' => ($service = trim((string) ($entry['service'] ?? ''))) !== '' ? $service : null,
                'criticality' => $criticality,
                'parent' => $parent,
            ];
            $level = $tier - 1;
            $lastAtTier[$level] = count($rows) - 1;
            foreach (array_keys($lastAtTier) as $deeper) {
                if ($deeper > $level) {
                    unset($lastAtTier[$deeper]);
                }
            }
        }
        return $rows;
    }

    /**
     * Inserts the chain below the supplier; names the supplier already has are skipped.
     *
     * @param array<int|string, mixed> $chain
     */
    public static function insert(Connection $connection, int $supplierId, ?int $tenantId, array $chain, string $now): int
    {
        $existing = array_map(
            static fn (mixed $n): string => mb_strtolower((string) $n),
            $connection->fetchFirstColumn('SELECT name FROM dora_subcontractor WHERE parent_supplier_id = ?', [$supplierId]),
        );
        $ids = [];
        $inserted = 0;
        foreach (self::fromJson($chain) as $i => $row) {
            if (in_array(mb_strtolower($row['name']), $existing, true)) {
                continue;
            }
            $connection->insert('dora_subcontractor', [
                'tenant_id' => $tenantId,
                'parent_supplier_id' => $supplierId,
                'parent_subcontractor_id' => $row['parent'] !== null ? ($ids[$row['parent']] ?? null) : null,
                'name' => $row['name'],
                'lei_code' => $row['lei'],
                'country' => $row['country'],
                'service_description' => $row['service'],
                'tier' => $row['tier'],
                'criticality' => $row['criticality'],
                'substitutability' => 'medium',
                'created_at' => $now,
            ]);
            $ids[$i] = (int) $connection->lastInsertId();
            ++$inserted;
        }
        return $inserted;
    }
}
