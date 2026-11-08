<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Legacy free-text owners → User / Person (step 1 of 2; the next migration drops
 * the columns).
 *
 * For every one of the 14 owner slots whose User and Person reference are both
 * empty but whose old text column is filled:
 *   1. exactly one active user of the record's organisation has that e-mail
 *      address or name ("Max Muster", "Muster Max", "Muster, Max") → that user;
 *   2. else exactly one active person of the organisation has that name or
 *      e-mail address → that person;
 *   3. else a person of that name is created in the organisation (type
 *      "other", note "Aus Freitext übernommen") and set.
 * Nothing is dropped: every text ends up as a reference. The same rules as the
 * former admin page "Freitext-Verantwortliche" (OwnerNameMatcher), done in SQL
 * so it runs on every installation without a manual step.
 */
final class Version20261108100000_OwnerTextToPersons extends AbstractMigration
{
    /**
     * table => [legacy text column, user FK column, person FK column]
     */
    private const array SLOTS = [
        'asset' => ['owner', 'owner_user_id', 'owner_person_id'],
        'control' => ['responsible_person', 'responsible_person_user_id', 'responsible_person_contact_id'],
        'business_process' => ['process_owner', 'process_owner_user_id', 'process_owner_person_id'],
        'business_continuity_plan' => ['plan_owner', 'plan_owner_user_id', 'plan_owner_person_id'],
        'bc_exercise' => ['facilitator', 'facilitator_user_id', 'facilitator_person_id'],
        'incident' => ['reported_by', 'reported_by_user_id', 'reported_by_person_id'],
        'internal_audit' => ['lead_auditor', 'lead_auditor_user_id', 'lead_auditor_person_id'],
        'training' => ['trainer', 'trainer_user_id', 'trainer_person_id'],
        'ismsobjective' => ['responsible_person', 'responsible_person_user_id', 'responsible_person_ref_id'],
        'supplier' => ['contact_person', 'contact_person_user_id', 'contact_person_ref_id'],
        'interested_party' => ['contact_person', 'contact_person_user_id', 'contact_person_ref_id'],
        'location' => ['responsible_person', 'responsible_person_user_id', 'responsible_person_ref_id'],
        'vulnerabilities' => ['responsible_person', 'responsible_person_user_id', 'responsible_person_ref_id'],
        'patches' => ['responsible_person', 'responsible_person_user_id', 'responsible_person_ref_id'],
    ];

    public function getDescription(): string
    {
        return 'Free-text owners become user or person references (before the text columns are dropped)';
    }

    public function up(Schema $schema): void
    {
        // Comparison key: trimmed, lower case (the columns are *_unicode_ci anyway).
        $userKeys = 'SELECT tenant_id, id, LOWER(TRIM(email)) AS k FROM users WHERE is_active = 1 AND email IS NOT NULL'
            . " UNION SELECT tenant_id, id, LOWER(CONCAT(TRIM(first_name), ' ', TRIM(last_name))) FROM users"
            . " WHERE is_active = 1 AND TRIM(COALESCE(first_name, '')) <> '' AND TRIM(COALESCE(last_name, '')) <> ''"
            . " UNION SELECT tenant_id, id, LOWER(CONCAT(TRIM(last_name), ' ', TRIM(first_name))) FROM users"
            . " WHERE is_active = 1 AND TRIM(COALESCE(first_name, '')) <> '' AND TRIM(COALESCE(last_name, '')) <> ''"
            . " UNION SELECT tenant_id, id, LOWER(CONCAT(TRIM(last_name), ', ', TRIM(first_name))) FROM users"
            . " WHERE is_active = 1 AND TRIM(COALESCE(first_name, '')) <> '' AND TRIM(COALESCE(last_name, '')) <> ''";
        $uniqueUsers = 'SELECT tenant_id, k, MIN(id) AS id FROM (' . $userKeys . ') uk'
            . ' WHERE tenant_id IS NOT NULL GROUP BY tenant_id, k HAVING COUNT(DISTINCT id) = 1';

        $personKeys = 'SELECT tenant_id, id, LOWER(TRIM(full_name)) AS k FROM person WHERE active = 1'
            . ' UNION SELECT tenant_id, id, LOWER(TRIM(email)) FROM person WHERE active = 1 AND email IS NOT NULL';
        $uniquePersons = 'SELECT tenant_id, k, MIN(id) AS id FROM (' . $personKeys . ') pk'
            . ' WHERE tenant_id IS NOT NULL GROUP BY tenant_id, k HAVING COUNT(DISTINCT id) = 1';

        foreach (self::SLOTS as $table => [$text, $user, $person]) {
            $open = sprintf(
                "t.%1\$s IS NULL AND t.%2\$s IS NULL AND t.%3\$s IS NOT NULL AND TRIM(t.%3\$s) <> '' AND t.tenant_id IS NOT NULL",
                $user,
                $person,
                $text,
            );

            // 1. one matching user
            $this->addSql(sprintf(
                'UPDATE %s t JOIN (%s) m ON m.tenant_id = t.tenant_id AND m.k = LOWER(TRIM(t.%s)) SET t.%s = m.id WHERE %s',
                $table, $uniqueUsers, $text, $user, $open,
            ));
            // 2. one matching person
            $this->addSql(sprintf(
                'UPDATE %s t JOIN (%s) m ON m.tenant_id = t.tenant_id AND m.k = LOWER(TRIM(t.%s)) SET t.%s = m.id WHERE %s',
                $table, $uniquePersons, $text, $person, $open,
            ));
            // 3a. the remaining names as persons of the organisation (one per name)
            $this->addSql(sprintf(
                'INSERT INTO person (tenant_id, full_name, person_type, active, notes, created_at, isms_availability_pct)'
                . " SELECT t.tenant_id, LEFT(MIN(TRIM(t.%1\$s)), 255), 'other', 1, 'Aus Freitext übernommen', NOW(), 1.0"
                . ' FROM %2$s t WHERE %3$s'
                . ' AND NOT EXISTS (SELECT 1 FROM person p WHERE p.tenant_id = t.tenant_id AND LOWER(TRIM(p.full_name)) = LOWER(LEFT(TRIM(t.%1$s), 255)))'
                . ' GROUP BY t.tenant_id, LOWER(LEFT(TRIM(t.%1$s), 255))',
                $text, $table, $open,
            ));
            // 3b. link every remaining record to the person of that name
            $this->addSql(sprintf(
                'UPDATE %s t SET t.%s = (SELECT MIN(p.id) FROM person p WHERE p.tenant_id = t.tenant_id'
                . ' AND LOWER(TRIM(p.full_name)) = LOWER(LEFT(TRIM(t.%s), 255))) WHERE %s',
                $table, $person, $text, $open,
            ));
        }

        // The proposal kind "Verantwortliche zuordnen" is gone with the columns.
        $this->addSql("UPDATE suggestion SET status = 'superseded' WHERE kind = 'legacy_owner.match' AND status = 'pending'");
    }

    public function down(Schema $schema): void
    {
        // Data migration: the references stay; the text columns still hold the old values until the next migration.
    }
}
