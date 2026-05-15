<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F27 — BSI-Standard 200-4 Übungs-Logbuch
 *
 * Creates the `bsi_2004_exercise_log` table linked 1:1 to `bc_exercise`.
 * Adds back-reference nullable column to `bc_exercise` table via the
 * OneToOne (mapped on the log side — no column added to bc_exercise itself).
 */
final class Version20260517160000_f27_bsi_2004_exercise_log extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'F27: Create bsi_2004_exercise_log table (BSI-200-4 Übungs-Logbuch)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS bsi_2004_exercise_log (
                id                      INT AUTO_INCREMENT NOT NULL,
                tenant_id               INT NOT NULL,
                bc_exercise_id          INT NOT NULL,
                exercise_type           VARCHAR(50) NOT NULL DEFAULT 'tabletop',
                bsi2004_template        VARCHAR(30) NOT NULL DEFAULT 'standard',
                participants            JSON NOT NULL,
                scenario_summary        LONGTEXT NOT NULL,
                objectives              JSON NOT NULL,
                actions_before          LONGTEXT DEFAULT NULL,
                actions_during          LONGTEXT DEFAULT NULL,
                actions_after           LONGTEXT DEFAULT NULL,
                lessons_learned         LONGTEXT DEFAULT NULL,
                improvement_actions     JSON DEFAULT NULL,
                overall_rating          VARCHAR(20) DEFAULT NULL,
                submitted_by_id         INT DEFAULT NULL,
                submitted_at            DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                confirmed_by_auditor_id INT DEFAULT NULL,
                confirmed_at            DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at              DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at              DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                UNIQUE INDEX UNIQ_BSI_LOG_EXERCISE (bc_exercise_id),
                INDEX IDX_BSI_LOG_TENANT (tenant_id),
                INDEX IDX_BSI_LOG_SUBMITTED_AT (submitted_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE bsi_2004_exercise_log
                ADD CONSTRAINT FK_BSI_LOG_TENANT
                    FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE,
                ADD CONSTRAINT FK_BSI_LOG_EXERCISE
                    FOREIGN KEY (bc_exercise_id) REFERENCES bc_exercise (id) ON DELETE CASCADE,
                ADD CONSTRAINT FK_BSI_LOG_SUBMITTED_BY
                    FOREIGN KEY (submitted_by_id) REFERENCES users (id) ON DELETE SET NULL,
                ADD CONSTRAINT FK_BSI_LOG_CONFIRMED_BY
                    FOREIGN KEY (confirmed_by_auditor_id) REFERENCES users (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bsi_2004_exercise_log DROP FOREIGN KEY FK_BSI_LOG_TENANT');
        $this->addSql('ALTER TABLE bsi_2004_exercise_log DROP FOREIGN KEY FK_BSI_LOG_EXERCISE');
        $this->addSql('ALTER TABLE bsi_2004_exercise_log DROP FOREIGN KEY FK_BSI_LOG_SUBMITTED_BY');
        $this->addSql('ALTER TABLE bsi_2004_exercise_log DROP FOREIGN KEY FK_BSI_LOG_CONFIRMED_BY');
        $this->addSql('DROP TABLE IF EXISTS bsi_2004_exercise_log');
    }
}
