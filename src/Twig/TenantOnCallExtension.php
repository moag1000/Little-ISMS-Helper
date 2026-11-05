<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFunction;

/**
 * Resolves the current entry of the tenant's crisis-team on-call rota
 * (Tenant::getCrisisTeamOnCall(), edited in the compliance settings).
 */
final class TenantOnCallExtension
{
    /**
     * The rota entry that is in force on $now: the latest entry whose date is
     * today or earlier. Falls back to null when the rota is empty or only has
     * future entries.
     *
     * @param array<int, array{date?: string, primary?: string, deputy?: string}>|null $rota
     * @return array{date: string, primary?: string, deputy?: string}|null
     */
    #[AsTwigFunction('current_on_call')]
    public function currentOnCall(?array $rota, ?\DateTimeInterface $now = null): ?array
    {
        $today = ($now ?? new \DateTimeImmutable())->format('Y-m-d');
        $current = null;
        foreach ($rota ?? [] as $entry) {
            $date = $entry['date'] ?? null;
            if (!\is_string($date) || $date === '' || $date > $today) {
                continue;
            }
            if ($current === null || $date >= $current['date']) {
                $current = $entry + ['date' => $date];
            }
        }
        if ($current === null || (($current['primary'] ?? '') === '' && ($current['deputy'] ?? '') === '')) {
            return null;
        }

        return $current;
    }
}
