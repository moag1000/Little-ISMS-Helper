<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFunction;

/**
 * The one table of status tones (W7, Gate 78).
 *
 * Three families, each with its own meaning of the six Aurora tones
 * (primary/accent/success/warning/danger/neutral):
 *
 *  - STATUS_WORDS — a status word has ONE tone everywhere: workflow places
 *    (`metadata.tone` in config/workflows/*.yaml), the status vocabularies below,
 *    `pill_tone_status()` / `badge_status()`. neutral = not started, ended or out of
 *    play; primary = planned or in work; warning = waiting, open, needs attention;
 *    success = done, valid, in force; danger = rejected, failed, broken.
 *  - SEVERITY — severity, criticality, priority, risk level, protection need
 *    (`pill_tone_severity()`): critical danger, high warning, medium primary,
 *    low success, minimal neutral. A three-level scale (high/medium/low) uses the
 *    same words, so `high` is warning there too.
 *  - VOCABULARIES — named vocabularies for `_status_vocabulary.html.twig`
 *    (`status_tone(vocabulary, value)`). A vocabulary in STATUS_FAMILY uses the
 *    STATUS_WORDS tones, one in SEVERITY_FAMILY the SEVERITY tones
 *    (`StatusToneConsistencyTest`); the rest are scales with their own meaning
 *    (confidence high = good, substitutability low = bad, RAG colours, …).
 *
 * Unknown vocabulary or value → neutral.
 */
final class StatusVocabulary
{
    public const array STATUS_WORDS = [
        // not started / ended / out of play
        'draft' => 'neutral', 'received' => 'neutral', 'not_started' => 'neutral', 'inactive' => 'neutral',
        'archived' => 'neutral', 'deleted' => 'neutral', 'cancelled' => 'neutral', 'withdrawn' => 'neutral',
        'superseded' => 'neutral', 'retired' => 'neutral', 'disposed' => 'neutral', 'returned' => 'neutral',
        'not_applicable' => 'neutral', 'false_positive' => 'neutral', 'closed' => 'neutral',
        'terminated' => 'neutral', 'pending_digest' => 'neutral', 'deprecated' => 'neutral',
        'obsolete' => 'neutral', 'dismissed' => 'neutral', 'skipped' => 'neutral',
        // planned / in work
        'planned' => 'primary', 'scheduled' => 'primary', 'planning' => 'primary', 'new' => 'primary',
        'in_review' => 'primary', 'under_review' => 'primary', 'review' => 'primary', 'dpo_sign_off' => 'primary',
        'under_assessment' => 'primary', 'identity_verification' => 'primary', 'in_triage' => 'primary',
        'in_investigation' => 'primary', 'investigating' => 'primary', 'analyzing' => 'primary',
        'testing' => 'primary', 'evaluation' => 'primary', 'scoping' => 'primary', 'in_progress' => 'primary',
        'in_resolution' => 'primary', 'in_remediation' => 'primary', 'in_treatment' => 'primary',
        'red_team' => 'primary', 'reporting' => 'primary', 'assessed' => 'primary', 'conducted' => 'primary',
        'monitoring' => 'primary', 'monitored' => 'primary', 'running' => 'primary',
        // waiting / open / needs attention
        'pending' => 'warning', 'pending_verification' => 'warning', 'open' => 'warning',
        'identified' => 'warning', 'reported' => 'warning', 'submitted' => 'warning',
        'requires_revision' => 'warning', 'follow_up_required' => 'warning', 'on_hold' => 'warning',
        'postponed' => 'warning', 'delayed' => 'warning', 'retrying' => 'warning', 'extended' => 'warning',
        'rolled_back' => 'warning', 'suspended' => 'warning', 'partially_implemented' => 'warning',
        'partial' => 'warning', 'degraded' => 'warning',
        // done / valid / in force
        'active' => 'success', 'in_use' => 'success', 'approved' => 'success', 'published' => 'success',
        'resolved' => 'success', 'completed' => 'success', 'achieved' => 'success', 'verified' => 'success',
        'verified_effective' => 'success', 'treated' => 'success', 'mitigated' => 'success',
        'patched' => 'success', 'deployed' => 'success', 'implemented' => 'success', 'operational' => 'success',
        'delivered' => 'success', 'sent' => 'success', 'authority_notified' => 'success',
        'subjects_notified' => 'success', 'reviewed' => 'success', 'accepted' => 'success', 'done' => 'success',
        'committed' => 'success', 'succeeded' => 'success', 'passed' => 'success', 'healthy' => 'success',
        'compliant' => 'success', 'auto_approved' => 'success',
        // rejected / failed / broken
        'rejected' => 'danger', 'revoked' => 'danger', 'failed' => 'danger', 'verified_ineffective' => 'danger',
        'expired' => 'danger', 'overdue' => 'danger', 'not_implemented' => 'danger', 'unhealthy' => 'danger', 'non_compliant' => 'danger',
    ];

    public const array SEVERITY = [
        'critical' => 'danger', 'very_high' => 'danger', 'essential' => 'danger', 'immediate' => 'danger',
        'urgent' => 'danger', 'prohibited' => 'danger', 'breach' => 'danger', 'major' => 'danger',
        'high' => 'warning', 'important' => 'warning', 'moderate' => 'warning',
        'medium' => 'primary', 'normal' => 'primary', 'limited' => 'primary',
        'low' => 'success', 'minor' => 'success',
        'very_low' => 'neutral', 'minimal' => 'neutral', 'none' => 'neutral', 'unknown' => 'neutral',
        // numeric 5-point scale
        '5' => 'danger', '4' => 'warning', '3' => 'primary', '2' => 'success', '1' => 'neutral',
    ];

    /** Vocabularies whose values are status words (tones = STATUS_WORDS). */
    public const array STATUS_FAMILY = [
        'workflow', 'consent', 'prototype_assessment', 'import_session', 'suggestion', 'notification_delivery',
        'bc_exercise', 'threat', 'data_breach', 'dsr', 'dpia', 'management_review', 'objective', 'certificate',
        'action_item', 'workflow_action', 'async_job', 'health', 'control_implementation', 'evidence_task',
        'mapping_gap',
    ];

    /** Vocabularies whose values are severity words (tones = SEVERITY). */
    public const array SEVERITY_FAMILY = ['protection_level', 'recovery_priority', 'escalation', 'entity_class', 'ai_act'];

    public const array VOCABULARIES = [
        // status family
        'workflow' => ['pending' => 'warning', 'in_progress' => 'primary', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'neutral'],
        'workflow_action' => ['approved' => 'success', 'auto_approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'neutral', 'expired' => 'danger', 'submitted' => 'warning', 'pending' => 'warning'],
        'consent' => ['pending_verification' => 'warning', 'active' => 'success', 'revoked' => 'danger', 'expired' => 'danger', 'rejected' => 'danger'],
        'prototype_assessment' => ['draft' => 'neutral', 'in_review' => 'primary', 'approved' => 'success', 'rejected' => 'danger', 'expired' => 'danger'],
        'import_session' => ['committed' => 'success', 'preview' => 'warning', 'cancelled' => 'neutral'],
        'suggestion' => ['pending' => 'warning', 'accepted' => 'success', 'rejected' => 'danger', 'superseded' => 'neutral'],
        'notification_delivery' => ['sent' => 'success', 'delivered' => 'success', 'failed' => 'danger', 'pending' => 'warning', 'retrying' => 'warning', 'archived' => 'neutral'],
        'bc_exercise' => ['planned' => 'primary', 'in_progress' => 'primary', 'completed' => 'success', 'cancelled' => 'neutral'],
        'threat' => ['new' => 'primary', 'analyzing' => 'primary', 'mitigated' => 'success', 'monitoring' => 'primary', 'closed' => 'neutral'],
        'data_breach' => ['draft' => 'neutral', 'under_assessment' => 'primary', 'authority_notified' => 'success', 'subjects_notified' => 'success', 'closed' => 'neutral'],
        'dsr' => ['received' => 'neutral', 'identity_verification' => 'primary', 'in_progress' => 'primary', 'completed' => 'success', 'rejected' => 'danger', 'extended' => 'warning'],
        'dpia' => ['draft' => 'neutral', 'in_review' => 'primary', 'approved' => 'success', 'rejected' => 'danger', 'requires_revision' => 'warning'],
        'management_review' => ['planned' => 'primary', 'in_progress' => 'primary', 'completed' => 'success', 'cancelled' => 'neutral', 'follow_up_required' => 'warning'],
        'objective' => ['not_started' => 'neutral', 'in_progress' => 'primary', 'achieved' => 'success', 'delayed' => 'warning', 'cancelled' => 'neutral'],
        'certificate' => ['active' => 'success', 'pending' => 'warning', 'expired' => 'danger', 'revoked' => 'danger'],
        'action_item' => ['open' => 'warning', 'planned' => 'primary', 'in_progress' => 'primary', 'done' => 'success', 'dismissed' => 'neutral'],
        'async_job' => ['pending' => 'warning', 'running' => 'primary', 'succeeded' => 'success', 'completed' => 'success', 'failed' => 'danger', 'cancelled' => 'neutral'],
        'health' => ['healthy' => 'success', 'degraded' => 'warning', 'warning' => 'warning', 'unhealthy' => 'danger', 'error' => 'danger', 'critical' => 'danger'],
        'control_implementation' => ['implemented' => 'success', 'operational' => 'success', 'partially_implemented' => 'warning', 'in_progress' => 'primary', 'planned' => 'primary', 'not_started' => 'neutral', 'not_implemented' => 'danger', 'not_applicable' => 'neutral'],
        'evidence_task' => ['pending' => 'warning', 'overdue' => 'danger', 'completed' => 'success', 'skipped' => 'neutral'],
        'mapping_gap' => ['open' => 'warning', 'in_progress' => 'primary', 'resolved' => 'success', 'accepted' => 'success'],
        // compliance results: done/partial/in work, everything else is a gap (danger)
        'compliance_result' => ['compliant' => 'success', 'partial' => 'warning', 'in_progress' => 'primary', 'needs_work' => 'danger', 'not_started' => 'danger', 'non_compliant' => 'danger'],

        // severity family
        'protection_level' => ['very_high' => 'danger', 'high' => 'warning', 'normal' => 'primary', 'low' => 'success'],
        'recovery_priority' => ['immediate' => 'danger', 'high' => 'warning', 'medium' => 'primary', 'low' => 'success'],
        'escalation' => ['low' => 'success', 'medium' => 'primary', 'high' => 'warning', 'critical' => 'danger', 'data_breach' => 'danger'],
        // NIS-2 entity class (Art. 3): essential above important
        'entity_class' => ['essential' => 'danger', 'important' => 'warning'],
        // EU AI Act risk classes (Art. 5/6/50)
        'ai_act' => ['prohibited' => 'danger', 'high' => 'warning', 'limited' => 'primary', 'minimal' => 'neutral'],

        // scales with their own meaning
        'import_row' => ['new' => 'success', 'update' => 'primary', 'conflict' => 'warning', 'error' => 'danger'],
        'import_decision' => ['import' => 'success', 'update' => 'primary', 'merge' => 'primary', 'skip' => 'warning', 'error' => 'danger'],
        'coverage' => ['good' => 'success', 'warning' => 'warning', 'danger' => 'danger', 'info' => 'primary', 'na' => 'neutral'],
        'security_zone' => ['public' => 'neutral', 'restricted' => 'primary', 'controlled' => 'warning', 'secure' => 'warning', 'high_security' => 'danger'],
        'governance_model' => ['hierarchical' => 'primary', 'shared' => 'warning'],
        'finding_severity' => ['major_nc' => 'danger', 'minor_nc' => 'warning', 'observation' => 'primary', 'opportunity' => 'success'],
        // higher is better
        'confidence' => ['high' => 'success', 'medium' => 'warning', 'low' => 'neutral'],
        'maturity_level' => ['0' => 'neutral', '1' => 'danger', '2' => 'warning', '3' => 'primary', '4' => 'accent', '5' => 'success', 'initial' => 'danger', 'managed' => 'success', 'defined' => 'primary'],
        // DORA Art. 28 (exit strategy): low substitutability is the risk
        'substitutability' => ['low' => 'danger', 'medium' => 'warning', 'high' => 'success'],
        'person_type' => ['employee' => 'success', 'contractor' => 'primary', 'visitor' => 'warning', 'vendor' => 'primary', 'auditor' => 'danger', 'consultant' => 'primary'],
        'requirement_result' => ['not_applicable' => 'neutral', 'not_met' => 'danger', 'partial' => 'warning', 'met' => 'success', 'exceeded' => 'success'],
        'kpi_status' => ['good' => 'success', 'warning' => 'warning', 'danger' => 'danger'],
        'trend' => ['good' => 'success', 'bad' => 'danger', 'positive' => 'success', 'negative' => 'danger', 'decreasing' => 'success', 'increasing' => 'danger', 'stable' => 'neutral'],
        'rag' => ['green' => 'success', 'amber' => 'warning', 'yellow' => 'warning', 'red' => 'danger', 'grey' => 'neutral', 'gray' => 'neutral'],
        'roadmap' => ['on_track' => 'success', 'completed' => 'success', 'at_risk' => 'warning', 'in_progress' => 'primary', 'off_track' => 'danger', 'delayed' => 'danger', 'critical' => 'danger'],
        'readiness' => ['ready' => 'success', 'conditional' => 'warning', 'not_ready' => 'danger'],
        'log_level' => ['emergency' => 'danger', 'alert' => 'danger', 'critical' => 'danger', 'error' => 'danger', 'warning' => 'warning', 'notice' => 'primary', 'info' => 'primary', 'debug' => 'neutral'],
        'audit_action' => ['create' => 'success', 'add' => 'success', 'login' => 'primary', 'update' => 'primary', 'modify' => 'primary', 'change' => 'primary', 'export' => 'primary', 'import' => 'primary', 'view' => 'neutral', 'login_failed' => 'warning', 'delete' => 'danger', 'force_delete' => 'danger', 'destroy' => 'danger', 'remove' => 'danger', 'error' => 'danger', 'failed' => 'danger'],
        'classification' => ['public' => 'success', 'internal' => 'primary', 'confidential' => 'warning', 'restricted' => 'danger', 'strictly_confidential' => 'danger'],
        'diff_change' => ['added' => 'success', 'removed' => 'danger', 'changed' => 'warning', 'modified' => 'warning'],
        'approval_level' => ['automatic' => 'success', 'manager' => 'warning', 'executive' => 'danger', 'board' => 'danger'],
        'framework_bucket' => ['mandatory' => 'danger', 'recommended' => 'warning', 'optional' => 'neutral'],
        // BSI IT-Grundschutz modal verbs (MUSS/SOLLTE/KANN)
        'bsi_modality' => ['muss' => 'danger', 'sollte' => 'warning', 'kann' => 'primary'],
        'gstool_kind' => ['direct' => 'success', 'split' => 'warning', 'merged' => 'primary', 'removed' => 'danger', 'bcm' => 'primary'],
        'bsi_tier' => ['basis' => 'primary', 'standard' => 'primary', 'kern' => 'danger'],
        'bsi_gap_state' => ['gedeckt' => 'success', 'partiell' => 'warning', 'iso_offen' => 'primary', 'ungemappt_unbewertet' => 'neutral', 'ungemappt_eigenstaendig' => 'neutral'],
        'bsi_trust' => ['amtlich' => 'success', 'amtlich_gestuetzt' => 'success', 'ki_validiert' => 'primary', 'bestaetigt' => 'primary', 'heuristisch' => 'warning'],
        'suggestion_origin' => ['rule' => 'primary', 'inheritance' => 'primary', 'import' => 'neutral', 'ai' => 'accent'],
        'journey' => ['complete' => 'success', 'partial' => 'primary', 'not_started' => 'primary', 'locked' => 'neutral', 'dismissed' => 'neutral'],
    ];

    /**
     * Tone of a value in a named vocabulary (Twig `status_tone(vocabulary, value)`).
     */
    #[AsTwigFunction('status_tone')]
    public function toneOf(string $vocabulary, string|int|\BackedEnum|null $value): string
    {
        return self::tone($vocabulary, $value);
    }

    public static function tone(string $vocabulary, string|int|\BackedEnum|null $value): string
    {
        $key = self::key($value);

        return self::VOCABULARIES[$vocabulary][$key] ?? 'neutral';
    }

    /**
     * Tone of a status word (the STATUS_WORDS table).
     */
    public static function statusTone(string|\BackedEnum|null $status): string
    {
        return self::STATUS_WORDS[self::key($status)] ?? 'neutral';
    }

    /**
     * Tone of a severity / criticality / priority word or 1–5.
     */
    public static function severityTone(string|int|\BackedEnum|null $value): string
    {
        return self::SEVERITY[self::key($value)] ?? 'neutral';
    }

    private static function key(string|int|\BackedEnum|null $value): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        return strtolower(trim((string) $value));
    }
}
