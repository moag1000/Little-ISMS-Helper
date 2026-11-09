<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFunction;

/**
 * Pill tones and status-pill classes for templates (FairyAurora v4).
 *
 * Every word-based tone comes from {@see StatusVocabulary} (W7: one table of
 * status, severity and named-vocabulary tones), so the same value has the same
 * colour on every page:
 *
 *  - `pill_tone_severity(x)` / `badge_severity()` / `badge_risk()` / `badge_priority()`
 *    — the severity scale (critical danger, high warning, medium primary, low success).
 *  - `pill_tone_status(x)` / `badge_status()` — the status words.
 *  - `pill_tone_action(x)` / `pill_tone_classification(x)` — the audit-log action and
 *    data-classification vocabularies.
 *  - `pill_tone_bs(colour)` — a stored Bootstrap colour (tags, criticality levels,
 *    mapping badges) as Aurora tone; `flash_tone(label)` — a flash label as alert tone.
 *
 * `badge_*()` return "fa-status-pill fa-status-pill--{tone}"; `pill_tone_*()` the tone.
 */
final class BadgeExtension
{
    /** Bootstrap colour (or Aurora tone) → Aurora status-pill tone. */
    private const array BS_TO_AURORA = [
        'danger' => 'danger',
        'warning' => 'warning',
        'success' => 'success',
        'primary' => 'primary',
        'accent' => 'accent',
        'neutral' => 'neutral',
        'info' => 'primary',
        'secondary' => 'neutral',
        'light' => 'neutral',
        'dark' => 'neutral',
        'muted' => 'neutral',
        // legacy _fa_confirm tones
        'warn' => 'warning',
        'nuclear' => 'danger',
    ];

    /** Flash label → _fa_alert variant (info / success / warning / danger). */
    private const array FLASH_TO_ALERT = [
        'success' => 'success',
        'warning' => 'warning',
        'danger' => 'danger',
        'error' => 'danger',
        'info' => 'info',
        'notice' => 'info',
    ];

    /** NIS-2 compliance status → tone. */
    private const array NIS2_MAP = [
        'compliant' => 'success',
        'partial' => 'warning',
        'non_compliant' => 'danger',
        'not_applicable' => 'neutral',
        'pending' => 'warning',
    ];

    /**
     * Aurora tone of a Bootstrap colour or Aurora tone name; unknown → neutral.
     */
    public static function auroraTone(?string $color): string
    {
        return self::BS_TO_AURORA[strtolower(trim((string) $color))] ?? 'neutral';
    }

    #[AsTwigFunction('pill_tone_bs')]
    public function getBootstrapTone(?string $color): string
    {
        return self::auroraTone($color);
    }

    /**
     * `_fa_alert` variant of a flash label (success / info / notice / warning / danger / error);
     * unknown → info.
     */
    #[AsTwigFunction('flash_tone')]
    public function getFlashTone(?string $label): string
    {
        return self::FLASH_TO_ALERT[strtolower((string) $label)] ?? 'info';
    }

    #[AsTwigFunction('pill_tone_severity')]
    public function getSeverityTone(string|int|\BackedEnum|null $severity): string
    {
        return StatusVocabulary::severityTone($severity);
    }

    #[AsTwigFunction('pill_tone_status')]
    public function getStatusTone(string|\BackedEnum|null $status): string
    {
        return StatusVocabulary::statusTone($status);
    }

    #[AsTwigFunction('pill_tone_nis2')]
    public function getNis2Tone(string $nis2Status): string
    {
        return self::NIS2_MAP[strtolower($nis2Status)] ?? 'neutral';
    }

    #[AsTwigFunction('pill_tone_action')]
    public function getActionTone(string $action): string
    {
        return StatusVocabulary::tone('audit_action', $action);
    }

    #[AsTwigFunction('pill_tone_classification')]
    public function getClassificationTone(string $classification): string
    {
        return StatusVocabulary::tone('classification', $classification);
    }

    /**
     * Aurora tone for a numeric score (thresholds, not words).
     */
    #[AsTwigFunction('pill_tone_score')]
    public function getScoreTone(int|float $score, int $dangerThreshold = 4, int $warningThreshold = 3): string
    {
        return match (true) {
            $score >= $dangerThreshold => 'danger',
            $score >= $warningThreshold => 'warning',
            $score >= 2 => 'primary',
            default => 'success',
        };
    }

    #[AsTwigFunction('badge_severity')]
    public function getSeverityBadgeClass(string|int|\BackedEnum $severity): string
    {
        return self::classes($this->getSeverityTone($severity));
    }

    #[AsTwigFunction('badge_status')]
    public function getStatusBadgeClass(string|\BackedEnum|null $status): string
    {
        return self::classes($this->getStatusTone($status));
    }

    #[AsTwigFunction('badge_risk')]
    public function getRiskBadgeClass(?string $riskLevel): string
    {
        return self::classes($this->getSeverityTone($riskLevel));
    }

    #[AsTwigFunction('badge_nis2')]
    public function getNis2BadgeClass(string $nis2Status): string
    {
        return self::classes($this->getNis2Tone($nis2Status));
    }

    #[AsTwigFunction('badge_action')]
    public function getActionBadgeClass(string $action): string
    {
        return self::classes($this->getActionTone($action));
    }

    #[AsTwigFunction('badge_classification')]
    public function getClassificationBadgeClass(string $classification): string
    {
        return self::classes($this->getClassificationTone($classification));
    }

    #[AsTwigFunction('badge_score')]
    public function getScoreBadgeClass(int|float $score, int $dangerThreshold = 4, int $warningThreshold = 3): string
    {
        return self::classes($this->getScoreTone($score, $dangerThreshold, $warningThreshold));
    }

    /**
     * Completion percentage: 100 success, ≥ 75 primary, ≥ 50 warning, else danger.
     */
    #[AsTwigFunction('badge_completion')]
    public function getCompletionBadgeClass(int|float $percentage): string
    {
        return self::classes(match (true) {
            $percentage >= 100 => 'success',
            $percentage >= 75 => 'primary',
            $percentage >= 50 => 'warning',
            default => 'danger',
        });
    }

    #[AsTwigFunction('badge_priority')]
    public function getPriorityBadgeClass(?string $priority): string
    {
        return self::classes($this->getSeverityTone($priority));
    }

    private static function classes(string $tone): string
    {
        return 'fa-status-pill fa-status-pill--' . $tone;
    }
}
