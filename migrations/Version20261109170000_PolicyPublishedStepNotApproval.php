<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * T3 (Gate 82): the last step "published" of the seeded "Policy Approval (W3)" chain was an `auto_action` step with
 * approver role ROLE_USER. Every step that is not a notification is an approval, so every signed-in user could sign a
 * policy off. It becomes a notification step (passes by itself) that tells the managers. Data only, re-runnable.
 */
final class Version20261109170000_PolicyPublishedStepNotApproval extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Policy approval chain: step "published" is a notification, not an approval open to ROLE_USER';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE workflow_steps SET step_type = 'notification', approver_role = 'ROLE_MANAGER'"
            . " WHERE name = 'published' AND approver_role = 'ROLE_USER'"
            . " AND workflow_id IN (SELECT id FROM workflows WHERE name = 'Policy Approval (W3)')",
        );
    }

    public function down(Schema $schema): void
    {
        // The old row let every signed-in user approve; it is not restored.
    }
}
