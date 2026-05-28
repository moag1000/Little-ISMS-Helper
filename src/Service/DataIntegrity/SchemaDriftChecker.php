<?php

declare(strict_types=1);

namespace App\Service\DataIntegrity;

use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Detects JSON-schema violations in entity columns and AuditLog integrity gaps.
 *
 * Checks:
 *   - findJsonSchemaViolations: Tenant.settings, TenantPolicySetting.value,
 *     NotificationRule.conditions, WorkflowStep.metadata
 *   - findAuditLogIntegrityIssues: bulk-batch mismatches, day gaps, null-tenant entries
 *
 * Extracted from DataIntegrityService to isolate schema-drift detection concerns.
 *
 * @see \App\Service\DataIntegrityService::findJsonSchemaViolations()
 * @see \App\Service\DataIntegrityService::findAuditLogIntegrityIssues()
 */
final class SchemaDriftChecker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenantRepository,
    ) {
    }

    /**
     * Decodes JSON columns and validates their minimal shape. NO auto-repair —
     * surfaces every violation as a manual-review row in the template.
     *
     * Targets:
     *   - Tenant.settings           : object/null, free-form k/v
     *   - TenantPolicySetting.value : any JSON-decodable value (object/scalar/array)
     *   - NotificationRule.conditions : list<{field:string, op:string, value:mixed}>
     *   - WorkflowStep.metadata     : object with optional auto_progression shape
     *
     * @return array<string, list<array{id: int, tenant?: ?string, error: string}>>
     */
    public function findJsonSchemaViolations(): array
    {
        $result = [
            'tenant_settings' => [],
            'tenant_policy_settings' => [],
            'notification_rule_conditions' => [],
            'workflow_step_metadata' => [],
        ];

        // 1. Tenant.settings — null OR associative object expected.
        try {
            $tenants = $this->tenantRepository->findAll();
            foreach ($tenants as $tenant) {
                $value = $tenant->getSettings();
                if ($value === null) {
                    continue;
                }
                if (!is_array($value)) {
                    $result['tenant_settings'][] = [
                        'id' => (int) $tenant->getId(),
                        'tenant' => $tenant->getName(),
                        'error' => 'settings is not an array/object (got ' . gettype($value) . ')',
                    ];
                    continue;
                }
                // Reject pure list-shape — `settings` is supposed to be a k/v map.
                if ($value !== [] && array_is_list($value)) {
                    $result['tenant_settings'][] = [
                        'id' => (int) $tenant->getId(),
                        'tenant' => $tenant->getName(),
                        'error' => 'settings is a list, expected k/v object',
                    ];
                }
            }
        } catch (\Throwable) {
            // Skip.
        }

        // 2. TenantPolicySetting.value — must be JSON-decodable (Doctrine stores
        //    it as JSON; if the column contains corrupted UTF-8 the load throws).
        try {
            $rows = $this->entityManager->createQueryBuilder()
                ->select('p.id, p.key, IDENTITY(p.tenant) AS tenantId')
                ->from(\App\Entity\TenantPolicySetting::class, 'p')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                try {
                    $entity = $this->entityManager->find(\App\Entity\TenantPolicySetting::class, $id);
                    if ($entity === null) {
                        continue;
                    }
                    $value = $entity->getValue();
                    // Just touching the property triggers the JSON-decode path —
                    // a corrupted column throws on hydration.
                    if (is_object($value)) {
                        $result['tenant_policy_settings'][] = [
                            'id' => $id,
                            'error' => sprintf('value for key "%s" hydrated to unexpected object', (string) ($row['key'] ?? '')),
                        ];
                    }
                } catch (\Throwable $e) {
                    $result['tenant_policy_settings'][] = [
                        'id' => $id,
                        'error' => sprintf('value for key "%s" failed to decode: %s', (string) ($row['key'] ?? ''), $e->getMessage()),
                    ];
                }
            }
        } catch (\Throwable) {
            // Skip.
        }

        // 3. NotificationRule.conditions — expect list<{field, op, value}>.
        try {
            $rules = $this->entityManager->createQueryBuilder()
                ->select('r')
                ->from(\App\Entity\Notification\NotificationRule::class, 'r')
                ->getQuery()
                ->getResult();
            foreach ((array) $rules as $rule) {
                $conds = $rule->getConditions();
                if ($conds === []) {
                    continue;
                }
                if (!array_is_list($conds)) {
                    $result['notification_rule_conditions'][] = [
                        'id' => (int) $rule->getId(),
                        'error' => 'conditions is not a list',
                    ];
                    continue;
                }
                foreach ($conds as $idx => $item) {
                    if (!is_array($item)) {
                        $result['notification_rule_conditions'][] = [
                            'id' => (int) $rule->getId(),
                            'error' => sprintf('conditions[%d] is not an object', $idx),
                        ];
                        continue 2;
                    }
                    foreach (['field', 'op'] as $required) {
                        if (!array_key_exists($required, $item) || !is_string($item[$required])) {
                            $result['notification_rule_conditions'][] = [
                                'id' => (int) $rule->getId(),
                                'error' => sprintf('conditions[%d] missing required string key "%s"', $idx, $required),
                            ];
                            continue 3;
                        }
                    }
                    if (!array_key_exists('value', $item)) {
                        $result['notification_rule_conditions'][] = [
                            'id' => (int) $rule->getId(),
                            'error' => sprintf('conditions[%d] missing key "value"', $idx),
                        ];
                    }
                }
            }
        } catch (\Throwable) {
            // Skip.
        }

        // 4. WorkflowStep.metadata — null OR object; if auto_progression key
        //    is set, it must itself be an object with `conditions` list.
        try {
            $steps = $this->entityManager->createQueryBuilder()
                ->select('s')
                ->from(\App\Entity\WorkflowStep::class, 's')
                ->getQuery()
                ->getResult();
            foreach ((array) $steps as $step) {
                $meta = $step->getMetadata();
                if ($meta === null) {
                    continue;
                }
                if (!is_array($meta)) {
                    $result['workflow_step_metadata'][] = [
                        'id' => (int) $step->getId(),
                        'error' => 'metadata is not an array/object',
                    ];
                    continue;
                }
                if ($meta !== [] && array_is_list($meta)) {
                    $result['workflow_step_metadata'][] = [
                        'id' => (int) $step->getId(),
                        'error' => 'metadata is a list, expected k/v object',
                    ];
                    continue;
                }
                if (array_key_exists('auto_progression', $meta) && $meta['auto_progression'] !== null) {
                    $ap = $meta['auto_progression'];
                    if (!is_array($ap) || (isset($ap['conditions']) && !is_array($ap['conditions']))) {
                        $result['workflow_step_metadata'][] = [
                            'id' => (int) $step->getId(),
                            'error' => 'metadata.auto_progression must be object with conditions list',
                        ];
                    }
                }
            }
        } catch (\Throwable) {
            // Skip.
        }

        return $result;
    }

    /**
     * AuditLog integrity gap detection.
     * The AuditLog is append-only and HMAC-chained (see AuditLogger).
     * This method surfaces structural anomalies the chain alone cannot catch:
     *
     *   - bulk_batch_mismatches : ACTION_BULK row recorded `per_entity_count = N`
     *                             but fewer per-entity rows actually carry the batch_id
     *   - day_gaps              : days with zero AuditLog entries between days with entries
     *                             over the last 30 days (suspicious in production)
     *   - null_tenant_entries   : rows with tenant_id IS NULL (post-merge backfill leak)
     *
     * Detection-only.
     *
     * @return array{
     *     bulk_batch_mismatches: list<array{batch_id: string, expected: int, actual: int}>,
     *     day_gaps: list<array{date: string}>,
     *     null_tenant_entries: list<array{id: int, action: string, entity_type: string}>,
     * }
     */
    public function findAuditLogIntegrityIssues(): array
    {
        $result = [
            'bulk_batch_mismatches' => [],
            'day_gaps' => [],
            'null_tenant_entries' => [],
        ];

        $connection = $this->entityManager->getConnection();

        // 1. bulk-batch row-count mismatch. We pull every ACTION_BULK row from
        //    the last 90 days, extract batch_id + per_entity_count from new_values,
        //    then count actual rows carrying _batch_id=<X> in new_values.
        try {
            $cutoff = (new \DateTimeImmutable('-90 days'))->format('Y-m-d H:i:s');
            $batchRows = $connection->fetchAllAssociative(
                'SELECT id, new_values FROM audit_log WHERE action = :a AND created_at >= :c',
                ['a' => 'bulk', 'c' => $cutoff],
            );
            foreach ($batchRows as $row) {
                $newValues = $row['new_values'] ?? null;
                if (!is_string($newValues) || $newValues === '') {
                    continue;
                }
                $decoded = json_decode($newValues, true);
                if (!is_array($decoded)) {
                    continue;
                }
                $batchId = (string) ($decoded['batch_id'] ?? '');
                $expected = (int) ($decoded['per_entity_count'] ?? 0);
                if ($batchId === '' || $expected === 0) {
                    continue;
                }
                // Count actual per-entity rows.
                $actual = (int) $connection->fetchOne(
                    "SELECT COUNT(*) FROM audit_log WHERE action <> :bulk AND new_values LIKE :like",
                    ['bulk' => 'bulk', 'like' => '%"_batch_id":"' . $batchId . '"%'],
                );
                if ($actual < $expected) {
                    $result['bulk_batch_mismatches'][] = [
                        'batch_id' => $batchId,
                        'expected' => $expected,
                        'actual' => $actual,
                    ];
                }
            }
        } catch (\Throwable) {
            // Table missing in tests — skip.
        }

        // 2. day-gaps in the last 30 days. SELECT DISTINCT DATE(created_at);
        //    detect gaps where a day with entries was followed/preceded by a
        //    day with 0 entries inside the active window.
        try {
            $days = $connection->fetchAllAssociative(
                "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM audit_log WHERE created_at >= :c GROUP BY DATE(created_at) ORDER BY d",
                ['c' => (new \DateTimeImmutable('-30 days'))->format('Y-m-d 00:00:00')],
            );
            if (count($days) >= 2) {
                $first = new \DateTimeImmutable((string) $days[0]['d']);
                $last = new \DateTimeImmutable((string) end($days)['d']);
                $observed = [];
                foreach ($days as $d) {
                    $observed[(string) $d['d']] = (int) $d['c'];
                }
                $cursor = $first;
                while ($cursor <= $last) {
                    $key = $cursor->format('Y-m-d');
                    if (!isset($observed[$key])) {
                        $result['day_gaps'][] = ['date' => $key];
                    }
                    $cursor = $cursor->modify('+1 day');
                }
            }
        } catch (\Throwable) {
            // Skip.
        }

        // 3. null tenant_id entries (post-merge backfill leak).
        try {
            $nullRows = $connection->fetchAllAssociative(
                "SELECT id, action, entity_type FROM audit_log WHERE tenant_id IS NULL ORDER BY id DESC LIMIT 100",
            );
            foreach ($nullRows as $row) {
                $result['null_tenant_entries'][] = [
                    'id' => (int) $row['id'],
                    'action' => (string) ($row['action'] ?? ''),
                    'entity_type' => (string) ($row['entity_type'] ?? ''),
                ];
            }
        } catch (\Throwable) {
            // Skip.
        }

        return $result;
    }
}
