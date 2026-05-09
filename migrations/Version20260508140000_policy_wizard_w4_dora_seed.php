<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard Sprint W4-A — DORA PolicyTemplate seed.
 *
 * Seeds the 6 NEW DORA policy templates per DORA §10 cross-mapping
 * (see `docs/plans/policy-wizard/03-dora-input.md` §2 + §10):
 *
 *   1. dora.ict_risk_management_framework — Art. 6 framework policy
 *   2. dora.ict_risk_tolerance            — Art. 6.8 tolerance statement
 *   3. dora.detection_anomalous_activities— Art. 10
 *   4. dora.response_recovery             — Art. 11
 *   5. dora.learning_evolving             — Art. 13
 *   6. dora.communication_ict_incidents   — Art. 14
 *
 * Idempotent: every INSERT uses ON DUPLICATE KEY UPDATE on the
 * `policy_template.key_name` unique index. Re-running on a database
 * that already has the rows updates the description / linked-articles
 * snapshot in place. The bodies / titles point at translation keys
 * (`policy.dora.<topic>.v1.body`) — real content is authored in W4-E.
 *
 * Per CLAUDE.md pitfall #6: plain SQL only, no PREPARE/EXECUTE
 * patterns; `isTransactional()=false` because INSERTs into
 * `policy_template` after earlier DDL migrations need their own
 * implicit-commit boundary.
 */
final class Version20260508140000_policy_wizard_w4_dora_seed extends AbstractMigration
{
    private const string CREATED_AT = '2026-05-08 14:00:00';

    private const string DORA_VALIDITY_FROM = '2025-01-17';

    /**
     * @var list<array{
     *     key_name: string,
     *     topic: string,
     *     document_type: string,
     *     norm_ref: string,
     *     title_translation_key: string,
     *     body_translation_key: string,
     *     linked_dora_articles: list<string>,
     *     affected_functions: list<string>,
     *     review_interval_months: int,
     *     approval_chain: list<string>,
     *     dpo_section_required: int,
     * }>
     */
    private const array TEMPLATES = [
        [
            'key_name' => 'dora.ict_risk_management_framework',
            'topic' => 'ict_risk_management_framework',
            'document_type' => 'policy',
            'norm_ref' => 'Art. 6',
            'title_translation_key' => 'policy.dora.ict_risk_management_framework.v1.title',
            'body_translation_key' => 'policy.dora.ict_risk_management_framework.v1.body',
            'linked_dora_articles' => ['Art. 6', 'Art. 6.8'],
            'affected_functions' => ['IT_OPERATIONS', 'RISK_MANAGEMENT', 'TOP_MGMT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'dora.ict_risk_tolerance',
            'topic' => 'ict_risk_tolerance',
            'document_type' => 'policy',
            'norm_ref' => 'Art. 6.8',
            'title_translation_key' => 'policy.dora.ict_risk_tolerance.v1.title',
            'body_translation_key' => 'policy.dora.ict_risk_tolerance.v1.body',
            'linked_dora_articles' => ['Art. 6.8'],
            'affected_functions' => ['RISK_MANAGEMENT', 'TOP_MGMT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'dora.detection_anomalous_activities',
            'topic' => 'detection_anomalous_activities',
            'document_type' => 'policy',
            'norm_ref' => 'Art. 10',
            'title_translation_key' => 'policy.dora.detection_anomalous_activities.v1.title',
            'body_translation_key' => 'policy.dora.detection_anomalous_activities.v1.body',
            'linked_dora_articles' => ['Art. 10'],
            'affected_functions' => ['IT_OPERATIONS', 'SOC'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'dora.response_recovery',
            'topic' => 'response_recovery',
            'document_type' => 'policy',
            'norm_ref' => 'Art. 11',
            'title_translation_key' => 'policy.dora.response_recovery.v1.title',
            'body_translation_key' => 'policy.dora.response_recovery.v1.body',
            'linked_dora_articles' => ['Art. 11'],
            'affected_functions' => ['IT_OPERATIONS', 'BCM', 'CRISIS_TEAM'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'dora.learning_evolving',
            'topic' => 'learning_evolving',
            'document_type' => 'policy',
            'norm_ref' => 'Art. 13',
            'title_translation_key' => 'policy.dora.learning_evolving.v1.title',
            'body_translation_key' => 'policy.dora.learning_evolving.v1.body',
            'linked_dora_articles' => ['Art. 13'],
            'affected_functions' => ['IT_OPERATIONS', 'HR', 'TOP_MGMT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_HR'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'dora.communication_ict_incidents',
            'topic' => 'communication_ict_incidents',
            'document_type' => 'policy',
            'norm_ref' => 'Art. 14',
            'title_translation_key' => 'policy.dora.communication_ict_incidents.v1.title',
            'body_translation_key' => 'policy.dora.communication_ict_incidents.v1.body',
            'linked_dora_articles' => ['Art. 14'],
            'affected_functions' => ['COMMUNICATIONS', 'CRISIS_TEAM', 'TOP_MGMT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => 0,
        ],
    ];

    public function isTransactional(): bool
    {
        // No DDL but earlier DDL migrations may have just committed; we
        // keep the per-INSERT implicit boundary by disabling Doctrine's
        // outer transaction (consistent with the rest of the W1 series).
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard W4-A: seed 6 NEW DORA PolicyTemplate rows '
            . '(Art. 6 / 6.8 / 10 / 11 / 13 / 14). Validity_from='
            . self::DORA_VALIDITY_FROM . '.';
    }

    public function up(Schema $schema): void
    {
        $createdAt = $this->connection->quote(self::CREATED_AT);

        foreach (self::TEMPLATES as $row) {
            $keyQ = $this->connection->quote($row['key_name']);
            $standardQ = $this->connection->quote('dora');
            $topicQ = $this->connection->quote($row['topic']);
            $docTypeQ = $this->connection->quote($row['document_type']);
            $normQ = $this->connection->quote($row['norm_ref']);
            $titleQ = $this->connection->quote($row['title_translation_key']);
            $bodyQ = $this->connection->quote($row['body_translation_key']);
            $linkedDoraQ = $this->connection->quote(json_encode(
                $row['linked_dora_articles'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
            $affectedQ = $this->connection->quote(json_encode(
                $row['affected_functions'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
            $approvalQ = $this->connection->quote(json_encode(
                $row['approval_chain'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ));
            $reviewMonths = (int) $row['review_interval_months'];
            $dpo = (int) $row['dpo_section_required'];

            $this->addSql(<<<SQL
                INSERT INTO policy_template (
                    key_name, standard, topic, document_type, norm_ref,
                    title_translation_key, body_translation_key,
                    linked_annex_a_controls, linked_bausteine, linked_dora_articles,
                    affected_functions, review_interval_months, approval_chain,
                    climate_change_wording, dpo_section_required,
                    is_active, version, created_at
                ) VALUES (
                    {$keyQ}, {$standardQ}, {$topicQ}, {$docTypeQ}, {$normQ},
                    {$titleQ}, {$bodyQ},
                    NULL, NULL, {$linkedDoraQ},
                    {$affectedQ}, {$reviewMonths}, {$approvalQ},
                    0, {$dpo},
                    1, 1, {$createdAt}
                )
                ON DUPLICATE KEY UPDATE
                    standard = VALUES(standard),
                    topic = VALUES(topic),
                    document_type = VALUES(document_type),
                    norm_ref = VALUES(norm_ref),
                    title_translation_key = VALUES(title_translation_key),
                    body_translation_key = VALUES(body_translation_key),
                    linked_dora_articles = VALUES(linked_dora_articles),
                    affected_functions = VALUES(affected_functions),
                    review_interval_months = VALUES(review_interval_months),
                    approval_chain = VALUES(approval_chain),
                    climate_change_wording = VALUES(climate_change_wording),
                    dpo_section_required = VALUES(dpo_section_required),
                    is_active = VALUES(is_active),
                    updated_at = {$createdAt}
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TEMPLATES as $row) {
            $keyQ = $this->connection->quote($row['key_name']);
            $this->addSql(<<<SQL
                DELETE FROM policy_template WHERE key_name = {$keyQ}
            SQL);
        }
    }
}
