<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Does the instance have an active operator account (ROLE_SUPER_ADMIN)?
 *
 * Installs that marked migrations as executed (phantom repair) skip the
 * 3.15.3 operator-bootstrap data migration and can end up without one;
 * /quick-fix shows a notice then.
 *
 * Plain SQL on purpose: bypasses the ORM tenant filter (an operator is
 * usually tenantless) and keeps working while entity metadata and schema
 * drift apart — /quick-fix runs on a possibly broken schema.
 */
class InstanceOperatorCheck
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function hasActiveSuperAdmin(): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM users WHERE is_active = 1 AND roles LIKE ?',
            [InitialAdminService::ROLE_SUPER_ADMIN_LIKE],
        ) > 0;
    }
}
