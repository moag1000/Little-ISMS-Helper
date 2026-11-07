<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard;

use App\Entity\Tenant;

/**
 * The compliance wizard's check catalogue and its data checks, as the
 * fulfilment rule reads them ({@see \App\Service\Compliance\Fulfillment\RecordCheckRule}).
 */
interface WizardCheckSource
{
    /**
     * Every wizard with its categories and checks, before module filtering.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getWizardDefinitions(): array;

    /**
     * Result of a check that reads the organisation's records, null for a manual check.
     *
     * @param array<string, mixed> $check
     * @return array<string, mixed>|null
     */
    public function runDataCheck(array $check, Tenant $tenant): ?array;
}
