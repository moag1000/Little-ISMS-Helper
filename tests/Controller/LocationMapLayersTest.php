<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Asset;
use App\Entity\BusinessProcess;
use App\Entity\InternalAudit;
use App\Entity\Location;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Location map PR 3: emergency and audit layers on the site pins, the group
 * view across the holding's own subtree only, event mode by country and the
 * audit prefill from a site.
 */
final class LocationMapLayersTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uid;
    /** @var list<object> in creation order; removed in reverse */
    private array $created = [];
    private Tenant $holding;
    private User $user;
    private Location $site;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = uniqid('lay_', false);

        $this->holding = $this->keep((new Tenant())->setName('Holding ' . $this->uid)->setCode('h_' . $this->uid));
        $subsidiary = $this->keep((new Tenant())->setName('Tochter ' . $this->uid)->setCode('t_' . $this->uid)->setParent($this->holding));
        $this->holding->addSubsidiary($subsidiary);
        $stranger = $this->keep((new Tenant())->setName('Fremd ' . $this->uid)->setCode('f_' . $this->uid));

        $this->user = $this->keep((new User())->setEmail($this->uid . '@example.com')->setFirstName('Lay')->setLastName('Er')
            ->setRoles(['ROLE_MANAGER', 'ROLE_AUDITOR', 'ROLE_GROUP_CISO'])->setPassword('x')
            ->setTenant($this->holding)->setIsActive(true));

        $this->site = $this->keep((new Location())->setTenant($this->holding)->setName('Werk ' . $this->uid)
            ->setLocationType('building')->setCity('München')->setCountry('Deutschland'));
        $this->keep((new Location())->setTenant($subsidiary)->setName('Lager ' . $this->uid)
            ->setLocationType('building')->setCity('Wien')->setCountry('Österreich'));
        $this->keep((new Location())->setTenant($stranger)->setName('Außen ' . $this->uid)
            ->setLocationType('building')->setCity('Zürich')->setCountry('Schweiz'));

        $asset = $this->keep((new Asset())->setName('ERP ' . $this->uid)->setAssetType('software')->setOwner('IT')
            ->setStatus('active')->setTenant($this->holding)->setConfidentialityValue(3)
            ->setIntegrityValue(3)->setAvailabilityValue(4)->setPhysicalLocation($this->site));
        $process = new BusinessProcess();
        $process->setTenant($this->holding);
        $process->setName('Auftragsabwicklung ' . $this->uid);
        $process->setDescription('d');
        $process->setCriticality('critical');
        $process->setRto(4);
        $process->setRpo(2);
        $process->setMtpd(24);
        $process->setProcessOwner('Vertrieb');
        $process->setReputationalImpact(3);
        $process->setRegulatoryImpact(2);
        $process->setOperationalImpact(4);
        $process->addSupportingAsset($asset);
        $this->keep($process);

        $audit = new InternalAudit();
        $audit->setTenant($this->holding);
        $audit->setAuditNumber('L-' . $this->uid);
        $audit->setTitle('Standortaudit ' . $this->uid);
        $audit->setPlannedDate(new \DateTime('-2 months'));
        $audit->setActualDate(new \DateTime('-2 months'));
        $audit->setStatus('conducted'); // @phpstan-ignore lifecycle.directSetStatus (test fixture)
        $audit->setLocation($this->site);
        $this->keep($audit);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
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
    public function emergencyAndAuditLayersRateTheSite(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/en/location/map?lens=bcm');

        self::assertResponseIsSuccessful();
        $layers = json_decode((string) $crawler->filter('[data-controller="location-map"]')->attr('data-location-map-layers-value'), true);
        self::assertContains('emergency', $layers);
        self::assertContains('audit', $layers);

        $site = $this->ownSite($crawler->filter('[data-controller="location-map"]')->attr('data-location-map-sites-value'));
        self::assertSame('danger', $site['bands']['emergency'], 'critical process without a BC plan');
        self::assertSame('missing', $site['layers']['emergency']['processes'][0]['state']);
        self::assertSame('ok', $site['bands']['audit'], 'audited two months ago');
        self::assertSame('ok', $site['bands']['thirdcountry']);
    }

    #[Test]
    public function groupViewShowsOwnSubtreeOnly(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/en/location/map?lens=group');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Lager ' . $this->uid, $html);
        self::assertStringContainsString('Tochter ' . $this->uid, $html);
        self::assertStringNotContainsString('Außen ' . $this->uid, $html);
    }

    #[Test]
    public function groupLensNeedsAHoldingRole(): void
    {
        $this->user->setRoles(['ROLE_MANAGER']);
        $this->em->flush();
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/en/location/map?lens=group');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Lager ' . $this->uid, (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function eventModeListsTheAffectedProcesses(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/en/location/map?lens=bcm&event=DE');

        self::assertResponseIsSuccessful();
        $facts = $crawler->filter('.location-map__event-facts')->text();
        self::assertStringContainsString('Werk ' . $this->uid, $facts);
        self::assertStringContainsString('Auftragsabwicklung ' . $this->uid, $facts);
    }

    #[Test]
    public function auditFormIsPrefilledFromTheSite(): void
    {
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/en/audit/new?fromLocation=' . $this->site->getId());

        self::assertResponseIsSuccessful();
        $selected = $crawler->filter('select[name$="[location]"] option[selected]');
        self::assertCount(1, $selected);
        self::assertSame((string) $this->site->getId(), $selected->attr('value'));
        self::assertStringContainsString('Werk ' . $this->uid, (string) $crawler->filter('input[name$="[title]"]')->attr('value'));
    }

    /** @return array<string, mixed> */
    private function ownSite(?string $json): array
    {
        foreach (json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR) as $site) {
            if ($site['id'] === $this->site->getId()) {
                return $site;
            }
        }
        self::fail('own site missing on the map');
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function keep(object $entity): object
    {
        $this->em->persist($entity);
        $this->created[] = $entity;

        return $entity;
    }
}
