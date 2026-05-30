<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

use App\Entity\OrganizationSecurityProfile;

/**
 * Pre-fills a tenant profile from a sector baseline: sets the sector key, copies
 * org-context flags, and fills any parameter the profile has not already set
 * explicitly (existing explicit values are never overwritten).
 */
final readonly class PolicyBaselineApplier
{
    public function __construct(
        private PolicyBaselineCatalog $baselines,
    ) {
    }

    public function apply(string $sector, OrganizationSecurityProfile $profile): void
    {
        $baseline = $this->baselines->get($sector);
        $profile->setSectorKey($sector);

        foreach ($baseline->flags as $flag => $value) {
            if (\is_bool($value)) {
                $profile->setFlag($flag, $value);
            }
        }

        foreach ($baseline->presetValues() as $key => $value) {
            if ($profile->getValue($key) === null) {
                $profile->setValue($key, $value);
            }
        }
    }
}
