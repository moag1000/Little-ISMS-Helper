<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Admin\AdminHubCatalog;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the live total-module count of the AdminHubCatalog to Twig so
 * surfaces that advertise the catalogue size (mega-menu subtitle, dashboard
 * tiles, README badges) stay in sync without manual edits.
 *
 * Returns the count from the static catalogue definition — no DB roundtrip,
 * no router lookup, no auth bypass.
 */
final class AdminHubExtension extends AbstractExtension
{
    private ?int $cached = null;

    public function __construct(
        private readonly AdminHubCatalog $catalog,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_hub_total_modules', $this->totalModules(...)),
        ];
    }

    public function totalModules(): int
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        $sum = 0;
        foreach ($this->catalog->getGroups() as $group) {
            $sum += count($group['modules']);
        }
        return $this->cached = $sum;
    }
}
