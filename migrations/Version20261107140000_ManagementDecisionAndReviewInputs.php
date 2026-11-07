<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Top-management decisions and management review inputs (ISO 27001 5.1,
 * 6.1.2 a, 6.1.3, 9.3, 10.2; NIS-2 Art. 20).
 *
 * 1. Table management_decision: one record per decision of top management.
 * 2. management_review: field for 9.3.2 c (changes in needs and expectations
 *    of interested parties). Four duplicate text fields are merged into their
 *    9.3.2 counterpart (text of both is kept, separated by a blank line):
 *    context_changes → changes_relevant_to_isms (b),
 *    nonconformities_review → non_conformities_status (d 1),
 *    improvement_opportunities → opportunities_for_improvement (g),
 *    resources_needed → resource_needs.
 * 3. management_review.action_items_with_deadlines (JSON list) become real
 *    tasks (action_items, origin management_review_action — the slug
 *    management_review is the task "carry out the review" of the planning
 *    adapter — with a reference to the review);
 *    a free-text owner stays visible in the task title.
 * 4. audit_findings.audit_id nullable: a nonconformity can come from a
 *    management review, an incident or a self-assessment (10.2).
 * 5. Management review workflow: the review step waits on ROLE_TOP_MGMT
 *    (was ROLE_ADMIN), the action step no longer on the free-text list.
 */
final class Version20261107140000_ManagementDecisionAndReviewInputs extends AbstractMigration
{
    /** @var array<string, string> duplicate column => kept 9.3.2 column */
    private const array MERGES = [
        'context_changes' => 'changes_relevant_to_isms',
        'nonconformities_review' => 'non_conformities_status',
        'improvement_opportunities' => 'opportunities_for_improvement',
        'resources_needed' => 'resource_needs',
    ];

    private const array STATUS_MAP = ['open' => 'open', 'in_progress' => 'in_progress', 'done' => 'done'];

    public function getDescription(): string
    {
        return 'Management decisions, review input 9.3.2 c, merged duplicate review fields, review actions as tasks, finding without audit';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS management_decision (
            id INT AUTO_INCREMENT NOT NULL,
            decision_type VARCHAR(40) NOT NULL,
            title VARCHAR(255) NOT NULL,
            proposal LONGTEXT DEFAULT NULL,
            subject_type VARCHAR(60) DEFAULT NULL,
            subject_id INT DEFAULT NULL,
            subject_snapshot JSON DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            lock_version INT DEFAULT 0 NOT NULL,
            requested_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            due_date DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            decided_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            decision_comment LONGTEXT DEFAULT NULL,
            valid_until DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            tenant_id INT NOT NULL,
            management_review_id INT DEFAULT NULL,
            basis_id INT DEFAULT NULL,
            requested_by_id INT DEFAULT NULL,
            decided_by_id INT DEFAULT NULL,
            INDEX IDX_B2D4738C9033212A (tenant_id),
            INDEX IDX_B2D4738CA211E8FF (management_review_id),
            INDEX IDX_B2D4738C233346FD (basis_id),
            INDEX IDX_B2D4738C4DA1E751 (requested_by_id),
            INDEX IDX_B2D4738CE26B496B (decided_by_id),
            INDEX idx_md_tenant_status (tenant_id, status),
            INDEX idx_md_tenant_type (tenant_id, decision_type),
            INDEX idx_md_subject (subject_type, subject_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE management_decision ADD CONSTRAINT FK_B2D4738C9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE management_decision ADD CONSTRAINT FK_B2D4738CA211E8FF FOREIGN KEY (management_review_id) REFERENCES management_review (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE management_decision ADD CONSTRAINT FK_B2D4738C233346FD FOREIGN KEY (basis_id) REFERENCES management_decision (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE management_decision ADD CONSTRAINT FK_B2D4738C4DA1E751 FOREIGN KEY (requested_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE management_decision ADD CONSTRAINT FK_B2D4738CE26B496B FOREIGN KEY (decided_by_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE management_review ADD interested_party_needs_changes LONGTEXT DEFAULT NULL');

        foreach (self::MERGES as $duplicate => $kept) {
            $this->addSql(sprintf(
                "UPDATE management_review SET %2\$s = CASE
                    WHEN %2\$s IS NULL OR TRIM(%2\$s) = '' THEN %1\$s
                    WHEN TRIM(%1\$s) = TRIM(%2\$s) THEN %2\$s
                    ELSE CONCAT(%2\$s, '\n\n', %1\$s)
                 END
                 WHERE %1\$s IS NOT NULL AND TRIM(%1\$s) <> ''",
                $duplicate,
                $kept,
            ));
        }

        $this->convertActionItems();

        $this->addSql('ALTER TABLE management_review DROP context_changes, DROP nonconformities_review, DROP improvement_opportunities, DROP resources_needed, DROP action_items_with_deadlines');

        $this->addSql('ALTER TABLE audit_findings MODIFY audit_id INT DEFAULT NULL');

        // The review meeting is a decision of top management (9.3); the action
        // step no longer waits on the retired free-text action list.
        $this->addSql("UPDATE workflow_steps SET approver_role = 'ROLE_TOP_MGMT' WHERE name = 'Review Execution' AND approver_role = 'ROLE_ADMIN'");
        $this->addSql("UPDATE workflow_steps SET metadata = NULL WHERE name = 'Action Planning' AND metadata LIKE '%actionItems%'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Merged review fields and converted review actions cannot be split again.');
    }

    private function convertActionItems(): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, tenant_id, review_date, action_items_with_deadlines FROM management_review WHERE action_items_with_deadlines IS NOT NULL',
        );
        foreach ($rows as $row) {
            $items = json_decode((string) $row['action_items_with_deadlines'], true);
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $action = trim((string) ($item['action'] ?? ''));
                if ($action === '') {
                    continue;
                }
                $owner = trim((string) ($item['owner'] ?? ''));
                $title = mb_substr($owner !== '' ? sprintf('%s (%s)', $action, $owner) : $action, 0, 255);
                $deadline = $this->validDate($item['deadline'] ?? null)
                    ?? $this->validDate($row['review_date'] ?? null)
                    ?? date('Y-m-d');
                $status = self::STATUS_MAP[(string) ($item['status'] ?? 'open')] ?? 'open';

                $this->addSql(
                    "INSERT INTO action_items (title, origin, scopes, due_date, status, completed_at, created_at, tenant_id)
                     VALUES (:title, 'management_review_action', '[]', :due, :status, :completed, NOW(), :tenant)",
                    [
                        'title' => $title,
                        'due' => $deadline,
                        'status' => $status,
                        'completed' => $status === 'done' ? $deadline : null,
                        'tenant' => $row['tenant_id'],
                    ],
                );
                $this->addSql(
                    "INSERT INTO action_item_references (ref_type, ref_id, action_item_id, tenant_id)
                     VALUES ('management_review_action', :review, LAST_INSERT_ID(), :tenant)",
                    ['review' => (int) $row['id'], 'tenant' => $row['tenant_id']],
                );
            }
        }
    }

    private function validDate(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

        return $date instanceof \DateTimeImmutable ? $date->format('Y-m-d') : null;
    }
}
