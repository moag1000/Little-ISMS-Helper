<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Policy-Wizard Sprint W5-B — BCM PolicyTemplate seed.
 *
 * Seeds the 14 BCM policy templates per BCM specialist input
 * (`docs/plans/policy-wizard/04-bcm-input.md` §1 + §2.1-§2.13):
 *
 *  1. bcm.bcms_top_level                       — ISO 22301 Cl. 5.2
 *  2. bcm.bcms_scope_statement                 — ISO 22301 Cl. 4.3
 *  3. bcm.bia_methodology                      — ISO 22301 Cl. 8.2.2
 *  4. bcm.risk_assessment_methodology_bcm      — ISO 22301 Cl. 8.2.3
 *  5. bcm.bc_strategy                          — ISO 22301 Cl. 8.3
 *  6. bcm.bc_plans                             — ISO 22301 Cl. 8.4.4
 *  7. bcm.incident_response_communication      — ISO 22301 Cl. 8.4.3
 *  8. bcm.crisis_management_plan               — ISO 22301 Cl. 8.4.4
 *  9. bcm.recovery_plans                       — ISO 22301 Cl. 8.4.5
 * 10. bcm.exercise_testing_programme           — ISO 22301 Cl. 8.6
 * 11. bcm.internal_audit_bcm                   — ISO 22301 Cl. 9.2
 * 12. bcm.management_review_bcm                — ISO 22301 Cl. 9.3
 * 13. bcm.nonconformity_corrective_action_bcm  — ISO 22301 Cl. 10.1
 * 14. bcm.notfallhandbuch_bsi_2004             — BSI 200-4 Kap. 7
 *
 * Idempotent: every INSERT uses ON DUPLICATE KEY UPDATE on the
 * `policy_template.key_name` unique index. Re-running on a database
 * that already has the rows updates the topic / norm_ref /
 * linked-controls snapshot in place. The bodies / titles point at
 * translation keys (`policy.bcm.<topic>.v1.body`) — real content is
 * authored in W5-D.
 *
 * Per CLAUDE.md pitfall #6: plain SQL only, no PREPARE/EXECUTE
 * patterns; `isTransactional()=false` because INSERTs into
 * `policy_template` after earlier DDL migrations need their own
 * implicit-commit boundary.
 */
final class Version20260508152000_policy_wizard_w5_bcm_seed extends AbstractMigration
{
    private const string CREATED_AT = '2026-05-08 15:20:00';

    /**
     * @var list<array{
     *     key_name: string,
     *     topic: string,
     *     document_type: string,
     *     norm_ref: string,
     *     title_translation_key: string,
     *     body_translation_key: string,
     *     linked_annex_a_controls: list<string>|null,
     *     linked_bausteine: list<string>|null,
     *     affected_functions: list<string>,
     *     review_interval_months: int,
     *     approval_chain: list<string>,
     *     dpo_section_required: int,
     * }>
     */
    private const array TEMPLATES = [
        [
            'key_name' => 'bcm.bcms_top_level',
            'topic' => 'bcms_top_level',
            'document_type' => 'policy',
            'norm_ref' => 'ISO 22301 Cl. 5.2',
            'title_translation_key' => 'policy.bcm.bcms_top_level.v1.title',
            'body_translation_key' => 'policy.bcm.bcms_top_level.v1.body',
            'linked_annex_a_controls' => ['A.5.29', 'A.5.30'],
            'linked_bausteine' => null,
            'affected_functions' => ['TOP_MGMT', 'BCM', 'CRISIS_TEAM'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'bcm.bcms_scope_statement',
            'topic' => 'bcms_scope_statement',
            'document_type' => 'policy',
            'norm_ref' => 'ISO 22301 Cl. 4.3',
            'title_translation_key' => 'policy.bcm.bcms_scope_statement.v1.title',
            'body_translation_key' => 'policy.bcm.bcms_scope_statement.v1.body',
            'linked_annex_a_controls' => null,
            'linked_bausteine' => null,
            'affected_functions' => ['TOP_MGMT', 'BCM'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'bcm.bia_methodology',
            'topic' => 'bia_methodology',
            'document_type' => 'methodology',
            'norm_ref' => 'ISO 22301 Cl. 8.2.2',
            'title_translation_key' => 'policy.bcm.bia_methodology.v1.title',
            'body_translation_key' => 'policy.bcm.bia_methodology.v1.body',
            'linked_annex_a_controls' => ['A.5.30'],
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'BUSINESS_PROCESS_OWNERS'],
            'review_interval_months' => 24,
            'approval_chain' => ['ROLE_BCM'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'bcm.risk_assessment_methodology_bcm',
            'topic' => 'risk_assessment_methodology_bcm',
            'document_type' => 'methodology',
            'norm_ref' => 'ISO 22301 Cl. 8.2.3',
            'title_translation_key' => 'policy.bcm.risk_assessment_methodology_bcm.v1.title',
            'body_translation_key' => 'policy.bcm.risk_assessment_methodology_bcm.v1.body',
            'linked_annex_a_controls' => null,
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'RISK_MANAGEMENT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_CISO'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'bcm.bc_strategy',
            'topic' => 'bc_strategy',
            'document_type' => 'programme',
            'norm_ref' => 'ISO 22301 Cl. 8.3',
            'title_translation_key' => 'policy.bcm.bc_strategy.v1.title',
            'body_translation_key' => 'policy.bcm.bc_strategy.v1.body',
            'linked_annex_a_controls' => ['A.5.30'],
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'IT_OPERATIONS', 'TOP_MGMT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'bcm.bc_plans',
            'topic' => 'bc_plans',
            'document_type' => 'procedure',
            'norm_ref' => 'ISO 22301 Cl. 8.4.4',
            'title_translation_key' => 'policy.bcm.bc_plans.v1.title',
            'body_translation_key' => 'policy.bcm.bc_plans.v1.body',
            'linked_annex_a_controls' => ['A.5.29'],
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'BUSINESS_PROCESS_OWNERS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'bcm.incident_response_communication',
            'topic' => 'incident_response_communication',
            'document_type' => 'procedure',
            'norm_ref' => 'ISO 22301 Cl. 8.4.3',
            'title_translation_key' => 'policy.bcm.incident_response_communication.v1.title',
            'body_translation_key' => 'policy.bcm.incident_response_communication.v1.body',
            'linked_annex_a_controls' => ['A.5.29'],
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'CRISIS_TEAM', 'COMMUNICATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_CISO'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'bcm.crisis_management_plan',
            'topic' => 'crisis_management_plan',
            'document_type' => 'plan',
            'norm_ref' => 'ISO 22301 Cl. 8.4.4',
            'title_translation_key' => 'policy.bcm.crisis_management_plan.v1.title',
            'body_translation_key' => 'policy.bcm.crisis_management_plan.v1.body',
            'linked_annex_a_controls' => ['A.5.29'],
            'linked_bausteine' => null,
            'affected_functions' => ['CRISIS_TEAM', 'TOP_MGMT', 'BCM'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'bcm.recovery_plans',
            'topic' => 'recovery_plans',
            'document_type' => 'procedure',
            'norm_ref' => 'ISO 22301 Cl. 8.4.5',
            'title_translation_key' => 'policy.bcm.recovery_plans.v1.title',
            'body_translation_key' => 'policy.bcm.recovery_plans.v1.body',
            'linked_annex_a_controls' => ['A.5.30'],
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'IT_OPERATIONS'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_CISO'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'bcm.exercise_testing_programme',
            'topic' => 'exercise_testing_programme',
            'document_type' => 'programme',
            'norm_ref' => 'ISO 22301 Cl. 8.6',
            'title_translation_key' => 'policy.bcm.exercise_testing_programme.v1.title',
            'body_translation_key' => 'policy.bcm.exercise_testing_programme.v1.body',
            'linked_annex_a_controls' => ['A.5.29', 'A.5.30'],
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'CRISIS_TEAM'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'bcm.internal_audit_bcm',
            'topic' => 'internal_audit_bcm',
            'document_type' => 'programme',
            'norm_ref' => 'ISO 22301 Cl. 9.2',
            'title_translation_key' => 'policy.bcm.internal_audit_bcm.v1.title',
            'body_translation_key' => 'policy.bcm.internal_audit_bcm.v1.body',
            'linked_annex_a_controls' => null,
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'INTERNAL_AUDIT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_INTERNAL_AUDIT'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'bcm.management_review_bcm',
            'topic' => 'management_review_bcm',
            'document_type' => 'procedure',
            'norm_ref' => 'ISO 22301 Cl. 9.3',
            'title_translation_key' => 'policy.bcm.management_review_bcm.v1.title',
            'body_translation_key' => 'policy.bcm.management_review_bcm.v1.body',
            'linked_annex_a_controls' => null,
            'linked_bausteine' => null,
            'affected_functions' => ['TOP_MGMT', 'BCM'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'bcm.nonconformity_corrective_action_bcm',
            'topic' => 'nonconformity_corrective_action_bcm',
            'document_type' => 'procedure',
            'norm_ref' => 'ISO 22301 Cl. 10.1',
            'title_translation_key' => 'policy.bcm.nonconformity_corrective_action_bcm.v1.title',
            'body_translation_key' => 'policy.bcm.nonconformity_corrective_action_bcm.v1.body',
            'linked_annex_a_controls' => null,
            'linked_bausteine' => null,
            'affected_functions' => ['BCM', 'INTERNAL_AUDIT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM'],
            'dpo_section_required' => 0,
        ],
        [
            'key_name' => 'bcm.notfallhandbuch_bsi_2004',
            'topic' => 'notfallhandbuch_bsi_2004',
            'document_type' => 'programme',
            'norm_ref' => 'BSI 200-4 Kap. 7',
            'title_translation_key' => 'policy.bcm.notfallhandbuch_bsi_2004.v1.title',
            'body_translation_key' => 'policy.bcm.notfallhandbuch_bsi_2004.v1.body',
            'linked_annex_a_controls' => ['A.5.29', 'A.5.30'],
            'linked_bausteine' => ['DER.4'],
            'affected_functions' => ['BCM', 'CRISIS_TEAM', 'TOP_MGMT'],
            'review_interval_months' => 12,
            'approval_chain' => ['ROLE_BCM', 'ROLE_TOP_MGMT'],
            'dpo_section_required' => 0,
        ],
    ];

    public function isTransactional(): bool
    {
        // No DDL but earlier DDL migrations may have just committed; we
        // keep the per-INSERT implicit boundary by disabling Doctrine's
        // outer transaction (consistent with the rest of the W series).
        return false;
    }

    public function getDescription(): string
    {
        return 'Policy-Wizard W5-B: seed 14 BCM PolicyTemplate rows '
            . '(ISO 22301 Cl. 4.3 / 5.2 / 8.x / 9.x / 10.1 + BSI 200-4 Kap. 7).';
    }

    public function up(Schema $schema): void
    {
        $createdAt = $this->connection->quote(self::CREATED_AT);

        foreach (self::TEMPLATES as $row) {
            $keyQ = $this->connection->quote($row['key_name']);
            $standardQ = $this->connection->quote('bcm');
            $topicQ = $this->connection->quote($row['topic']);
            $docTypeQ = $this->connection->quote($row['document_type']);
            $normQ = $this->connection->quote($row['norm_ref']);
            $titleQ = $this->connection->quote($row['title_translation_key']);
            $bodyQ = $this->connection->quote($row['body_translation_key']);
            $annexQ = $row['linked_annex_a_controls'] === null
                ? 'NULL'
                : $this->connection->quote(json_encode(
                    $row['linked_annex_a_controls'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ));
            $bausteineQ = $row['linked_bausteine'] === null
                ? 'NULL'
                : $this->connection->quote(json_encode(
                    $row['linked_bausteine'],
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
                    {$annexQ}, {$bausteineQ}, NULL,
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
                    linked_annex_a_controls = VALUES(linked_annex_a_controls),
                    linked_bausteine = VALUES(linked_bausteine),
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
