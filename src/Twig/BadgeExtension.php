<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Badge Extension for consistent badge rendering across templates
 *
 * Resolves Issues 5.1 & 5.2:
 * - Standardizes badge color schemes
 * - Provides semantic status-to-color mapping
 * - Eliminates inline ternary logic
 *
 * @author Claude Code
 */
class BadgeExtension extends AbstractExtension
{
    /**
     * Severity level to Bootstrap color mapping
     */
    private const SEVERITY_MAP = [
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
    private const STATUS_MAP = [
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
    ];

    /**
     * Risk level to Bootstrap color mapping
     */
    private const RISK_MAP = [
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
    private const NIS2_MAP = [
        'compliant' => 'success',
        'partial' => 'warning',
        'non_compliant' => 'danger',
        'not_applicable' => 'secondary',
        'pending' => 'info',
    ];

    /**
     * Action type to Bootstrap color mapping (for audit logs)
     */
    private const ACTION_MAP = [
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
    private const CLASSIFICATION_MAP = [
        'public' => 'success',
        'internal' => 'info',
        'confidential' => 'warning',
        'restricted' => 'danger',
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('badge_severity', [$this, 'getSeverityBadgeClass']),
            new TwigFunction('badge_status', [$this, 'getStatusBadgeClass']),
            new TwigFunction('badge_risk', [$this, 'getRiskBadgeClass']),
            new TwigFunction('badge_nis2', [$this, 'getNis2BadgeClass']),
            new TwigFunction('badge_action', [$this, 'getActionBadgeClass']),
            new TwigFunction('badge_classification', [$this, 'getClassificationBadgeClass']),
            new TwigFunction('badge_score', [$this, 'getScoreBadgeClass']),
            new TwigFunction('badge_completion', [$this, 'getCompletionBadgeClass']),
            new TwigFunction('badge_priority', [$this, 'getPriorityBadgeClass']),
        ];
    }

    /**
     * Get Bootstrap badge class for severity level
     *
     * @param string|int $severity Severity level (critical/high/medium/low or 1-5)
     * @return string Bootstrap badge class (e.g., "badge bg-danger")
     */
    public function getSeverityBadgeClass(string|int $severity): string
    {
        $severity = is_string($severity) ? strtolower($severity) : $severity;
        $color = self::SEVERITY_MAP[$severity] ?? 'secondary';
        return "badge bg-{$color}";
    }

    /**
     * Get Bootstrap badge class for status
     *
     * @param string $status Status value
     * @return string Bootstrap badge class
     */
    public function getStatusBadgeClass(string $status): string
    {
        $status = strtolower($status);
        $color = self::STATUS_MAP[$status] ?? 'secondary';
        return "badge bg-{$color}";
    }

    /**
     * Get Bootstrap badge class for risk level
     *
     * @param string $riskLevel Risk level (critical/high/medium/low)
     * @return string Bootstrap badge class
     */
    public function getRiskBadgeClass(string $riskLevel): string
    {
        $riskLevel = strtolower($riskLevel);
        $color = self::RISK_MAP[$riskLevel] ?? 'secondary';
        return "badge bg-{$color}";
    }

    /**
     * Get Bootstrap badge class for NIS2 compliance status
     *
     * @param string $nis2Status NIS2 compliance status
     * @return string Bootstrap badge class
     */
    public function getNis2BadgeClass(string $nis2Status): string
    {
        $nis2Status = strtolower($nis2Status);
        $color = self::NIS2_MAP[$nis2Status] ?? 'secondary';
        return "badge bg-{$color}";
    }

    /**
     * Get Bootstrap badge class for action type (audit logs)
     *
     * @param string $action Action type (create/update/delete/etc.)
     * @return string Bootstrap badge class
     */
    public function getActionBadgeClass(string $action): string
    {
        $action = strtolower($action);
        $color = self::ACTION_MAP[$action] ?? 'secondary';
        return "badge bg-{$color}";
    }

    /**
     * Get Bootstrap badge class for data classification
     *
     * @param string $classification Data classification level
     * @return string Bootstrap badge class
     */
    public function getClassificationBadgeClass(string $classification): string
    {
        $classification = strtolower($classification);
        $color = self::CLASSIFICATION_MAP[$classification] ?? 'secondary';
        return "badge bg-{$color}";
    }

    /**
     * Get Bootstrap badge class for numeric score
     *
     * Automatically maps scores to semantic colors:
     * - 4-5: danger (red)
     * - 3: warning (yellow)
     * - 2: info (blue)
     * - 0-1: success (green)
     *
     * @param int|float $score Numeric score (typically 0-5)
     * @param int $dangerThreshold Threshold for danger (default: 4)
     * @param int $warningThreshold Threshold for warning (default: 3)
     * @return string Bootstrap badge class
     */
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

        return "badge bg-{$color}";
    }

    /**
     * Get Bootstrap badge class for completion/fulfillment percentage
     *
     * Maps completion percentages to semantic colors (inverted from score):
     * - 100%: success (green) - fully complete
     * - 75-99%: info (blue) - mostly complete
     * - 50-74%: warning (yellow) - partially complete
     * - 0-49%: danger (red) - incomplete
     *
     * @param int|float $percentage Completion percentage (0-100)
     * @return string Bootstrap badge class
     */
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

        return "badge bg-{$color}";
    }

    /**
     * Get Bootstrap badge class for priority level
     *
     * @param string $priority Priority level (critical/high/medium/low)
     * @return string Bootstrap badge class
     */
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
        return "badge bg-{$color}";
    }
}
