<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260424212328 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FairyAurora v4.0: Add alva_companion_enabled/size/position to users table (Phase 4.2)';
    }

    public function up(Schema $schema): void
    {
        // Auto-diff had picked up FK-constraints on compliance_requirement /
        // document / four_eyes_approval_request / risk that were already
        // created by the squash migration (Version20260424150000). Re-applying
        // them causes errno 121 "Duplicate key" on CI's fresh DB. Only the
        // Alva user-settings columns are genuinely new in Phase 4.2.
        $this->addSql("ALTER TABLE users ADD alva_companion_enabled TINYINT DEFAULT 1 NOT NULL, ADD alva_companion_size VARCHAR(8) DEFAULT 'md' NOT NULL, ADD alva_companion_position VARCHAR(20) DEFAULT 'bottom-right' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP alva_companion_enabled, DROP alva_companion_size, DROP alva_companion_position');
    }
}
