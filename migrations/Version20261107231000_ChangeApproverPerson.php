<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P5:
 *  - change_request.approver_person_id: the approver is a person (ISO 27001 A.8.32) and
 *    holds the approval duty in My Day. An old free-text approver that matches exactly one
 *    person of the same organisation by name is taken over; the text stays as it was.
 *  - document_version.retention_until was never written or read anywhere — dropped.
 */
final class Version20261107231000_ChangeApproverPerson extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change request approver as person; drop unused document_version.retention_until';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL (invalidates the SAVEPOINT).
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE change_request ADD approver_person_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE change_request ADD CONSTRAINT FK_CB902D362300BD2B FOREIGN KEY (approver_person_id) REFERENCES person (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_CB902D362300BD2B ON change_request (approver_person_id)');
        $this->addSql(
            'UPDATE change_request cr
             JOIN (
                 SELECT c.id AS change_id, MIN(p.id) AS person_id
                 FROM change_request c
                 JOIN person p ON p.tenant_id = c.tenant_id AND LOWER(TRIM(p.full_name)) = LOWER(TRIM(c.approved_by))
                 WHERE c.approved_by IS NOT NULL AND TRIM(c.approved_by) <> \'\'
                 GROUP BY c.id
                 HAVING COUNT(p.id) = 1
             ) m ON m.change_id = cr.id
             SET cr.approver_person_id = m.person_id'
        );

        $this->addSql('ALTER TABLE document_version DROP COLUMN retention_until');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_version ADD retention_until DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE change_request DROP FOREIGN KEY FK_CB902D362300BD2B');
        $this->addSql('DROP INDEX IDX_CB902D362300BD2B ON change_request');
        $this->addSql('ALTER TABLE change_request DROP COLUMN approver_person_id');
    }
}
