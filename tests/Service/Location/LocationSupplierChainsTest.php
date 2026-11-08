<?php

declare(strict_types=1);

namespace App\Tests\Service\Location;

use App\Entity\DoraSubcontractor;
use App\Entity\Supplier;
use App\Service\Location\LocationGeocoder;
use App\Service\Location\LocationLayers;
use App\Service\Location\LocationPostureService;
use App\Service\Location\LocationSupplierChains;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GDPR class per link of a supplier chain (Art. 28(4), 44–49) and the
 * third-country layer of the sites.
 */
final class LocationSupplierChainsTest extends TestCase
{
    private LocationGeocoder $geocoder;
    private LocationSupplierChains $chains;

    protected function setUp(): void
    {
        $this->geocoder = new LocationGeocoder(dirname(__DIR__, 3) . '/fixtures/geo');
        $this->chains = new LocationSupplierChains($this->createStub(EntityManagerInterface::class), $this->geocoder);
    }

    #[Test]
    public function thirdCountryLinkWithoutSafeguardIsTheWorstLink(): void
    {
        $supplier = (new Supplier())->setName('Cloud AG')
            ->setProcessingLocations(['Deutschland', 'USA']);
        self::sub($supplier, 3, 'Support Ltd', 'IN', 'Helpdesk');
        self::sub($supplier, 2, 'Hosting GmbH', 'CH');
        self::sub($supplier, 2, '');

        $row = $this->chains->chain($supplier);

        self::assertSame(['DE', 'US'], array_column($row['locations'], 'code'));
        self::assertSame(['ok', 'warn'], array_column($row['locations'], 'band'), 'USA under the DPF needs a safeguard check');
        self::assertSame(['Hosting GmbH', 'Support Ltd'], array_column($row['chain'], 'name'), 'sorted by tier, blank entries dropped');
        self::assertSame(['ok', 'danger'], array_column($row['chain'], 'band'));
        self::assertSame('danger', $row['band']);
    }

    #[Test]
    public function safeguardLowersTheThirdCountryBand(): void
    {
        $supplier = (new Supplier())->setName('Cloud AG')->setTransferSafeguards('scc')
            ->setCountryOfHeadOffice('USA');
        self::sub($supplier, 2, 'Support Ltd', 'IN');

        $row = $this->chains->chain($supplier);

        self::assertSame('ok', $row['locations'][0]['band'], 'head office used when no processing location is set');
        self::assertSame('warn', $row['chain'][0]['band']);
        self::assertSame('warn', $row['band']);
        self::assertSame('scc', $row['safeguard']);
    }

    #[Test]
    public function sitesAreClassifiedByTransferZone(): void
    {
        $layers = new LocationLayers(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(LocationPostureService::class),
            $this->geocoder,
        );

        $out = $layers->thirdCountry([
            ['id' => 1, 'country' => 'Deutschland'],
            ['id' => 2, 'country' => 'China'],
            ['id' => 3, 'country' => 'USA'],
            ['id' => 4, 'country' => null],
        ]);

        self::assertSame(['band' => 'ok', 'zone' => 'eea', 'code' => 'DE'], $out[1]);
        self::assertSame('warn', $out[2]['band']);
        self::assertSame('dpf', $out[3]['zone']);
        self::assertSame('none', $out[4]['band']);
    }

    /** Subcontractors are DoraSubcontractor rows (P5: one list). */
    private static function sub(Supplier $supplier, int $tier, string $name, ?string $country = null, ?string $service = null): void
    {
        $supplier->getSubcontractors()->add((new DoraSubcontractor())->setParentSupplier($supplier)->setTier($tier)
            ->setName($name)->setCountry($country)->setServiceDescription($service));
    }
}
