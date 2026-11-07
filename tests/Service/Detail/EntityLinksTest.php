<?php

declare(strict_types=1);

namespace App\Tests\Service\Detail;

use App\Entity\Asset;
use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Incident;
use App\Entity\Location;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Entity\RiskTreatmentPlan;
use App\Repository\RiskRepository;
use App\Service\Detail\EntityLinks;
use App\Service\ModuleConfigurationService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EntityLinksTest extends TestCase
{
    private function service(array $inactive = []): EntityLinks
    {
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActive')->willReturnCallback(static fn (string $key): bool => !in_array($key, $inactive, true));

        return new EntityLinks($modules, $this->createStub(RiskRepository::class));
    }

    private function risk(): Risk
    {
        $asset = $this->createStub(Asset::class);
        $asset->method('getId')->willReturn(7);
        $asset->method('getName')->willReturn('Kundenportal');
        $asset->method('getConfidentialityValue')->willReturn(4);

        $document = $this->createStub(Document::class);
        $document->method('getId')->willReturn(3);
        $document->method('getOriginalFilename')->willReturn('pentest.pdf');

        $control = $this->createStub(Control::class);
        $control->method('getId')->willReturn(11);
        $control->method('getControlId')->willReturn('8.22');
        $control->method('getName')->willReturn('Netzwerktrennung');
        $control->method('getImplementationStatus')->willReturn('implemented');
        $control->method('getEvidenceDocuments')->willReturn(new ArrayCollection([$document]));

        $incident = $this->createStub(Incident::class);
        $incident->method('getId')->willReturn(5);
        $incident->method('getTitle')->willReturn('Phishing');

        $risk = $this->createStub(Risk::class);
        $risk->method('getId')->willReturn(42);
        $risk->method('getAsset')->willReturn($asset);
        $risk->method('getIctAssetDependency')->willReturn(new ArrayCollection([$asset]));
        $risk->method('getControls')->willReturn(new ArrayCollection([$control]));
        $risk->method('getIncidents')->willReturn(new ArrayCollection([$incident]));

        return $risk;
    }

    #[Test]
    public function riskShowsTheChainInOrderWithEvidenceThroughItsControls(): void
    {
        $groups = $this->service()->for($this->risk());

        self::assertSame(['asset', 'control', 'evidence', 'incident', 'vulnerability'], array_column($groups, 'type'));
        $byType = array_column($groups, null, 'type');

        self::assertSame(1, $byType['asset']['total'], 'primary asset and ICT dependency are the same record');
        self::assertSame(['CIA 4/5', 'danger'], [$byType['asset']['items'][0]['meta'], $byType['asset']['items'][0]['tone']]);
        self::assertSame('8.22', $byType['control']['items'][0]['code']);
        self::assertSame('success', $byType['control']['items'][0]['tone']);
        self::assertSame('control', $byType['evidence']['via']);
        self::assertSame('pentest.pdf', $byType['evidence']['items'][0]['label']);
        self::assertSame(['route' => 'app_incident_new', 'params' => ['fromRisk' => 42], 'anchor' => null], $byType['incident']['derive']);
        self::assertSame(0, $byType['vulnerability']['total']);
    }

    #[Test]
    public function groupsOfInactiveModulesAreLeftOut(): void
    {
        $groups = $this->service(['vulnerability_intel', 'documents'])->for($this->risk());

        self::assertSame(['asset', 'control', 'incident'], array_column($groups, 'type'));
    }

    #[Test]
    public function unknownRecordsHaveNoChain(): void
    {
        self::assertSame([], $this->service()->for(new \stdClass()));
    }

    #[Test]
    public function aSupplierShowsOnlyTheChainTypesItCanRelateTo(): void
    {
        $asset = $this->createStub(Asset::class);
        $asset->method('getId')->willReturn(7);
        $asset->method('getName')->willReturn('Kundenportal');

        $supplier = $this->createStub(Supplier::class);
        $supplier->method('getId')->willReturn(9);
        $supplier->method('getSupportedAssets')->willReturn(new ArrayCollection([$asset]));
        $supplier->method('getIdentifiedRisks')->willReturn(new ArrayCollection());
        $supplier->method('getDocuments')->willReturn(new ArrayCollection());

        $groups = $this->service()->for($supplier);

        self::assertSame(['asset', 'risk', 'evidence'], array_column($groups, 'type'));
        self::assertSame(0, $groups[1]['total'], 'a supplier without risks shows the gap');
        self::assertNull($groups[0]['derive']);
    }

    #[Test]
    public function aTreatmentPlanLinksItsRiskAndControls(): void
    {
        $risk = $this->risk();
        $plan = $this->createStub(RiskTreatmentPlan::class);
        $plan->method('getId')->willReturn(4);
        $plan->method('getRisk')->willReturn($risk);
        $plan->method('getControls')->willReturn($risk->getControls());
        $plan->method('getEvidenceDocuments')->willReturn(new ArrayCollection());

        $groups = $this->service()->for($plan);

        self::assertSame(['risk', 'control', 'evidence'], array_column($groups, 'type'));
        self::assertSame(42, $groups[0]['items'][0]['id']);
    }

    #[Test]
    public function locationOffersRiskDerivedFromTheSite(): void
    {
        $location = $this->createStub(Location::class);
        $location->method('getId')->willReturn(9);
        $location->method('getAssets')->willReturn(new ArrayCollection());

        $groups = array_column($this->service()->for($location), null, 'type');

        self::assertSame(['asset', 'risk'], array_keys($groups));
        self::assertSame(['route' => 'app_risk_new', 'params' => ['fromLocation' => 9], 'anchor' => null], $groups['risk']['derive']);
    }
}
