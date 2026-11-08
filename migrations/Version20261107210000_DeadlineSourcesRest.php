<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2 Fristen-Rest (data only):
 *  - NIS-2 registration profiles that were never reported carried a first due
 *    date one year after creation. An in-scope entity registers at once
 *    (BSIG § 33): such profiles become due now, or — when the organisation
 *    recorded its registration date — one year after that date, which then
 *    counts as the last report.
 *  - The time-driven rule event "control.overdue" is gone; overdue control
 *    reviews reach the owner through My Day and the reminder mails. Rules
 *    still listening to it are switched off (they would never fire again).
 */
final class Version20261107210000_DeadlineSourcesRest extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'NIS-2 registration due at once; switch off control.overdue notification rules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE nis2_registration_profile p INNER JOIN tenant t ON t.id = p.tenant_id'
            . ' SET p.last_reported_at = t.nis2_registered_at, p.next_due_at = DATE_ADD(t.nis2_registered_at, INTERVAL 1 YEAR)'
            . ' WHERE p.last_reported_at IS NULL AND t.nis2_registered_at IS NOT NULL'
        );
        $this->addSql(
            'UPDATE nis2_registration_profile SET next_due_at = created_at'
            . ' WHERE last_reported_at IS NULL AND next_due_at > created_at'
        );
        $this->addSql("UPDATE notification_rule SET is_active = 0 WHERE event_type = 'control.overdue'");
    }

    public function down(Schema $schema): void
    {
        // Data correction; the old one-year default is not restored.
    }
}
