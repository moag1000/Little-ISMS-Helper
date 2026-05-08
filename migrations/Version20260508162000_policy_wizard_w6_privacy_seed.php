<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Command\SeedPrivacyPolicyTemplatesCommand;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard Sprint W6-B — Privacy / DPO PolicyTemplate seed.
 *
 * Seeds the 5 standalone Privacy/DPO documents plus the thin A.5.34
 * cross-reference host = 6 PolicyTemplate rows from
 * `docs/plans/policy-wizard/06-dpo-input.md` Decision Matrix v2.
 *
 * Each row carries:
 *   • `standard='gdpr'`
 *   • `linked_annex_a_controls=['A.5.34']` — every privacy doc cross-
 *     references the ISO 27001 A.5.34 host (incl. the host itself).
 *   • `iso27701_clauses_2025` + `iso27701_clauses_2019` — PIMS mapping
 *     per §3.1; both versions stored so `iso27701.version` setting
 *     can pick the right clause set without re-mapping at runtime.
 *   • `dpo_section_required=1` for the 5 standalone docs;
 *     `dpo_section_required=0` for the thin A.5.34 host.
 *   • `affected_functions=["dpo"]` — surfaces docs in the DPO inbox.
 *
 * Idempotent: every INSERT uses ON DUPLICATE KEY UPDATE on the
 * `policy_template.key_name` unique index. Re-running on a database
 * that already has the rows updates the description / clause-mapping
 * snapshot in place. The bodies / titles point at translation keys
 * (`policy.gdpr.<topic>.v1.body`) — real content authored in W6-E.
 *
 * Per CLAUDE.md pitfall #6: plain SQL only, no PREPARE/EXECUTE
 * patterns; `isTransactional()=false` because INSERTs into
 * `policy_template` after the column-add migration
 * (Version20260508161000) need their own implicit-commit boundary.
 *
 * Source data lives in {@see SeedPrivacyPolicyTemplatesCommand::TEMPLATES}
 * to keep the catalogue single-sourced.
 */
final class Version20260508162000_policy_wizard_w6_privacy_seed extends AbstractMigration
{
    private const string CREATED_AT = '2026-05-08 16:20:00';

    public function isTransactional(): bool
    {
        // Outer transaction would invalidate the implicit-commit
        // boundary the W6-B column-add migration relies on. Match the
        // W4 / W5 seed pattern.
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard W6-B: seed 5 standalone Privacy/DPO templates + 1 thin '
            . 'A.5.34 cross-reference host = 6 PolicyTemplate rows. Source: '
            . 'docs/plans/policy-wizard/06-dpo-input.md §0 Decision Matrix v2.';
    }

    public function up(Schema $schema): void
    {
        $createdAt = $this->connection->quote(self::CREATED_AT);
        $standardQ = $this->connection->quote(SeedPrivacyPolicyTemplatesCommand::STANDARD);

        // Every privacy template links A.5.34 (incl. the thin host —
        // the host's `topic` IS A.5.34).
        $linkedAnnexQ = $this->connection->quote(json_encode(
            ['A.5.34'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
        // Surfaces every privacy doc to the DPO inbox via the W3
        // function-owner-review workflow gate.
        $affectedFunctionsQ = $this->connection->quote(json_encode(
            ['dpo'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));

        foreach (SeedPrivacyPolicyTemplatesCommand::TEMPLATES as $row) {
            $keyQ = $this->connection->quote($row['key']);
            $topicQ = $this->connection->quote($row['topic']);
            $docTypeQ = $this->connection->quote($row['document_type']);
            $normQ = $this->connection->quote($row['norm_ref']);
            $titleQ = $this->connection->quote($row['title_translation_key']);
            $bodyQ = $this->connection->quote($row['body_translation_key']);

            $clauses2025Q = $row['iso27701_clauses_2025'] === []
                ? 'NULL'
                : $this->connection->quote(json_encode(
                    $row['iso27701_clauses_2025'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ));
            $clauses2019Q = $row['iso27701_clauses_2019'] === []
                ? 'NULL'
                : $this->connection->quote(json_encode(
                    $row['iso27701_clauses_2019'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ));

            $approvalQ = $this->connection->quote(json_encode(
                $row['approval_chain'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
            $reviewMonths = (int) $row['review_interval_months'];
            $dpo = $row['dpo_section_required'] ? 1 : 0;

            $this->addSql(<<<SQL
                INSERT INTO policy_template (
                    key_name, standard, topic, document_type, norm_ref,
                    title_translation_key, body_translation_key,
                    linked_annex_a_controls, linked_bausteine, linked_bsi_bausteine,
                    linked_dora_articles, affected_functions,
                    review_interval_months, approval_chain,
                    climate_change_wording, dpo_section_required,
                    bsi_tier, iso27701_clauses_2025, iso27701_clauses_2019,
                    is_active, version, created_at
                ) VALUES (
                    {$keyQ}, {$standardQ}, {$topicQ}, {$docTypeQ}, {$normQ},
                    {$titleQ}, {$bodyQ},
                    {$linkedAnnexQ}, NULL, NULL,
                    NULL, {$affectedFunctionsQ},
                    {$reviewMonths}, {$approvalQ},
                    0, {$dpo},
                    NULL, {$clauses2025Q}, {$clauses2019Q},
                    1, 1, {$createdAt}
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
                    iso27701_clauses_2025 = VALUES(iso27701_clauses_2025),
                    iso27701_clauses_2019 = VALUES(iso27701_clauses_2019),
                    is_active = VALUES(is_active),
                    updated_at = {$createdAt}
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (SeedPrivacyPolicyTemplatesCommand::TEMPLATES as $row) {
            $keyQ = $this->connection->quote($row['key']);
            $this->addSql(<<<SQL
                DELETE FROM policy_template WHERE key_name = {$keyQ}
            SQL);
        }
    }
}
