<?php

declare(strict_types=1);

namespace App\Service\GateFixture;

use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\PortfolioSnapshot;

/** Gate 85 fixture (T4: B41 of the counter-check). Every `expect:` line is a mutation the rule must report. */
final class FulfillmentWriters
{
    public function byType(ComplianceRequirementFulfillment $row, PortfolioSnapshot $snapshot): void
    {
        $row->setStatus('compliant'); // expect: gate85.secondWriter
        $row->setFulfillmentPercentage(100); // expect: gate85.secondWriter
        $row->clearOverride(); // expect: gate85.secondWriter
        $row->{'setStatus'}('compliant'); // expect: gate85.secondWriter
        $writer = [$row, 'setFulfillmentPercentage']; // expect: gate85.secondWriter
        $writer(50);
        $row->setFulfillmentNotes('read by people only');
        $snapshot->setFulfillmentPercentage(10);
    }

    public function reviewed(ComplianceRequirementFulfillment $row): void
    {
        // @fulfillment-writer-ok: fixture of a reviewed write
        $row->setStatus('compliant');
    }
}
