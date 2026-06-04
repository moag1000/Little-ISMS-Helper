<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F45 — Access Review / User-Access-Recertification (UAR)
 *
 * Creates two tables:
 *   access_review_campaign  — campaign header (name, scope, due_date, status)
 *   access_review_item      — per-user/role decision row
 *
 * ISO 27001 A.5.18 / A.8.2, NIS2 Art. 21(2)(e), BSI ORP.4.
 *
 * Pattern notes:
 *   - isTransactional()=false: DDL (CREATE TABLE) commits implicitly under
 *     MySQL — keep outside per-migration SAVEPOINT to avoid
 *     "SAVEPOINT DOCTRINE_X does not exist" on multi-migration runs.
 *   - No PREPARE/EXECUTE — plain DDL only (see pitfall #6 in CLAUDE.md).
 *   - lock_version on campaign for optimistic-locking (P-4b).
 *   - down() fully reverses (FK constraints first, then tables).
 */
final class Version20260705110000_AccessReview extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F45 UAR — create access_review_campaign and access_review_item tables';
    }

    public function isTransactional(): bool
    {
        // DDL (CREATE TABLE / ALTER TABLE) commits implicitly under MySQL.
        // Keeping this migration outside the Doctrine per-migration SAVEPOINT
        // prevents "SAVEPOINT DOCTRINE_X does not exist" errors when multiple
        // DDL migrations run in a single `doctrine:migrations:migrate` call.
        return false;
    }

    public function up(Schema $schema): void
    {
        // ── Campaign header ───────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS access_review_campaign (
                id           INT AUTO_INCREMENT NOT NULL,
                tenant_id    INT NOT NULL,
                created_by_id INT DEFAULT NULL,
                name         VARCHAR(255) NOT NULL,
                scope        VARCHAR(24)  NOT NULL DEFAULT 'all_users',
                due_date     DATE NOT NULL,
                status       VARCHAR(16)  NOT NULL DEFAULT 'open',
                lock_version INT          NOT NULL DEFAULT 0,
                created_at   DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                closed_at    DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_arc_tenant     (tenant_id),
                INDEX idx_arc_status     (status),
                INDEX idx_arc_due_date   (due_date),
                INDEX IDX_arc_created_by (created_by_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE access_review_campaign
                ADD CONSTRAINT FK_arc_tenant FOREIGN KEY (tenant_id)
                REFERENCES tenant (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE access_review_campaign
                ADD CONSTRAINT FK_arc_created_by FOREIGN KEY (created_by_id)
                REFERENCES `user` (id) ON DELETE SET NULL
        SQL);

        // ── Per-user/role decision row ────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS access_review_item (
                id              INT AUTO_INCREMENT NOT NULL,
                tenant_id       INT NOT NULL,
                campaign_id     INT NOT NULL,
                subject_user_id INT NOT NULL,
                decided_by_id   INT DEFAULT NULL,
                reviewed_role   VARCHAR(100) NOT NULL,
                decision        VARCHAR(16)  NOT NULL DEFAULT 'pending',
                comment         LONGTEXT     DEFAULT NULL,
                decided_at      DATETIME     DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at      DATETIME     NOT NULL   COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_ari_tenant       (tenant_id),
                INDEX idx_ari_campaign     (campaign_id),
                INDEX idx_ari_subject_user (subject_user_id),
                INDEX idx_ari_decision     (decision),
                INDEX IDX_ari_decided_by   (decided_by_id),
                UNIQUE INDEX uq_access_review_item_campaign_user_role (campaign_id, subject_user_id, reviewed_role),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE access_review_item
                ADD CONSTRAINT FK_ari_tenant FOREIGN KEY (tenant_id)
                REFERENCES tenant (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE access_review_item
                ADD CONSTRAINT FK_ari_campaign FOREIGN KEY (campaign_id)
                REFERENCES access_review_campaign (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE access_review_item
                ADD CONSTRAINT FK_ari_subject_user FOREIGN KEY (subject_user_id)
                REFERENCES `user` (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE access_review_item
                ADD CONSTRAINT FK_ari_decided_by FOREIGN KEY (decided_by_id)
                REFERENCES `user` (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Drop FK constraints before tables (InnoDB requires this ordering).
        $this->addSql('ALTER TABLE access_review_item DROP FOREIGN KEY FK_ari_decided_by');
        $this->addSql('ALTER TABLE access_review_item DROP FOREIGN KEY FK_ari_subject_user');
        $this->addSql('ALTER TABLE access_review_item DROP FOREIGN KEY FK_ari_campaign');
        $this->addSql('ALTER TABLE access_review_item DROP FOREIGN KEY FK_ari_tenant');
        $this->addSql('DROP TABLE IF EXISTS access_review_item');

        $this->addSql('ALTER TABLE access_review_campaign DROP FOREIGN KEY FK_arc_created_by');
        $this->addSql('ALTER TABLE access_review_campaign DROP FOREIGN KEY FK_arc_tenant');
        $this->addSql('DROP TABLE IF EXISTS access_review_campaign');
    }
}
