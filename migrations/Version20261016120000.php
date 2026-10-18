<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notification-rule conditions saved through the old rule form used the key
 * `operator` (and the free-text operator "="), while NotificationRuleEvaluator
 * reads `op` with "==" — so every such condition silently never matched.
 * Rewrites those rows to the evaluator format the form now writes.
 * Data-only: keeps the default transactional behaviour.
 */
final class Version20261016120000 extends AbstractMigration
{
    private const OPERATOR_ALIASES = ['=' => '==', '<>' => '!=', '≠' => '!=', '≥' => '>=', '≤' => '<='];

    public function getDescription(): string
    {
        return 'Normalise notification_rule.conditions: operator → op, "=" → "=="';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, conditions FROM notification_rule WHERE conditions LIKE '%\"operator\"%'"
        );
        foreach ($rows as $row) {
            $conditions = json_decode((string) $row['conditions'], true);
            if (!is_array($conditions)) {
                continue;
            }
            $changed = false;
            foreach ($conditions as $i => $condition) {
                if (!is_array($condition) || !array_key_exists('operator', $condition)) {
                    continue;
                }
                $op = trim((string) $condition['operator']);
                unset($condition['operator']);
                $condition['op'] = $condition['op'] ?? (self::OPERATOR_ALIASES[$op] ?? $op);
                $conditions[$i] = $condition;
                $changed = true;
            }
            if ($changed) {
                $this->addSql(
                    'UPDATE notification_rule SET conditions = :conditions WHERE id = :id',
                    ['conditions' => json_encode(array_values($conditions), JSON_THROW_ON_ERROR), 'id' => (int) $row['id']],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Irreversible on purpose: the old key never worked with the evaluator.
    }
}
