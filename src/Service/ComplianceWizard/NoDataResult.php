<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard;

/**
 * "No data recorded" is not evidence: a wizard check whose subject has zero
 * records scores 0 with `details.reason = no_data` and an open gap, never 100.
 */
final class NoDataResult
{
    /**
     * @param array<string, mixed> $check   check config (may override `priority` / `route`)
     * @param array<string, mixed> $details extra details merged after the reason
     * @return array{score: float, details: array<string, mixed>, gap: array<string, mixed>}
     */
    public static function build(string $title, string $route, array $check, array $details = []): array
    {
        return [
            'score' => 0.0,
            'details' => ['reason' => 'no_data'] + $details,
            'gap' => [
                'title' => $title,
                'description' => 'wizard.gap.no_data_desc',
                'priority' => $check['priority'] ?? 'high',
                'route' => $check['route'] ?? $route,
            ],
        ];
    }
}
