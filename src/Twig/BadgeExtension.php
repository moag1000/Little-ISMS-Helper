<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFunction;
/**
 * Badge Extension for consistent badge rendering across templates
 *
 * Resolves Issues 5.1 & 5.2:
 * - Standardizes badge color schemes
 * - Provides semantic status-to-color mapping
 * - Eliminates inline ternary logic
 *
 * Aurora v4 Migration (Sprint B):
 * - All methods return BC-aliased dual class-set:
 *   "badge bg-{bs-color} fa-status-pill fa-status-pill--{aurora-variant}"
 * - Bootstrap classes kept for backward-compat (templates using the class
 *   string directly without going through _badge.html.twig macro).
 * - Variant mapping: BS "secondary" → Aurora "neutral", BS "info" → Aurora "primary".
 *
 * @author Claude Code
 */
class BadgeExtension
{
    /**
     * Severity level to Bootstrap color mapping
     */
    private const array SEVERITY_MAP = [
        'critical' => 'danger',
        'high' => 'warning',
        'medium' => 'info',
        'low' => 'success',
        'minimal' => 'secondary',
        // Numeric severity (1-5)
        5 => 'danger',     // Critical
        4 => 'warning',    // High
        3 => 'info',       // Medium
        2 => 'success',    // Low
        1 => 'secondary',  // Minimal
    ];

    /**
     * Status to Bootstrap color mapping
     */
    private const array STATUS_MAP = [
        // Incident statuses
        'open' => 'danger',
        'in_progress' => 'warning',
        'investigating' => 'info',
        'resolved' => 'success',
        'closed' => 'secondary',

        // Generic statuses
        'active' => 'success',
        'inactive' => 'secondary',
        'pending' => 'warning',
        'approved' => 'success',
        'rejected' => 'danger',
        'draft' => 'secondary',
        'published' => 'success',

        // Control statuses
        'implemented' => 'success',
        'partially_implemented' => 'warning',
        'not_implemented' => 'danger',
        'planned' => 'info',

        // Audit statuses
        'scheduled' => 'info',
        'completed' => 'success',
        'cancelled' => 'secondary',
        'reported' => 'primary',

        // Audit results
        'passed' => 'success',
        'passed_with_observations' => 'info',
        'failed' => 'danger',
        'not_applicable' => 'secondary',

        // Training statuses
        'mandatory' => 'danger',
        'optional' => 'info',
        'confirmed' => 'primary',
        'postponed' => 'warning',

        // Health/monitoring statuses
        'healthy' => 'success',
        'unhealthy' => 'danger',

        // Document statuses
        'review' => 'warning',
        'obsolete' => 'danger',
    ];

    /**
     * Risk level to Bootstrap color mapping
     */
    private const array RISK_MAP = [
        'critical' => 'danger',
        'high' => 'warning',
        'medium' => 'info',
        'low' => 'success',
        'very_low' => 'secondary',
        'minimal' => 'secondary',
    ];

    /**
     * NIS2 compliance to Bootstrap color mapping
     */
    private const array NIS2_MAP = [
        'compliant' => 'success',
        'partial' => 'warning',
        'non_compliant' => 'danger',
        'not_applicable' => 'secondary',
        'pending' => 'info',
    ];

    /**
     * Action type to Bootstrap color mapping (for audit logs)
     */
    private const array ACTION_MAP = [
        'create' => 'success',
        'update' => 'info',
        'delete' => 'danger',
        'view' => 'secondary',
        'export' => 'primary',
        'import' => 'primary',
    ];

    /**
     * Data classification to Bootstrap color mapping
     */
    private const array CLASSIFICATION_MAP = [
        'public' => 'success',
        'internal' => 'info',
        'confidential' => 'warning',
        'restricted' => 'danger',
    ];

    /**
     * Maps Bootstrap color names to Aurora v4 status-pill variant names.
     *
     * Aurora has: primary, accent, success, warning, danger, neutral.
     * Bootstrap "secondary" → Aurora "neutral" (no-emphasis neutral tone).
     * Bootstrap "info"      → Aurora "primary" (Aurora maps info-blue to primary).
     * Bootstrap "primary"   → Aurora "primary" (direct match).
     */
    private const array BS_TO_AURORA = [
        'danger'    => 'danger',
        'warning'   => 'warning',
        'success'   => 'success',
        'primary'   => 'primary',
        'info'      => 'primary',
        'secondary' => 'neutral',
    ];

    /**
     * Returns the BC-aliased dual class string for a given Bootstrap color.
     *
     * Example: buildBadgeClasses('danger') → "badge bg-danger fa-status-pill fa-status-pill--danger"
     */
    private function buildBadgeClasses(string $bsColor): string
    {
        $auroraVariant = self::BS_TO_AURORA[$bsColor] ?? 'neutral';
        return "badge bg-{$bsColor} fa-status-pill fa-status-pill--{$auroraVariant}";
    }

    /**
     * Get Bootstrap badge class for severity level
     *
     * @param string|int|\BackedEnum $severity Severity level (critical/high/medium/low or 1-5 or backed enum)
     * @return string BC-aliased badge classes: "badge bg-{color} fa-status-pill fa-status-pill--{variant}"
     */
    #[AsTwigFunction('badge_severity')]
    public function getSeverityBadgeClass(string|int|\BackedEnum $severity): string
    {
        if ($severity instanceof \BackedEnum) {
            $severity = $severity->value;
        }
        $severity = is_string($severity) ? strtolower($severity) : $severity;
        $color = self::SEVERITY_MAP[$severity] ?? 'secondary';
        return $this->buildBadgeClasses($color);
    }

    /**
     * Get Bootstrap badge class for status
     *
     * @param string|\BackedEnum $status Status value or backed enum
     * @return string BC-aliased badge classes
     */
    #[AsTwigFunction('badge_status')]
    public function getStatusBadgeClass(string|\BackedEnum $status): string
    {
        if ($status instanceof \BackedEnum) {
            $status = $status->value;
        }
        $status = strtolower($status);
        $color = self::STATUS_MAP[$status] ?? 'secondary';
        return $this->buildBadgeClasses($color);
    }

    /**
     * Get Bootstrap badge class for risk level
     *
     * @param string $riskLevel Risk level (critical/high/medium/low)
     * @return string BC-aliased badge classes
     */
    #[AsTwigFunction('badge_risk')]
    public function getRiskBadgeClass(string $riskLevel): string
    {
        $riskLevel = strtolower($riskLevel);
        $color = self::RISK_MAP[$riskLevel] ?? 'secondary';
        return $this->buildBadgeClasses($color);
    }

    /**
     * Get Bootstrap badge class for NIS2 compliance status
     *
     * @param string $nis2Status NIS2 compliance status
     * @return string BC-aliased badge classes
     */
    #[AsTwigFunction('badge_nis2')]
    public function getNis2BadgeClass(string $nis2Status): string
    {
        $nis2Status = strtolower($nis2Status);
        $color = self::NIS2_MAP[$nis2Status] ?? 'secondary';
        return $this->buildBadgeClasses($color);
    }

    /**
     * Get Bootstrap badge class for action type (audit logs)
     *
     * @param string $action Action type (create/update/delete/etc.)
     * @return string BC-aliased badge classes
     */
    #[AsTwigFunction('badge_action')]
    public function getActionBadgeClass(string $action): string
    {
        $action = strtolower($action);
        $color = self::ACTION_MAP[$action] ?? 'secondary';
        return $this->buildBadgeClasses($color);
    }

    /**
     * Get Bootstrap badge class for data classification
     *
     * @param string $classification Data classification level
     * @return string BC-aliased badge classes
     */
    #[AsTwigFunction('badge_classification')]
    public function getClassificationBadgeClass(string $classification): string
    {
        $classification = strtolower($classification);
        $color = self::CLASSIFICATION_MAP[$classification] ?? 'secondary';
        return $this->buildBadgeClasses($color);
    }

    /**
     * Get Bootstrap badge class for numeric score
     *
     * Automatically maps scores to semantic colors:
     * - 4-5: danger (red)
     * - 3: warning (yellow)
     * - 2: info (blue) → Aurora primary
     * - 0-1: success (green)
     *
     * @param int|float $score Numeric score (typically 0-5)
     * @param int $dangerThreshold Threshold for danger (default: 4)
     * @param int $warningThreshold Threshold for warning (default: 3)
     * @return string BC-aliased badge classes
     */
    #[AsTwigFunction('badge_score')]
    public function getScoreBadgeClass(int|float $score, int $dangerThreshold = 4, int $warningThreshold = 3): string
    {
        if ($score >= $dangerThreshold) {
            $color = 'danger';
        } elseif ($score >= $warningThreshold) {
            $color = 'warning';
        } elseif ($score >= 2) {
            $color = 'info';
        } else {
            $color = 'success';
        }

        return $this->buildBadgeClasses($color);
    }

    /**
     * Get Bootstrap badge class for completion/fulfillment percentage
     *
     * Maps completion percentages to semantic colors (inverted from score):
     * - 100%: success (green) - fully complete
     * - 75-99%: info (blue) → Aurora primary - mostly complete
     * - 50-74%: warning (yellow) - partially complete
     * - 0-49%: danger (red) - incomplete
     *
     * @param int|float $percentage Completion percentage (0-100)
     * @return string BC-aliased badge classes
     */
    #[AsTwigFunction('badge_completion')]
    public function getCompletionBadgeClass(int|float $percentage): string
    {
        if ($percentage >= 100) {
            $color = 'success';
        } elseif ($percentage >= 75) {
            $color = 'info';
        } elseif ($percentage >= 50) {
            $color = 'warning';
        } else {
            $color = 'danger';
        }

        return $this->buildBadgeClasses($color);
    }

    /**
     * Get Bootstrap badge class for priority level
     *
     * @param string $priority Priority level (critical/high/medium/low)
     * @return string BC-aliased badge classes
     */
    #[AsTwigFunction('badge_priority')]
    public function getPriorityBadgeClass(string $priority): string
    {
        $priority = strtolower($priority);

        $priorityMap = [
            'critical' => 'danger',
            'high' => 'warning',
            'medium' => 'info',
            'low' => 'secondary',
        ];

        $color = $priorityMap[$priority] ?? 'secondary';
        return $this->buildBadgeClasses($color);
    }
}
