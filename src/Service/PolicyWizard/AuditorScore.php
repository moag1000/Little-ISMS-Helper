<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

/**
 * Policy-Wizard — DTO for {@see AuditorScoreCalculator} results.
 *
 * Tier follows the green/yellow/red traffic-light pattern; reasons
 * are machine-readable codes that the Twig layer translates into
 * human-readable bullets via the
 * `policy_wizard.auditor_score.reason.<code>` translation keys.
 */
final readonly class AuditorScore
{
    public const string TIER_GREEN = 'green';
    public const string TIER_YELLOW = 'yellow';
    public const string TIER_RED = 'red';

    /**
     * @param self::TIER_* $tier
     * @param int<0, 100>  $score
     * @param list<string> $reasons machine-readable reason codes; the
     *     Twig layer maps them to translation keys for display.
     */
    public function __construct(
        public string $tier,
        public int $score,
        public array $reasons,
    ) {
    }
}
