<?php

declare(strict_types=1);

namespace App\Tests\Service\Export;

use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ControlRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\Compliance\Fulfillment\FulfillmentRuleEngine;
use App\Service\Compliance\Fulfillment\RequirementEvidence;
use App\Service\Export\EvidenceDocuments;

/**
 * Real evidence chain over empty repositories: a requirement's own evidence
 * list (of the exporting organisation) is all the exports see in these tests.
 */
trait EvidenceDocumentsFactoryTrait
{
    private function evidenceDocuments(?WorkflowInstanceRepository $workflowInstances = null): EvidenceDocuments
    {
        $controls = $this->createStub(ControlRepository::class);
        $controls->method('findByTenant')->willReturn([]);
        $fulfillments = $this->createStub(ComplianceRequirementFulfillmentRepository::class);
        $fulfillments->method('findOneBy')->willReturn(null);
        $engine = new FulfillmentRuleEngine($controls, $this->createStub(ComplianceMappingRepository::class), $fulfillments);

        return new EvidenceDocuments(new RequirementEvidence($engine, $fulfillments), $workflowInstances);
    }
}
