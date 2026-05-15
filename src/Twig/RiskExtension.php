<?php

declare(strict_types=1);

namespace App\Twig;

use App\Risk\RiskMatrixThresholds;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig Extension for risk-matrix presentation helpers.
 *
 * Exposes the band-thresholds from {@see RiskMatrixThresholds} (SSoT)
 * to templates so views never have to hard-code "20-25" / "12-19" etc.
 * — those strings used to drift between yaml/twig/service (Audit P-11).
 */
final class RiskExtension
{
    /**
     * Render the score-range for a band, e.g. risk_threshold('critical') → "20–25".
     *
     * Empty string for unknown levels.
     */
    #[AsTwigFunction('risk_threshold')]
    public function riskThreshold(string $level): string
    {
        $bands = RiskMatrixThresholds::getBands();
        if (!isset($bands[$level])) {
            return '';
        }

        return sprintf('%d–%d', $bands[$level]['min'], $bands[$level]['max']);
    }
}
