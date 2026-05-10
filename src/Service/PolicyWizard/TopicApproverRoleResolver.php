<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\User;

/**
 * TopicApproverRoleResolver — Task #126.
 *
 * Resolves the recommended approver role(s) for a given PolicyTemplate
 * topic key, and validates whether a concrete {@see User} actually
 * carries one of those roles. Used by:
 *
 *  - {@see PolicySectionApprovalService::assertApproverRoleMatch()} to
 *    write the role-match audit-event when a section is approved.
 *  - {@see \App\Service\PolicyWizard\Step\RolesStep} (UI display only —
 *    surfaces a "Recommended role" tooltip per topic-row).
 *  - {@see \App\Service\PolicyWizard\Step\LifecycleStep} (UI display
 *    only — surfaces match-badges in the per-template approver picker).
 *
 * The resolver is intentionally NOT blocking: when an approver mismatches
 * the recommended role(s) the wizard records a `policy_wizard.approver_
 * role_mismatch_warning` audit event but lets the kickoff proceed —
 * external auditor "warum DIESER Approver fuer DIESES Topic" finding is
 * answered by the audit trail, not by a runtime block.
 *
 * Persona-walkthrough trigger: Risk-Owner-Business + Auditor-External
 * walkthrough showed the approval-chain in the wizard had no role
 * validation — an ISB could send a Cryptography Policy to a Risk-Owner
 * for sign-off and nothing in the system flagged the mismatch.
 */
final class TopicApproverRoleResolver
{
    /**
     * Match-result enum-like constants returned from
     * {@see validateApproverForTopic}. Kept as plain string constants so
     * they survive the audit-log serialisation roundtrip.
     */
    public const string MATCH_STRICT = 'strict_match';
    public const string MATCH_WEAK = 'weak_match';
    public const string MATCH_MISMATCH = 'mismatch';

    /**
     * Default fallback when a topic has no specific recommendation.
     * CISO is the universal "always-acceptable" approver for InfoSec
     * topics; Top-Mgmt seconds it for governance-level Documents.
     *
     * @var list<string>
     */
    private const array DEFAULT_RECOMMENDED_ROLES = ['ROLE_CISO', 'ROLE_TOP_MGMT'];

    /**
     * Topic-key → recommended approver-role(s) map. Keys mirror the
     * `PolicyTemplate.topic` column populated by Seed*PolicyTemplates
     * commands. Roles are full Symfony role-strings (not role-name
     * fragments) so {@see User::getRoles()} comparison is exact.
     *
     * Multiple recommended roles per topic = ANY-of (the approver is a
     * strict_match if they hold AT LEAST ONE of the listed roles).
     *
     * @var array<string, list<string>>
     */
    private const array TOPIC_ROLE_MAP = [
        // ── ISO 27002:2022 organizational topics ──────────────────
        'top_level' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
        'acceptable_use' => ['ROLE_CISO'],
        'access_control' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'information_classification' => ['ROLE_CISO'],
        'information_transfer' => ['ROLE_CISO'],
        'identity_management' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'authentication_information' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'cryptography' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'crypto_concept' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'backup' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'backup_concept' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'logging' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'logging_policy' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'patch_management' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'patch_change_management_policy' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'malware' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'malware_protection_policy' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'secure_configuration' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'network_security' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'secure_development' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'software_development_policy' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'software_test_release_policy' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'project_management' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
        'asset_management' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'mobile_device' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'teleworking_policy' => ['ROLE_CISO', 'ROLE_HR_LEAD'],
        'foreign_travel_policy' => ['ROLE_CISO', 'ROLE_HR_LEAD'],
        'remote_maintenance_policy' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'cloud_usage_policy' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'web_application_policy' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'it_administration_policy' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'detection_policy' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],

        // ── HR / People security ──────────────────────────────────
        'hr_security' => ['ROLE_HR_LEAD', 'ROLE_CISO'],
        'personnel_policy' => ['ROLE_HR_LEAD', 'ROLE_CISO'],
        'awareness_policy' => ['ROLE_CISO', 'ROLE_HR_LEAD'],

        // ── Supplier & Procurement ────────────────────────────────
        'supplier_relationships' => ['ROLE_PROCUREMENT_LEAD', 'ROLE_CISO'],
        'outsourcing_supplier_policy' => ['ROLE_PROCUREMENT_LEAD', 'ROLE_CISO'],
        'information_exchange_policy' => ['ROLE_CISO', 'ROLE_PROCUREMENT_LEAD'],

        // ── Physical security ─────────────────────────────────────
        'physical_security' => ['ROLE_FACILITIES_LEAD', 'ROLE_CISO'],

        // ── Incident & Forensics ──────────────────────────────────
        'incident_management' => ['ROLE_CISO'],
        'incident_response' => ['ROLE_CISO'],
        'incident_response_communication' => ['ROLE_CISO', 'ROLE_BCM_OFFICER'],
        'it_forensics' => ['ROLE_CISO'],

        // ── Business Continuity (BCM / ISO 22301) ─────────────────
        'continuity' => ['ROLE_BCM_OFFICER', 'ROLE_CISO'],
        'emergency_management' => ['ROLE_BCM_OFFICER', 'ROLE_CISO'],
        'bcms_top_level' => ['ROLE_BCM_OFFICER', 'ROLE_TOP_MGMT'],
        'bcms_scope_statement' => ['ROLE_BCM_OFFICER', 'ROLE_TOP_MGMT'],
        'bia_methodology' => ['ROLE_BCM_OFFICER'],
        'risk_assessment_methodology_bcm' => ['ROLE_BCM_OFFICER', 'ROLE_CISO'],
        'bc_strategy' => ['ROLE_BCM_OFFICER', 'ROLE_TOP_MGMT'],
        'bc_plans' => ['ROLE_BCM_OFFICER'],
        'crisis_management_plan' => ['ROLE_BCM_OFFICER', 'ROLE_TOP_MGMT'],
        'recovery_plans' => ['ROLE_BCM_OFFICER'],
        'exercise_testing_programme' => ['ROLE_BCM_OFFICER'],
        'internal_audit_bcm' => ['ROLE_BCM_OFFICER', 'ROLE_CISO'],
        'management_review_bcm' => ['ROLE_TOP_MGMT', 'ROLE_BCM_OFFICER'],
        'nonconformity_corrective_action_bcm' => ['ROLE_BCM_OFFICER', 'ROLE_CISO'],
        'notfallhandbuch_bsi_2004' => ['ROLE_BCM_OFFICER', 'ROLE_CISO'],

        // ── Data Protection / Privacy ─────────────────────────────
        'privacy_pii' => ['ROLE_DPO'],
        'privacy_policy' => ['ROLE_DPO'],
        'dpo_charter' => ['ROLE_DPO', 'ROLE_TOP_MGMT'],
        'data_protection' => ['ROLE_DPO'],
        'dpia' => ['ROLE_DPO'],
        'deletion_policy' => ['ROLE_DPO', 'ROLE_CISO'],

        // ── Threat Intelligence ───────────────────────────────────
        'threat_intelligence' => ['ROLE_CISO'],

        // ── BSI building blocks (top-level) ───────────────────────
        'it_security_policy' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
        'isms_concept' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
        'security_organization' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
        'organization_policy' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
        'iam' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'protection_needs_methodology' => ['ROLE_CISO'],

        // ── DORA (ICT-Risk for financial entities) ────────────────
        'ict_risk_management_framework' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
        'ict_risk_tolerance' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
        'detection_anomalous_activities' => ['ROLE_CISO', 'ROLE_IT_OPS_LEAD'],
        'response_recovery' => ['ROLE_CISO', 'ROLE_BCM_OFFICER'],
        'learning_evolving' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],
        'communication_ict_incidents' => ['ROLE_CISO', 'ROLE_TOP_MGMT'],

        // ── Legal / Compliance overlay ────────────────────────────
        'legal_compliance' => ['ROLE_LEGAL', 'ROLE_CISO'],
    ];

    /**
     * Roles that count as "weak match" for any topic — these users
     * inherit broad approval authority but are not the topic-specific
     * fachlich-correct approver. Used to differentiate "ROLE_ADMIN
     * approves a Cryptography Policy" (weak — admin can approve
     * anything operationally, but a CISO-sign-off would be the strict
     * match) from "Risk-Owner-Business approves a Cryptography Policy"
     * (mismatch — neither topic-recommended NOR universal).
     *
     * @var list<string>
     */
    private const array UNIVERSAL_WEAK_ROLES = [
        'ROLE_ADMIN',
        'ROLE_SUPER_ADMIN',
        'ROLE_GROUP_CISO',
        'ROLE_TOP_MGMT',
    ];

    /**
     * Returns the list of recommended approver-roles for a given topic
     * key. Unknown topic keys collapse to the
     * {@see DEFAULT_RECOMMENDED_ROLES} fallback so a freshly seeded
     * Topic that isn't yet in the map never deadlocks the wizard.
     *
     * @return list<string>
     */
    public function recommendedRolesForTopic(?string $topicKey): array
    {
        if ($topicKey === null || $topicKey === '') {
            return self::DEFAULT_RECOMMENDED_ROLES;
        }
        return self::TOPIC_ROLE_MAP[$topicKey] ?? self::DEFAULT_RECOMMENDED_ROLES;
    }

    /**
     * Validate whether the given $approver carries any of the
     * recommended roles for $topicKey.
     *
     * Returns an {@see ApproverMatchResult} with three possible match
     * states:
     *  - MATCH_STRICT : approver carries one of the recommended roles
     *                   for the topic (the fachlich-correct approver).
     *  - MATCH_WEAK   : approver does NOT carry a recommended role but
     *                   carries a universal-weak role (ADMIN/SUPER_ADMIN
     *                   /GROUP_CISO/TOP_MGMT). The approval is allowed
     *                   but the audit trail flags "broad-authority
     *                   approval used in lieu of topic-specialist".
     *  - MATCH_MISMATCH : approver carries neither — Risk-Owner-Business
     *                     signing off a Cryptography Policy is the
     *                     canonical example. Audit-event with
     *                     `policy_wizard.approver_role_mismatch_warning`
     *                     is written by the caller; approval still
     *                     proceeds (wizard-starter may overrule).
     */
    public function validateApproverForTopic(User $approver, ?string $topicKey): ApproverMatchResult
    {
        $recommended = $this->recommendedRolesForTopic($topicKey);
        $approverRoles = $approver->getRoles();

        $strictHits = array_values(array_intersect($recommended, $approverRoles));
        if ($strictHits !== []) {
            return new ApproverMatchResult(
                state: self::MATCH_STRICT,
                topicKey: $topicKey,
                recommendedRoles: $recommended,
                approverRoles: $approverRoles,
                matchedRoles: $strictHits,
                reason: sprintf(
                    'Approver holds recommended role(s) [%s] for topic "%s"',
                    implode(', ', $strictHits),
                    $topicKey ?? '?',
                ),
            );
        }

        $weakHits = array_values(array_intersect(self::UNIVERSAL_WEAK_ROLES, $approverRoles));
        if ($weakHits !== []) {
            return new ApproverMatchResult(
                state: self::MATCH_WEAK,
                topicKey: $topicKey,
                recommendedRoles: $recommended,
                approverRoles: $approverRoles,
                matchedRoles: $weakHits,
                reason: sprintf(
                    'Approver holds broad-authority role(s) [%s] but not the topic-specific recommended role(s) [%s] for "%s"',
                    implode(', ', $weakHits),
                    implode(', ', $recommended),
                    $topicKey ?? '?',
                ),
            );
        }

        return new ApproverMatchResult(
            state: self::MATCH_MISMATCH,
            topicKey: $topicKey,
            recommendedRoles: $recommended,
            approverRoles: $approverRoles,
            matchedRoles: [],
            reason: sprintf(
                'Approver does not hold any of the recommended role(s) [%s] for topic "%s" (approver has [%s])',
                implode(', ', $recommended),
                $topicKey ?? '?',
                implode(', ', $approverRoles),
            ),
        );
    }
}
