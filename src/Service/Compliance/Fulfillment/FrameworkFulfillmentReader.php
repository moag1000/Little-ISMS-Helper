<?php

declare(strict_types=1);

namespace App\Service\Compliance\Fulfillment;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;

/**
 * The organisation's fulfilment of a framework's requirements by number, for
 * views that show a norm's articles as tiles (NIS-2 Art. 21 (2) letters, …):
 * they show the one value the rule derived (or the override), never an
 * auxiliary figure of their own.
 */
final class FrameworkFulfillmentReader
{
    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworks,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillments,
    ) {
    }

    /**
     * @return array<string, ComplianceRequirementFulfillment> normalised number ("21.2.a") → fulfilment
     */
    public function byNumber(Tenant $tenant, string $frameworkCode): array
    {
        $framework = $this->frameworks->findOneBy(['code' => $frameworkCode]);
        if (!$framework instanceof ComplianceFramework) {
            return [];
        }

        $result = [];
        foreach ($this->fulfillments->findByFrameworkAndTenant($framework, $tenant) as $fulfillment) {
            $number = RecordCheckRule::requirementNumber($frameworkCode, (string) $fulfillment->getRequirement()?->getRequirementId());
            if ($number !== '') {
                $result[$number] ??= $fulfillment;
            }
        }

        return $result;
    }
}
