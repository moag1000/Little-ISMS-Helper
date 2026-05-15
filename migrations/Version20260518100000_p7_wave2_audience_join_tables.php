<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint-2 P-7 Wave-2 — Audience join tables.
 *
 * Two new Many2Many join tables introduced by FollowUp-Trigger Wave-2:
 *
 * 1. `processing_activity_processor_supplier`
 *    Owner: ProcessingActivity::$processorSuppliers.
 *    Replaces the legacy JSON `processors` blob with a structured FK
 *    into the supplier register so AVV (Auftragsverarbeitungs-Vertrag)
 *    tracking under GDPR Art. 28(1)/(3) becomes audit-ready (P0-15).
 *
 * 2. `document_acknowledgement_audience`
 *    Owner: Document::$acknowledgementAudience.
 *    Optional explicit audience for policy-acknowledgement campaigns
 *    (ISO 27001 A.6.3). When the collection is empty, the legacy
 *    `AutoReactionAcknowledgementCampaignListener` broadcast-to-all
 *    behaviour continues.
 *
 * Both tables use ON DELETE CASCADE so removing the source entity
 * cleans up the link rows automatically. No data backfill required —
 * starts empty in every tenant.
 *
 * `isTransactional() = false` because MySQL implicitly commits each
 * CREATE TABLE; without the override Doctrine's SAVEPOINT mechanism
 * trips when this migration is followed by another DDL migration in
 * the same migrate run.
 */
final class Version20260518100000_p7_wave2_audience_join_tables extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'P-7 Wave-2: audience join tables (processing_activity_processor_supplier, document_acknowledgement_audience).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS processing_activity_processor_supplier (
                processing_activity_id INT NOT NULL,
                supplier_id            INT NOT NULL,
                INDEX IDX_pa_proc_sup_pa (processing_activity_id),
                INDEX IDX_pa_proc_sup_sup (supplier_id),
                PRIMARY KEY(processing_activity_id, supplier_id),
                CONSTRAINT FK_pa_proc_sup_pa FOREIGN KEY (processing_activity_id)
                    REFERENCES processing_activity (id) ON DELETE CASCADE,
                CONSTRAINT FK_pa_proc_sup_sup FOREIGN KEY (supplier_id)
                    REFERENCES supplier (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS document_acknowledgement_audience (
                document_id INT NOT NULL,
                user_id     INT NOT NULL,
                INDEX IDX_doc_ack_aud_doc (document_id),
                INDEX IDX_doc_ack_aud_usr (user_id),
                PRIMARY KEY(document_id, user_id),
                CONSTRAINT FK_doc_ack_aud_doc FOREIGN KEY (document_id)
                    REFERENCES document (id) ON DELETE CASCADE,
                CONSTRAINT FK_doc_ack_aud_usr FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS document_acknowledgement_audience');
        $this->addSql('DROP TABLE IF EXISTS processing_activity_processor_supplier');
    }
}
