<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * S29 (ISO 27001 7.5.3): evidence rows no longer vanish with the record they
 * point at. Their foreign keys change from ON DELETE CASCADE to RESTRICT; the
 * application refuses such a delete beforehand with a reason
 * (App\Lifecycle\DeletionPolicy). Nothing else changes: no row is touched.
 *
 * The constraint names differ between installations (early migrations named
 * them by hand), so each key is looked up by its column and re-created under
 * the name it already has.
 */
final class Version20261109210000_EvidenceForeignKeysRestrict extends AbstractMigration
{
    /** table => columns whose foreign key becomes RESTRICT */
    private const array KEYS = [
        'audit_findings' => ['audit_id'],
        'corrective_actions' => ['finding_id'],
        'consent' => ['processing_activity_id'],
        'risk_treatment_plan' => ['risk_id'],
        'risk_incident_link' => ['risk_id', 'incident_id'],
        'training_participation' => ['training_id', 'user_id'],
        'policy_acknowledgement' => ['document_id', 'user_id'],
        'document_version' => ['document_id'],
        'document_section' => ['document_id'],
        'four_eyes_approval_request' => ['requested_by_id'],
        'comments' => ['author_id'],
        'dora_data_flow' => ['supplier_id'],
        'dora_exit_plan' => ['supplier_id'],
        'dora_subcontractor' => ['parent_supplier_id'],
        'supplier_questionnaires' => ['supplier_id'],
        'access_review_item' => ['campaign_id', 'subject_user_id'],
        'tisax_license_confirmation' => ['user_id'],
        'bsi_2004_exercise_log' => ['bc_exercise_id'],
    ];

    public function getDescription(): string
    {
        return 'S29: foreign keys of evidence rows ON DELETE RESTRICT instead of CASCADE';
    }

    public function isTransactional(): bool
    {
        // ALTER TABLE commits implicitly in MySQL.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->rewrite($schema, null);
    }

    public function down(Schema $schema): void
    {
        $this->rewrite($schema, 'CASCADE');
    }

    private function rewrite(Schema $schema, ?string $onDelete): void
    {
        foreach (self::KEYS as $table => $columns) {
            if (!$schema->hasTable($table)) {
                continue; // module table never created on this installation
            }
            foreach ($schema->getTable($table)->getForeignKeys() as $key) {
                $referencing = $key->getReferencingColumnNames();
                $local = array_map(static fn ($name): string => $name->getIdentifier()->getValue(), $referencing);
                if (\count($local) !== 1 || !\in_array($local[0], $columns, true) || $key->getObjectName() === null) {
                    continue;
                }
                $current = $key->getOnDeleteAction()->value;
                $restrict = \in_array($current, ['NO ACTION', 'RESTRICT'], true);
                if (($onDelete === null && $restrict) || $current === $onDelete) {
                    continue; // already as wanted (re-run)
                }
                // toSQL() quotes for the platform (backticks on MySQL/MariaDB); toString() is ANSI.
                $name = $key->getObjectName()->toSQL($this->platform);
                $this->addSql(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $name));
                $this->addSql(sprintf(
                    'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)%s',
                    $table,
                    $name,
                    $referencing[0]->toSQL($this->platform),
                    $key->getReferencedTableName()->toSQL($this->platform),
                    implode(', ', array_map(fn ($name): string => $name->toSQL($this->platform), $key->getReferencedColumnNames())),
                    $onDelete !== null ? ' ON DELETE ' . $onDelete : '',
                ));
            }
        }
    }
}
