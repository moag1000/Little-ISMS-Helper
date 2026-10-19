<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Security\RoleNames;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * 3.15.3 security release — report only, changes no data.
 *
 * Since the hotfix a non-system custom Role whose name equals a reserved
 * Symfony role ({@see RoleNames::RESERVED}, e.g. "ROLE_ADMIN") no longer
 * grants that role to its holders. Such roles are listed here so the operator
 * can re-create them as system roles (ROLE_SUPER_ADMIN only). They are
 * deliberately NOT upgraded automatically — that would re-open the
 * privilege escalation the hotfix closes.
 */
final class Version20261019120100_reserved_custom_role_names extends AbstractMigration
{
    public function getDescription(): string
    {
        return '3.15.3 security: warn about non-system custom roles named like reserved Symfony roles (no data change)';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.id, r.name, COUNT(ur.user_id) AS holders FROM roles r'
            . ' LEFT JOIN user_roles ur ON ur.role_id = r.id'
            . ' WHERE r.is_system_role = ? GROUP BY r.id, r.name ORDER BY r.id ASC',
            [0],
        );
        $reserved = array_values(array_filter(
            $rows,
            static fn (array $row): bool => RoleNames::isReserved((string) $row['name']),
        ));

        if ($reserved === []) {
            $this->write('[security] No non-system custom roles with reserved names found.');

            return;
        }

        $this->write(sprintf(
            '[security] WARNING: %d custom role(s) are named like reserved Symfony roles and no longer grant them'
            . ' (re-create as system role via ROLE_SUPER_ADMIN if still needed):',
            count($reserved),
        ));
        foreach ($reserved as $row) {
            $this->write(sprintf(
                '[security]   - role #%d "%s" (%d holder(s))',
                (int) $row['id'],
                (string) $row['name'],
                (int) $row['holders'],
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->write('Version20261019120100_reserved_custom_role_names: report only, nothing to revert.');
    }
}
