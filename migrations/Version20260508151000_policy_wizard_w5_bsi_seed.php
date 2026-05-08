<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Command\SeedBsiPolicyTemplatesCommand;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard Sprint W5-A — BSI PolicyTemplate seed.
 *
 * Seeds the 28 BSI Pflicht-Richtlinien from Anhang A of
 * `docs/plans/policy-wizard/02-bsi-input.md` plus the Schutzbedarfs-
 * feststellungs-Methodik (§4.1) = 29 PolicyTemplate rows.
 *
 * Idempotent: every INSERT uses ON DUPLICATE KEY UPDATE on the
 * `policy_template.key_name` unique index. Re-running on a database
 * that already has the rows updates the description / linked-anchor
 * snapshot in place. The bodies / titles point at translation keys
 * (`policy.bsi.<topic>.v1.body`) — real content is authored in W5-E.
 *
 * Per CLAUDE.md pitfall #6: plain SQL only, no PREPARE/EXECUTE
 * patterns; `isTransactional()=false` because INSERTs into
 * `policy_template` after the column-add migration (Version20260508150000)
 * need their own implicit-commit boundary.
 *
 * Source data lives in {@see SeedBsiPolicyTemplatesCommand::TEMPLATES}
 * to keep the catalogue single-sourced — the migration just mirrors
 * the constant so cold installs (where the command isn't run) still
 * end up with the seed.
 */
final class Version20260508151000_policy_wizard_w5_bsi_seed extends AbstractMigration
{
    private const string CREATED_AT = '2026-05-08 15:10:00';

    public function isTransactional(): bool
    {
        // No DDL but the W5 column-add migration just committed; we keep
        // the per-INSERT implicit boundary by disabling Doctrine's outer
        // transaction (consistent with the W4 seed pattern).
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard W5-A: seed 28 BSI Pflicht-Richtlinien + 1 '
            . 'Schutzbedarfsfeststellungs-Methodik = 29 PolicyTemplate rows. '
            . 'Source: BSI IT-Grundschutz-Kompendium Edition 2023, BSI 200-1/200-2/200-4.';
    }

    public function up(Schema $schema): void
    {
        $createdAt = $this->connection->quote(self::CREATED_AT);
        $standardQ = $this->connection->quote(SeedBsiPolicyTemplatesCommand::STANDARD);

        foreach (SeedBsiPolicyTemplatesCommand::TEMPLATES as $row) {
            $keyQ = $this->connection->quote($row['key']);
            $topicQ = $this->connection->quote($row['topic']);
            $docTypeQ = $this->connection->quote($row['document_type']);
            $normQ = $this->connection->quote($row['norm_ref']);
            $titleQ = $this->connection->quote($row['title_translation_key']);
            $bodyQ = $this->connection->quote($row['body_translation_key']);
            $tierQ = $this->connection->quote($row['bsi_tier']);

            $linkedBsiBausteineQ = $this->connection->quote(json_encode(
                $row['linked_bsi_bausteine'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));

            $bausteineRoots = $this->bausteineRoots($row['linked_bsi_bausteine']);
            $linkedBausteineQ = $bausteineRoots === null
                ? 'NULL'
                : $this->connection->quote(json_encode(
                    $bausteineRoots,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ));

            $linkedAnnexQ = $row['linked_annex_a_controls'] === []
                ? 'NULL'
                : $this->connection->quote(json_encode(
                    $row['linked_annex_a_controls'],
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
                    bsi_tier, is_active, version, created_at
                ) VALUES (
                    {$keyQ}, {$standardQ}, {$topicQ}, {$docTypeQ}, {$normQ},
                    {$titleQ}, {$bodyQ},
                    {$linkedAnnexQ}, {$linkedBausteineQ}, {$linkedBsiBausteineQ},
                    NULL, NULL,
                    {$reviewMonths}, {$approvalQ},
                    0, {$dpo},
                    {$tierQ}, 1, 1, {$createdAt}
                )
                ON DUPLICATE KEY UPDATE
                    standard = VALUES(standard),
                    topic = VALUES(topic),
                    document_type = VALUES(document_type),
                    norm_ref = VALUES(norm_ref),
                    title_translation_key = VALUES(title_translation_key),
                    body_translation_key = VALUES(body_translation_key),
                    linked_annex_a_controls = VALUES(linked_annex_a_controls),
                    linked_bausteine = VALUES(linked_bausteine),
                    linked_bsi_bausteine = VALUES(linked_bsi_bausteine),
                    review_interval_months = VALUES(review_interval_months),
                    approval_chain = VALUES(approval_chain),
                    climate_change_wording = VALUES(climate_change_wording),
                    dpo_section_required = VALUES(dpo_section_required),
                    bsi_tier = VALUES(bsi_tier),
                    is_active = VALUES(is_active),
                    updated_at = {$createdAt}
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (SeedBsiPolicyTemplatesCommand::TEMPLATES as $row) {
            $keyQ = $this->connection->quote($row['key']);
            $this->addSql(<<<SQL
                DELETE FROM policy_template WHERE key_name = {$keyQ}
            SQL);
        }
    }

    /**
     * Mirror of the SeedBsiPolicyTemplatesCommand helper; kept inline to
     * keep the migration usable even when the App namespace isn't loaded
     * (some recovery paths run migrations against a stripped autoloader).
     *
     * @param list<string> $anchors
     * @return list<string>|null
     */
    private function bausteineRoots(array $anchors): ?array
    {
        $seen = [];
        foreach ($anchors as $anchor) {
            $parts = explode('.', $anchor);
            $count = count($parts);
            if ($count < 2) {
                continue;
            }
            if ($count >= 2 && preg_match('/^A\d+$/', $parts[$count - 1]) === 1) {
                array_pop($parts);
            }
            $root = implode('.', $parts);
            if ($root === '' || isset($seen[$root])) {
                continue;
            }
            $seen[$root] = true;
        }
        $result = array_keys($seen);
        return $result === [] ? null : $result;
    }
}
