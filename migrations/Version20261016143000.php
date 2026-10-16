<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lifecycle completion: optimistic-lock column for the entities that moved
 * onto a Symfony Workflow lifecycle (DocumentSection, ThreatLedPenetrationTest,
 * SupplierQuestionnaire). TransferImpactAssessment and AccessReviewCampaign
 * already carry lock_version. Existing rows start at 0.
 */
final class Version20261016143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lock_version to document_section, threat_led_penetration_test, supplier_questionnaires';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_section ADD lock_version INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE threat_led_penetration_test ADD lock_version INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE supplier_questionnaires ADD lock_version INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_section DROP lock_version');
        $this->addSql('ALTER TABLE threat_led_penetration_test DROP lock_version');
        $this->addSql('ALTER TABLE supplier_questionnaires DROP lock_version');
    }

    /** DDL commits implicitly on MySQL/MariaDB and would invalidate the savepoint. */
    public function isTransactional(): bool
    {
        return false;
    }
}
