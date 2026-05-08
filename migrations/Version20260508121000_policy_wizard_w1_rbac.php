<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard Sprint W1-B — RBAC-Erweiterung.
 *
 * Seedet die fünf neuen `policy_wizard.*` Permissions, die in Phase 4-C
 * §7-#4 spezifiziert wurden, sowie die zwei System-Rollen
 * `ROLE_GROUP_BCM_OFFICER` und `ROLE_FUNCTION_OWNER`. Verknüpft
 * Permissions mit den korrekten Rollen entsprechend der Verantwortungs-
 * matrix:
 *
 *  policy_wizard.bulk_approval         → ROLE_TOP_MGMT
 *  policy_wizard.function_owner_review → ROLE_FUNCTION_OWNER, ROLE_CISO
 *  policy_wizard.konzern_defaults      → ROLE_GROUP_CISO, ROLE_GROUP_BCM_OFFICER
 *  policy_wizard.bcm_auto_create       → ROLE_GROUP_BCM_OFFICER, ROLE_BCM_OFFICER
 *  policy_wizard.dpo_section_veto      → ROLE_DPO
 *
 * Idempotent: alle INSERTs verwenden ON DUPLICATE KEY UPDATE / NOT EXISTS,
 * so dass die Migration auf einer DB, in der Rollen oder Permissions
 * bereits durch frühere Setup-Commands seeded wurden, gefahrlos
 * wiederholt werden kann.
 */
final class Version20260508121000_policy_wizard_w1_rbac extends AbstractMigration
{
    private const string CREATED_AT = '2026-05-08 12:10:00';

    /**
     * @var list<array{name: string, description: string, category: string, action: string}>
     */
    private const array PERMISSIONS = [
        [
            'name' => 'policy_wizard.bulk_approval',
            'description' => 'Bulk-Freigabe mehrerer Policy-Dokumente (W1-Workflow Step Top-Management). DORA-Tenants benötigen zusätzliche dual-signoff Bestätigung.',
            'category' => 'policy_wizard',
            'action' => 'approve',
        ],
        [
            'name' => 'policy_wizard.function_owner_review',
            'description' => 'Function-Owner-Review-Sign-Off für Policies, deren affectedFunctions die eigene Funktion abdecken (P1 Risk-Owner, W2 Step 3).',
            'category' => 'policy_wizard',
            'action' => 'review',
        ],
        [
            'name' => 'policy_wizard.konzern_defaults',
            'description' => 'Pflege Konzern-weiter Policy-Defaults (Konzern-ISB / Group-BCM-Officer). Wirkt downstream auf inheritable Policies.',
            'category' => 'policy_wizard',
            'action' => 'manage',
        ],
        [
            'name' => 'policy_wizard.bcm_auto_create',
            'description' => 'Automatisches Anlegen abgeleiteter BCM-Pläne aus Policy-Wizard (W5 BCM-Flow).',
            'category' => 'policy_wizard',
            'action' => 'create',
        ],
        [
            'name' => 'policy_wizard.dpo_section_veto',
            'description' => 'DPO-Veto auf der DPO-Cross-Check-Section eines Policy-Dokuments (P1 DSGVO-Schutz).',
            'category' => 'policy_wizard',
            'action' => 'veto',
        ],
    ];

    /**
     * @var list<array{name: string, description: string}>
     */
    private const array ROLES = [
        [
            'name' => 'ROLE_GROUP_BCM_OFFICER',
            'description' => 'Konzern-BCM-Officer: Steuert konzernweite BCM-Standards und Policy-Defaults für BCM-relevante Themen.',
        ],
        [
            'name' => 'ROLE_FUNCTION_OWNER',
            'description' => 'Fachbereichsleiter / Function-Owner: Zeichnet Policies für die eigene Business-Function (P1 Risk-Owner).',
        ],
    ];

    /**
     * Permission → Rollen Mapping.
     *
     * Schlüssel = permission.name, Wert = Liste von role.name. Die Rollen
     * können System-Rollen (z.B. ROLE_TOP_MGMT, ROLE_CISO) sein, die
     * regulär in `roles` existieren — das Mapping wird per
     * `INSERT … SELECT … WHERE EXISTS` aufgelöst und überspringt fehlende
     * Rollen lautlos. So läuft die Migration auch dann durch, wenn ein
     * Tenant nur einen Teil der System-Rollen seeded hat.
     *
     * @var array<string, list<string>>
     */
    private const array MAPPING = [
        'policy_wizard.bulk_approval'         => ['ROLE_TOP_MGMT'],
        'policy_wizard.function_owner_review' => ['ROLE_FUNCTION_OWNER', 'ROLE_CISO'],
        'policy_wizard.konzern_defaults'      => ['ROLE_GROUP_CISO', 'ROLE_GROUP_BCM_OFFICER'],
        'policy_wizard.bcm_auto_create'       => ['ROLE_GROUP_BCM_OFFICER', 'ROLE_BCM_OFFICER'],
        'policy_wizard.dpo_section_veto'      => ['ROLE_DPO'],
    ];

    public function getDescription(): string
    {
        return 'Policy-Wizard W1-B: seed ROLE_GROUP_BCM_OFFICER + ROLE_FUNCTION_OWNER and five policy_wizard.* permissions with role mappings.';
    }

    public function isTransactional(): bool
    {
        // Kein DDL hier, aber die INSERT-Statements werden batch-weise
        // angefügt und sollen auch dann durchlaufen, wenn vorherige
        // Migrationen DDL-Implicit-Commits gefeuert haben.
        return false;
    }

    public function up(Schema $schema): void
    {
        $createdAt = $this->connection->quote(self::CREATED_AT);

        // 1. Roles — idempotent via ON DUPLICATE KEY UPDATE auf dem unique
        //    index (name). updated_at wird mit gepflegt, damit ein
        //    Re-Run sichtbar ist, ohne den Datensatz inhaltlich zu kippen.
        foreach (self::ROLES as $role) {
            $nameQ = $this->connection->quote($role['name']);
            $descQ = $this->connection->quote($role['description']);
            $this->addSql(<<<SQL
                INSERT INTO roles (name, description, is_system_role, created_at, updated_at)
                VALUES ({$nameQ}, {$descQ}, 1, {$createdAt}, {$createdAt})
                ON DUPLICATE KEY UPDATE
                    description = VALUES(description),
                    is_system_role = 1,
                    updated_at = VALUES(updated_at)
            SQL);
        }

        // 2. Permissions — analog idempotent.
        foreach (self::PERMISSIONS as $perm) {
            $nameQ = $this->connection->quote($perm['name']);
            $descQ = $this->connection->quote($perm['description']);
            $catQ  = $this->connection->quote($perm['category']);
            $actQ  = $this->connection->quote($perm['action']);
            $this->addSql(<<<SQL
                INSERT INTO permissions (name, description, category, action, is_system_permission, created_at)
                VALUES ({$nameQ}, {$descQ}, {$catQ}, {$actQ}, 1, {$createdAt})
                ON DUPLICATE KEY UPDATE
                    description = VALUES(description),
                    category = VALUES(category),
                    action = VALUES(action),
                    is_system_permission = 1
            SQL);
        }

        // 3. role_permissions — verknüpft Permissions mit Rollen.
        //    Pivot-Tabelle hat Composite-PK (role_id, permission_id) und
        //    keinen weiteren Unique-Key, daher INSERT IGNORE: ein
        //    bereits bestehendes Mapping wird stillschweigend
        //    übersprungen, ein neues wird eingefügt. Per JOIN auf
        //    `roles` und `permissions` — wenn die Rolle nicht existiert
        //    (z.B. weil ein Tenant ROLE_TOP_MGMT noch nicht seeded
        //    hat), liefert der SELECT keine Zeile und die Verknüpfung
        //    wird übersprungen, statt die Migration zu kippen.
        foreach (self::MAPPING as $permName => $roleNames) {
            $permNameQ = $this->connection->quote($permName);
            foreach ($roleNames as $roleName) {
                $roleNameQ = $this->connection->quote($roleName);
                $this->addSql(<<<SQL
                    INSERT IGNORE INTO role_permissions (role_id, permission_id)
                    SELECT r.id, p.id
                    FROM roles r
                    INNER JOIN permissions p ON p.name = {$permNameQ}
                    WHERE r.name = {$roleNameQ}
                SQL);
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Pivot-Einträge zuerst (FK auf permissions / roles).
        foreach (self::MAPPING as $permName => $roleNames) {
            $permNameQ = $this->connection->quote($permName);
            foreach ($roleNames as $roleName) {
                $roleNameQ = $this->connection->quote($roleName);
                $this->addSql(<<<SQL
                    DELETE rp FROM role_permissions rp
                    INNER JOIN roles r ON r.id = rp.role_id AND r.name = {$roleNameQ}
                    INNER JOIN permissions p ON p.id = rp.permission_id AND p.name = {$permNameQ}
                SQL);
            }
        }

        foreach (self::PERMISSIONS as $perm) {
            $nameQ = $this->connection->quote($perm['name']);
            $this->addSql("DELETE FROM permissions WHERE name = {$nameQ}");
        }

        foreach (self::ROLES as $role) {
            $nameQ = $this->connection->quote($role['name']);
            $this->addSql("DELETE FROM roles WHERE name = {$nameQ}");
        }
    }
}
