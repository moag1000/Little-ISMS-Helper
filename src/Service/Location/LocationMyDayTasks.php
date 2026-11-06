<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\User;
use App\Service\ModuleConfigurationService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * My Day items for site owners: one item per own site with open checks
 * (ISO 27001 A.5.2, A.7). Same checks as the location map, so the owner sees
 * the gap where they start the day instead of only on the map.
 */
readonly class LocationMyDayTasks
{
    public function __construct(
        private LocationPostureService $posture,
        private ModuleConfigurationService $modules,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function openGapsFor(User $user): array
    {
        $tenant = $user->getTenant();
        if ($tenant === null || !$this->modules->isModuleActive('locations')) {
            return [];
        }

        $items = [];
        foreach ($this->posture->forTenant($tenant, $user) as $site) {
            if (!$site['mine'] || !$site['active'] || $site['gaps'] === []) {
                continue;
            }
            $items[] = [
                'priority' => $site['gapBand'] === 'danger' ? 'high' : 'medium',
                'due_date' => null,
                'link' => $this->urls->generate('app_location_show', ['id' => $site['id']]) . '#profile',
                'entity_type' => 'location',
                'tone' => $site['gapBand'] === 'danger' ? 'danger' : 'warning',
                'title' => $site['name'],
                'subtitle' => implode(', ', array_map(
                    fn (string $check): string => $this->translator->trans('location.map.check.' . $check, [], 'locations')
                        . ' (' . LocationPostureService::CHECK_NORM[$check] . ')',
                    $site['gaps'],
                )),
                'badge' => null,
            ];
        }

        return $items;
    }
}
