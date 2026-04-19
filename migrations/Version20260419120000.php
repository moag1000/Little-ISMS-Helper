<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pattern A dual-state: backfill *_user_id columns by matching the legacy
 * free-text owner fields against users' full name or e-mail.
 *
 * Matching strategy (case-insensitive, scoped to the same tenant):
 *  1. exact "First Last" match against `CONCAT(first_name, ' ', last_name)`
 *  2. fallback: exact match against the user's e-mail
 *
 * No data is ever deleted — the legacy string column stays in place; we only
 * populate the structured FK where we can. The string and the User picker
 * coexist via the getEffective* helpers on each entity.
 */
final class Version20260419120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pattern A: backfill *_user_id columns from legacy string fields by name / e-mail match';
    }

    /**
     * @return array<int, array{table: string, string_column: string, user_column: string, tenant_column: string}>
     */
    private function mappings(): array
    {
        return [
            ['table' => 'asset',                    'string_column' => 'owner',                     'user_column' => 'owner_user_id',                   'tenant_column' => 'tenant_id'],
            ['table' => 'business_continuity_plan', 'string_column' => 'plan_owner',                'user_column' => 'plan_owner_user_id',              'tenant_column' => 'tenant_id'],
            ['table' => 'business_process',         'string_column' => 'process_owner',             'user_column' => 'process_owner_user_id',           'tenant_column' => 'tenant_id'],
            ['table' => 'control',                  'string_column' => 'responsible_person',        'user_column' => 'responsible_person_user_id',      'tenant_column' => 'tenant_id'],
            ['table' => 'incident',                 'string_column' => 'reported_by',               'user_column' => 'reported_by_user_id',             'tenant_column' => 'tenant_id'],
            ['table' => 'risk',                     'string_column' => 'acceptance_approved_by',    'user_column' => 'acceptance_approved_by_user_id',  'tenant_column' => 'tenant_id'],
            ['table' => 'training',                 'string_column' => 'trainer',                   'user_column' => 'trainer_user_id',                 'tenant_column' => 'tenant_id'],
        ];
    }

    public function up(Schema $schema): void
    {
        foreach ($this->mappings() as $m) {
            $table = $m['table'];
            $strCol = $m['string_column'];
            $userCol = $m['user_column'];
            $tenantCol = $m['tenant_column'];

            // 1) Match on "FirstName LastName"
            $this->addSql(sprintf(
                "UPDATE `%s` t
                 INNER JOIN users u
                   ON u.tenant_id = t.%s
                  AND LOWER(TRIM(t.%s)) = LOWER(CONCAT(u.first_name, ' ', u.last_name))
                 SET t.%s = u.id
                 WHERE t.%s IS NULL
                   AND t.%s IS NOT NULL
                   AND t.%s <> ''",
                $table, $tenantCol, $strCol, $userCol, $userCol, $strCol, $strCol
            ));

            // 2) Fallback: match on email
            $this->addSql(sprintf(
                "UPDATE `%s` t
                 INNER JOIN users u
                   ON u.tenant_id = t.%s
                  AND LOWER(TRIM(t.%s)) = LOWER(u.email)
                 SET t.%s = u.id
                 WHERE t.%s IS NULL
                   AND t.%s IS NOT NULL
                   AND t.%s <> ''",
                $table, $tenantCol, $strCol, $userCol, $userCol, $strCol, $strCol
            ));
        }
    }

    public function down(Schema $schema): void
    {
        // Non-destructive — clear only the FK columns we populated above.
        foreach ($this->mappings() as $m) {
            $this->addSql(sprintf('UPDATE `%s` SET %s = NULL', $m['table'], $m['user_column']));
        }
    }
}
