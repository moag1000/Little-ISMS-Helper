<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Command\SeedIsoPolicyTemplatesCommand;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard ISO 27001 PolicyTemplate seed.
 *
 * Seeds 25 PolicyTemplate rows for `standard='iso27001'`: 1 cross-cutting
 * top-level policy (ISO 27001 Cl. 5.2) plus 24 ISO 27002:2022 themen-
 * spezifische Richtlinien from
 * {@see \App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardTopicCatalogue::ISO27001_TOPICS}.
 *
 * E2E-Audit (commit e5e768ac) flagged: DB had `bsi=29 / bcm=14 / dora=6 /
 * gdpr=6 / iso27001=0` rows. WelcomeStandardsStep requires `iso27001` in
 * scope, but `DocumentGenerator::collectTemplatesFor('iso27001')` returned
 * an empty list, leaving wizard-runs with 0 ISO documents generated even
 * after a successful run. This seed closes that gap.
 *
 * Idempotent: every INSERT uses `ON DUPLICATE KEY UPDATE` on the
 * `policy_template.key_name` unique index. Re-running on a database that
 * already has the rows updates the metadata snapshot in place. Bodies /
 * titles point at translation keys living in the existing
 * `translations/policy_iso27001*.{de,en}.yaml` files (no new translations
 * authored by this migration).
 *
 * Per CLAUDE.md pitfall #6: plain SQL only, no PREPARE/EXECUTE pattern;
 * `isTransactional()=false` because INSERTs into `policy_template` after
 * the schema-add migrations need their own implicit-commit boundary so a
 * subsequent migration in the same `migrate` run does not lose its
 * SAVEPOINT.
 *
 * Source data lives in {@see SeedIsoPolicyTemplatesCommand::TEMPLATES} to
 * keep the catalogue single-sourced — the migration just mirrors the
 * constant so cold installs (where the command is never run) still end
 * up with the seed.
 */
final class Version20260510000000_policy_wizard_iso27001_seed extends AbstractMigration
{
    private const string CREATED_AT = '2026-05-10 00:00:00';

    public function isTransactional(): bool
    {
        // INSERTs only, but consistent with the W4 / W5 seed pattern: per-
        // INSERT implicit boundaries by disabling Doctrine's outer
        // transaction so DDL-emitting neighbours don't break.
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard ISO 27001 seed: 1 top-level (Cl. 5.2) + 24 themen-'
            . 'spezifische Richtlinien (ISO 27002:2022) = 25 PolicyTemplate rows. '
            . 'Source: docs/plans/policy-wizard/01-iso27001-input.md.';
    }

    public function up(Schema $schema): void
    {
        $createdAt = $this->connection->quote(self::CREATED_AT);
        $standardQ = $this->connection->quote(SeedIsoPolicyTemplatesCommand::STANDARD);

        foreach (SeedIsoPolicyTemplatesCommand::TEMPLATES as $row) {
            $keyQ = $this->connection->quote($row['key']);
            $topicQ = $this->connection->quote($row['topic']);
            $docTypeQ = $this->connection->quote($row['document_type']);
            $normQ = $this->connection->quote($row['norm_ref']);
            $titleQ = $this->connection->quote(
                SeedIsoPolicyTemplatesCommand::titleTranslationKey($row['translation_topic']),
            );
            $bodyQ = $this->connection->quote(
                SeedIsoPolicyTemplatesCommand::bodyTranslationKey($row['translation_topic']),
            );

            $linkedAnnexQ = $row['linked_annex_a_controls'] === []
                ? 'NULL'
                : $this->connection->quote(json_encode(
                    $row['linked_annex_a_controls'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ));

            $affectedQ = $row['affected_functions'] === []
                ? 'NULL'
                : $this->connection->quote(json_encode(
                    $row['affected_functions'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ));

            $approvalQ = $this->connection->quote(json_encode(
                $row['approval_chain'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
            $reviewMonths = (int) $row['review_interval_months'];
            $dpo = $row['dpo_section_required'] ? 1 : 0;
            $br = $row['requires_works_council_evidence'] ? 1 : 0;
            $climate = $row['climate_change_wording'] ? 1 : 0;

            $this->addSql(<<<SQL
                INSERT INTO policy_template (
                    key_name, standard, topic, document_type, norm_ref,
                    title_translation_key, body_translation_key,
                    linked_annex_a_controls, linked_bausteine, linked_bsi_bausteine,
                    linked_dora_articles, affected_functions,
                    review_interval_months, approval_chain,
                    climate_change_wording, dpo_section_required,
                    requires_works_council_evidence,
                    bsi_tier, is_active, version, created_at
                ) VALUES (
                    {$keyQ}, {$standardQ}, {$topicQ}, {$docTypeQ}, {$normQ},
                    {$titleQ}, {$bodyQ},
                    {$linkedAnnexQ}, NULL, NULL,
                    NULL, {$affectedQ},
                    {$reviewMonths}, {$approvalQ},
                    {$climate}, {$dpo},
                    {$br},
                    NULL, 1, 1, {$createdAt}
                )
                ON DUPLICATE KEY UPDATE
                    standard = VALUES(standard),
                    topic = VALUES(topic),
                    document_type = VALUES(document_type),
                    norm_ref = VALUES(norm_ref),
                    title_translation_key = VALUES(title_translation_key),
                    body_translation_key = VALUES(body_translation_key),
                    linked_annex_a_controls = VALUES(linked_annex_a_controls),
                    affected_functions = VALUES(affected_functions),
                    review_interval_months = VALUES(review_interval_months),
                    approval_chain = VALUES(approval_chain),
                    climate_change_wording = VALUES(climate_change_wording),
                    dpo_section_required = VALUES(dpo_section_required),
                    requires_works_council_evidence = VALUES(requires_works_council_evidence),
                    is_active = VALUES(is_active),
                    updated_at = {$createdAt}
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (SeedIsoPolicyTemplatesCommand::TEMPLATES as $row) {
            $keyQ = $this->connection->quote($row['key']);
            $this->addSql(<<<SQL
                DELETE FROM policy_template WHERE key_name = {$keyQ}
            SQL);
        }
    }
}
