<?php

declare(strict_types=1);

namespace App\Service\PolicyParameter;

use App\Entity\OrganizationSecurityProfile;

/**
 * Resolves the effective value of a policy parameter through the layered chain
 * override -> tenant-profile -> industry-baseline -> catalog-default.
 */
final readonly class PolicyParameterResolver
{
    public function __construct(
        private PolicyParameterCatalog $catalog,
    ) {
    }

    /**
     * @param array<string, mixed> $baseline industry-baseline preset values
     * @param array<string, mixed> $override per-run WizardRun override values
     */
    public function resolve(
        string $key,
        ?OrganizationSecurityProfile $profile,
        array $baseline = [],
        array $override = [],
    ): mixed {
        if (array_key_exists($key, $override)) {
            return $override[$key];
        }

        $profileValue = $profile?->getValue($key);
        if ($profileValue !== null) {
            return $profileValue;
        }

        if (array_key_exists($key, $baseline)) {
            return $baseline[$key];
        }

        return $this->catalog->get($key)->default;
    }
}
