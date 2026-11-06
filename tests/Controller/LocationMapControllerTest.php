<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Asset;
use App\Entity\Location;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Service\Location\LocationGeocoder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Location map: posture per site, tenant isolation, geocoding on save,
 * CSRF on the store action and the ?location= drill-down filter.
 */
final class LocationMapControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uid;
    private Tenant $tenant;
    private Tenant $foreignTenant;
    private User $user;
    private Location $site;
    private Location $room;
    private Location $foreignSite;
    private Asset $asset;
    private Risk $risk;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = uniqid('map_', false);

        $this->tenant = (new Tenant())->setName('Map ' . $this->uid)->setCode('map_' . $this->uid);
        $this->foreignTenant = (new Tenant())->setName('Foreign ' . $this->uid)->setCode('foreign_' . $this->uid);
        $this->em->persist($this->tenant);
        $this->em->persist($this->foreignTenant);

        $this->user = (new User())->setEmail('map_' . $this->uid . '@example.com')->setFirstName('Map')
            ->setLastName('Tester')->setRoles(['ROLE_MANAGER'])->setPassword('x')->setTenant($this->tenant)->setIsActive(true);
        $this->em->persist($this->user);

        $this->site = (new Location())->setTenant($this->tenant)->setName('Werk ' . $this->uid)
            ->setLocationType('building')->setPostalCode('80331')->setCity('München')->setCountry('Deutschland')
            ->setSecurityLevel('secure')->setResponsiblePersonUser($this->user);
        $this->room = (new Location())->setTenant($this->tenant)->setName('Halle ' . $this->uid)
            ->setLocationType('room')->setParentLocation($this->site)->setSecurityLevel('restricted');
        $this->foreignSite = (new Location())->setTenant($this->foreignTenant)->setName('Fremd ' . $this->uid)
            ->setLocationType('building')->setCity('Wien')->setCountry('AT');
        foreach ([$this->site, $this->room, $this->foreignSite] as $location) {
            $this->em->persist($location);
        }

        $this->asset = (new Asset())->setName('SPS ' . $this->uid)->setAssetType('hardware')->setOwner('OT')
            ->setStatus('active')->setTenant($this->tenant)->setConfidentialityValue(2)
            ->setIntegrityValue(4)->setAvailabilityValue(3)->setPhysicalLocation($this->room);
        $this->em->persist($this->asset);

        $this->risk = (new Risk())->setTitle('Ungesicherter Zugang ' . $this->uid)->setCategory('security')
            ->setDescription('d')->setThreat('t')->setVulnerability('v')->setAsset($this->asset)
            ->setProbability(5)->setLikelihoodJustification('l')->setImpact(4)->setImpactJustification('i')
            ->setResidualProbability(2)->setResidualImpact(2)->setTreatmentStrategy(TreatmentStrategy::Mitigate)
            ->setStatus(RiskStatus::Identified)->setRiskOwner($this->user)->setTenant($this->tenant);
        $this->em->persist($this->risk);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ([$this->risk, $this->asset, $this->room, $this->site, $this->foreignSite, $this->user, $this->tenant, $this->foreignTenant] as $entity) {
            try {
                $managed = $this->em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $this->em->remove($managed);
                    $this->em->flush();
                }
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function savingALocationPlacesItFromThePostalCode(): void
    {
        $this->em->clear();
        $site = $this->em->find(Location::class, $this->site->getId());

        self::assertSame(LocationGeocoder::SOURCE_POSTAL, $site->getGeoSource());
        self::assertEqualsWithDelta(48.14, $site->getLatitude(), 0.05);
    }

    #[Test]
    public function manualCoordinatesSurviveAnAddressChange(): void
    {
        $site = $this->em->find(Location::class, $this->site->getId());
        $site->setLatitude(48.2)->setLongitude(11.6);
        $this->em->flush();
        self::assertSame(LocationGeocoder::SOURCE_MANUAL, $site->getGeoSource());

        $site->setPostalCode('60311')->setCity('Frankfurt am Main');
        $this->em->flush();
        self::assertSame(48.2, $site->getLatitude());

        // Clearing the coordinates hands the site back to the geocoder.
        $site->setLatitude(null)->setLongitude(null);
        $this->em->flush();
        self::assertSame(LocationGeocoder::SOURCE_POSTAL, $site->getGeoSource());
        self::assertEqualsWithDelta(50.11, $site->getLatitude(), 0.05);
    }

    #[Test]
    public function mapShowsOwnSitesWithPostureOnly(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/en/location/map');

        self::assertResponseIsSuccessful();
        $json = $crawler->filter('[data-controller="location-map"]')->attr('data-location-map-sites-value');
        $sites = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(1, $sites, 'only the top-level site of the own tenant');
        $site = $sites[0];
        self::assertSame($this->site->getId(), $site['id']);
        self::assertSame(4, $site['protection'], 'max of C/I/A of assets in the site tree');
        self::assertSame(20, $site['score'], 'unhandled risk counts with its inherent score');
        self::assertSame('critical', $site['riskBand']);
        self::assertTrue($site['mine']);
        self::assertContains('risks_treated', $site['gaps']);
        self::assertContains('camera', $site['gaps']);
        self::assertNotContains('owner', $site['gaps']);
        self::assertStringNotContainsString('Fremd ' . $this->uid, (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function storeActionRequiresCsrfToken(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/en/location/map/geocode');

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function riskListFiltersByLocation(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/en/risk?location=' . $this->site->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($this->risk->getTitle(), (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/en/risk?location=' . $this->foreignSite->getId());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($this->risk->getTitle(), (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function bulkActionRequiresCsrfToken(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/en/location/map/bulk', [
            'target' => 'owner:' . $this->user->getId(),
            'ids' => [$this->room->getId()],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function bulkOwnerAssignmentStaysInsideTheOwnTenant(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/en/location/map');
        $token = $crawler->filter('#location-map-selection input[name="_token"]')->attr('value');

        $this->client->request('POST', '/en/location/map/bulk', [
            '_token' => $token,
            'target' => 'owner:' . $this->user->getId(),
            'ids' => [$this->room->getId(), $this->foreignSite->getId()],
        ]);
        self::assertResponseRedirects();

        $this->em->clear();
        self::assertSame($this->user->getId(), $this->em->find(Location::class, $this->room->getId())->getResponsiblePersonUser()?->getId());
        self::assertNull($this->em->find(Location::class, $this->foreignSite->getId())->getResponsiblePersonUser());
    }

    #[Test]
    public function exportsTheSituationReportAsExcelAndPdf(): void
    {
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/en/location/map/export.xlsx');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->client->request('GET', '/en/location/map/export.pdf?ids[]=' . $this->site->getId());
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
    }

    #[Test]
    public function locationPageShowsTheSiteProfile(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/en/location/' . $this->room->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#profile'));
        self::assertStringContainsString('Werk ' . $this->uid, $crawler->filter('#profile')->ancestors()->first()->text());
    }

    #[Test]
    public function myDayListsOwnSitesWithOpenChecks(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/en/my-day');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Werk ' . $this->uid, (string) $this->client->getResponse()->getContent());
    }
}
