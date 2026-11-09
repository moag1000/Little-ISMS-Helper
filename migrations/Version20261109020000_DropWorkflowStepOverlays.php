<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S15: the step overrides of /admin/workflows (approver role, approvers, days,
 * auto-progress conditions, reason, four eyes, module per organisation) were stored in
 * lifecycle_config but never applied — a running approval reads its approver and deadline
 * from the WorkflowStep row the RegulatoryWorkflowMirror writes from the YAML. The page is
 * read-only now; the rows without effect go. Transition overrides (workflow names ending in
 * `_lifecycle`) stay.
 */
final class Version20261109020000_DropWorkflowStepOverlays extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the never-applied workflow step overlays from lifecycle_config (S15)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM lifecycle_config WHERE workflow_name LIKE 'workflow:%' AND transition_name LIKE 'step:%'");
    }

    public function down(Schema $schema): void
    {
        // The removed rows had no effect; nothing to restore.
    }
}
