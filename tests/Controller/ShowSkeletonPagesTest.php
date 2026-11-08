<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Asset;
use App\Entity\Control;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\IncidentSeverity;
use App\Enum\IncidentStatus;
use App\Enum\RiskStatus;
use App\Service\ModuleConfigurationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UI wave E in the real app: the detail pages of Risk, Asset, Control and
 * Incident share one skeleton (status band, anchor bar, links block first,
 * history and comments last), and the links block shows the evidence chain
 * from the relations the records already have.
 */
final class ShowSkeletonPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private User $user;
    /** @var array<string, object> */
    private array $records = [];
    private string $uid;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $modules = static::getContainer()->get(ModuleConfigurationService::class);

        $this->uid = substr(uniqid('', true), -8);
        $this->tenant = (new Tenant())->setName('Show ' . $this->uid)->setCode('show_' . $this->uid);
        $this->tenant->setActiveModules(array_values(array_unique([
            ...$modules->getInstanceDefaultModules(), 'risks', 'assets', 'controls', 'incidents', 'documents', 'suppliers',
        ])));
        $this->em->persist($this->tenant);

        $this->user = new User();
        $this->user->setEmail('show_' . $this->uid . '@example.com');
        $this->user->setFirstName('Iris');
        $this->user->setLastName('Isb');
        $this->user->setRoles(['ROLE_ADMIN']);
        $this->user->setPassword('hashed_password');
        $this->user->setTenant($this->tenant);
        $this->user->setIsActive(true);
        $this->em->persist($this->user);

        $asset = (new Asset())
            ->setName('Kundenportal ' . $this->uid)
            ->setAssetType('application')
            ->setDescription('Skeleton fixture.')
            ->setTenant($this->tenant)
            ->setConfidentialityValue(4)
            ->setIntegrityValue(3)
            ->setAvailabilityValue(3);
        $this->em->persist($asset);

        $control = new Control();
        $control->setTenant($this->tenant);
        $control->setControlId('8.22');
        $control->setName('Netzwerktrennung ' . $this->uid);
        $control->setDescription('Skeleton fixture.');
        $control->setCategory('technological');
        $control->setApplicable(true);
        $control->setImplementationStatus('in_progress');
        $control->setImplementationPercentage(60);
        $control->addProtectedAsset($asset);
        $this->em->persist($control);

        $risk = new Risk();
        $risk->setTenant($this->tenant);
        $risk->setTitle('Datenabfluss Kundenportal ' . $this->uid);
        $risk->setCategory('operational');
        $risk->setDescription('Skeleton fixture.');
        $risk->setProbability(4);
        $risk->setImpact(4);
        $risk->setResidualProbability(3);
        $risk->setResidualImpact(4);
        $risk->setStatus(RiskStatus::Assessed);
        $risk->setCreatedAt(new DateTimeImmutable());
        $risk->setAsset($asset);
        $risk->addControl($control);
        $this->em->persist($risk);

        $incident = new Incident();
        $incident->setTenant($this->tenant);
        $incident->setTitle('Phishing Kundenportal ' . $this->uid);
        $incident->setDescription('Skeleton fixture.');
        $incident->setCategory('security');
        $incident->setStatus(IncidentStatus::Reported);
        $incident->setSeverity(IncidentSeverity::High);
        $incident->setIncidentNumber('INC-SK-' . $this->uid);
        $incident->addAffectedAsset($asset);
        $incident->addRealizedRisk($risk);
        $this->em->persist($incident);

        $supplier = new Supplier();
        $supplier->setTenant($this->tenant);
        $supplier->setName('Hoster ' . $this->uid);
        $supplier->setEmail('hoster_' . $this->uid . '@example.com');
        $supplier->setCriticality('high');
        $supplier->setServiceProvided('Hosting Kundenportal');
        $supplier->addSupportedAsset($asset);
        $this->em->persist($supplier);

        $this->em->flush();
        $this->records = ['asset' => $asset, 'control' => $control, 'risk' => $risk, 'incident' => $incident, 'supplier' => $supplier];
        // Pages read the inverse sides (asset → risks, risk → incidents) from the database.
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        foreach (['supplier', 'incident', 'risk', 'control', 'asset'] as $key) {
            $record = isset($this->records[$key]) ? $this->em->find($this->records[$key]::class, $this->records[$key]->getId()) : null;
            if ($record !== null) {
                try {
                    $this->em->remove($record);
                    $this->em->flush();
                } catch (\Throwable) {
                    // best effort: audit rows written during the request may block
                }
            }
        }
        $tenantId = $this->tenant?->getId();
        if ($tenantId !== null && $this->em->isOpen()) {
            foreach (['DELETE FROM users WHERE tenant_id = ?', 'DELETE FROM tenant WHERE id = ?'] as $sql) {
                try {
                    $this->em->getConnection()->executeStatement($sql, [$tenantId]);
                } catch (\Throwable) {
                }
            }
        }
        $this->tenant = null;

        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, array<string, string>}> page => [record, url prefix, group => linked record] */
    public static function pages(): iterable
    {
        yield 'risk' => ['risk', '/de/risk/', ['asset' => 'asset', 'control' => 'control', 'incident' => 'incident']];
        yield 'asset' => ['asset', '/de/asset/', ['risk' => 'risk', 'control' => 'control', 'incident' => 'incident']];
        yield 'control' => ['control', '/de/soa/', ['asset' => 'asset', 'risk' => 'risk']];
        yield 'incident' => ['incident', '/de/incident/', ['asset' => 'asset', 'risk' => 'risk']];
    }

    /** @param array<string, string> $groups */
    #[Test]
    #[DataProvider('pages')]
    public function detailPageUsesTheSkeletonAndShowsTheChain(string $record, string $prefix, array $groups): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', $prefix . $this->records[$record]->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="status-band"]');
        self::assertSelectorExists('[data-testid="anchor-bar"] a[href="#links"]');
        self::assertSelectorExists('#links[data-testid="entity-links"]');
        self::assertSelectorExists('#comments');
        self::assertSelectorNotExists('.entity-link-matrix');

        foreach ($groups as $group => $linked) {
            $label = method_exists($this->records[$linked], 'getTitle')
                ? $this->records[$linked]->getTitle()
                : $this->records[$linked]->getName();
            self::assertSelectorTextContains('[data-testid="links-' . $group . '"]', (string) $label);
        }
    }

    #[Test]
    public function riskPageOffersToRecordAnIncidentFromTheRisk(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/de/risk/' . $this->records['risk']->getId());

        self::assertSelectorExists('[data-testid="links-incident"] a[href*="fromRisk=' . $this->records['risk']->getId() . '"]');
        self::assertSelectorTextContains('[data-testid="links-evidence"]', 'Keine Verknüpfung.');
        self::assertSelectorTextContains('[data-testid="status-band"]', 'Restrisiko');
    }

    #[Test]
    public function aSupplierPageShowsItsPartOfTheChainAndItsHistory(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/de/supplier/' . $this->records['supplier']->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="status-band"]');
        self::assertSelectorTextContains('[data-testid="links-asset"]', 'Kundenportal ' . $this->uid);
        self::assertSelectorTextContains('[data-testid="links-risk"]', 'Keine Verknüpfung.');
        self::assertSelectorNotExists('[data-testid="links-control"]');
        self::assertSelectorExists('#history');
    }
}
