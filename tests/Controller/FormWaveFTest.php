<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Asset;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use App\Service\ModuleConfigurationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UI wave F in the real app: context prefill on /new forms (same tenant
 * only), large forms open only their core, empty legacy free-text person
 * fields are gone, and every mapping screen carries the hub tabs.
 */
final class FormWaveFTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<Tenant> */
    private array $tenants = [];
    private User $user;
    private Asset $asset;
    private Asset $foreignAsset;
    private Risk $risk;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $modules = static::getContainer()->get(ModuleConfigurationService::class);
        $active = array_values(array_unique([
            ...$modules->getInstanceDefaultModules(),
            'risks', 'assets', 'controls', 'incidents', 'documents', 'compliance', 'risk_treatment_plans', 'suppliers',
        ]));

        $uid = substr(uniqid('', true), -8);
        $tenant = $this->tenant('Form ' . $uid, 'form_' . $uid, $active);
        $other = $this->tenant('Other ' . $uid, 'other_' . $uid, $active);

        $this->user = new User();
        $this->user->setEmail('form_' . $uid . '@example.com');
        $this->user->setFirstName('Fritz');
        $this->user->setLastName('Form');
        $this->user->setRoles(['ROLE_ADMIN']);
        $this->user->setPassword('hashed_password');
        $this->user->setTenant($tenant);
        $this->user->setIsActive(true);
        $this->em->persist($this->user);

        $this->asset = $this->asset('Kundenportal ' . $uid, $tenant, 4);
        $this->foreignAsset = $this->asset('Fremdes Portal ' . $uid, $other, 2);

        $this->risk = new Risk();
        $this->risk->setTenant($tenant);
        $this->risk->setTitle('Datenabfluss ' . $uid);
        $this->risk->setCategory('operational');
        $this->risk->setDescription('Wave F fixture.');
        $this->risk->setProbability(3);
        $this->risk->setImpact(3);
        $this->risk->setStatus(RiskStatus::Assessed);
        $this->risk->setCreatedAt(new DateTimeImmutable());
        $this->em->persist($this->risk);

        $this->em->flush();
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            $connection = $this->em->getConnection();
            foreach ($this->tenants as $tenant) {
                foreach ([
                    'DELETE FROM risk_treatment_plan WHERE tenant_id = ?',
                    'DELETE FROM risk WHERE tenant_id = ?',
                    'DELETE FROM asset WHERE tenant_id = ?',
                    'DELETE FROM users WHERE tenant_id = ?',
                    'DELETE FROM tenant WHERE id = ?',
                ] as $sql) {
                    try {
                        $connection->executeStatement($sql, [$tenant->getId()]);
                    } catch (\Throwable) {
                        // best effort: audit rows written during the request may block
                    }
                }
            }
        }
        $this->tenants = [];

        parent::tearDown();
    }

    /** @param list<string> $modules */
    private function tenant(string $name, string $code, array $modules): Tenant
    {
        $tenant = (new Tenant())->setName($name)->setCode($code);
        $tenant->setActiveModules($modules);
        $this->em->persist($tenant);
        $this->tenants[] = $tenant;

        return $tenant;
    }

    private function asset(string $name, Tenant $tenant, int $confidentiality): Asset
    {
        $asset = (new Asset())
            ->setName($name)
            ->setAssetType('application')
            ->setDescription('Wave F fixture.')
            ->setTenant($tenant)
            ->setConfidentialityValue($confidentiality)
            ->setIntegrityValue(2)
            ->setAvailabilityValue(2);
        $this->em->persist($asset);

        return $asset;
    }

    #[Test]
    public function riskFormIsPrefilledFromTheAsset(): void
    {
        $crawler = $this->client->request('GET', '/de/risk/new?fromAsset=' . $this->asset->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Kundenportal', (string) $crawler->filter('input[name="risk[title]"]')->attr('value'));
        self::assertSame((string) $this->asset->getId(), $crawler->filter('select[name="risk[asset]"] option[selected]')->attr('value'));
        self::assertSame('4', $crawler->filter('input[name="risk[impact]"]')->attr('value'));
    }

    #[Test]
    public function aForeignAssetIsIgnored(): void
    {
        $crawler = $this->client->request('GET', '/de/risk/new?fromAsset=' . $this->foreignAsset->getId());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Fremdes Portal', (string) $this->client->getResponse()->getContent());
        self::assertSame('', (string) $crawler->filter('input[name="risk[title]"]')->attr('value'));
    }

    #[Test]
    public function treatmentPlanFormIsPrefilledFromTheRisk(): void
    {
        $crawler = $this->client->request('GET', '/de/risk-treatment-plan/new?fromRisk=' . $this->risk->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Datenabfluss', (string) $crawler->filter('input[name="risk_treatment_plan[title]"]')->attr('value'));
        self::assertSame((string) $this->risk->getId(), $crawler->filter('select[name="risk_treatment_plan[risk]"] option[selected]')->attr('value'));
    }

    #[Test]
    public function riskPageOffersATreatmentPlanAndAssetPageOffersARisk(): void
    {
        $this->client->request('GET', '/de/risk/' . $this->risk->getId());
        self::assertSelectorExists('[data-testid="risk-derive-treatment-plan"][href*="fromRisk=' . $this->risk->getId() . '"]');

        $this->em->clear();
        $this->client->request('GET', '/de/asset/' . $this->asset->getId());
        self::assertSelectorExists('[data-testid="derive-risk"][href*="fromAsset=' . $this->asset->getId() . '"]');
    }

    #[Test]
    public function largeFormOpensOnlyItsCoreAndCollapsesTheRest(): void
    {
        $crawler = $this->client->request('GET', '/de/supplier/new');

        self::assertResponseIsSuccessful();
        $sections = $crawler->filter('.fa-form-layout .fa-form-section');
        self::assertGreaterThan(2, $sections->count());
        self::assertStringContainsString('fa-form-section--open', (string) $sections->first()->attr('class'));
        self::assertGreaterThan(0, $crawler->filter('.fa-form-section--collapsed')->count());
        self::assertSelectorTextContains('.fa-form-section--collapsed .fa-form-section__meta', 'von');
    }

    #[Test]
    public function emptyLegacyOwnerTextIsNotOffered(): void
    {
        $this->client->request('GET', '/de/asset/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[name="asset[owner]"]');

        $this->asset->setOwner('Team Web (alt)');
        $this->em->flush();
        $this->client->request('GET', '/de/asset/' . $this->asset->getId() . '/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[name="asset[owner]"][value="Team Web (alt)"]');
    }

    /** @return iterable<string, array{string, string}> */
    public static function mappingPages(): iterable
    {
        yield 'hub' => ['/de/compliance/mapping/hub', 'overview'];
        yield 'list' => ['/de/compliance/mapping', 'mappings'];
        yield 'quality' => ['/de/compliance/mapping-quality/review-queue', 'quality'];
        yield 'compare' => ['/de/compliance/compare', 'compare'];
        yield 'cross framework' => ['/de/compliance/cross-framework', 'compare'];
        yield 'transitive' => ['/de/compliance/transitive-compliance', 'compare'];
    }

    #[Test]
    #[DataProvider('mappingPages')]
    public function mappingScreensShareTheHubTabs(string $url, string $activeTab): void
    {
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="mapping-hub-tabs"]');
        self::assertSelectorExists('[data-testid="mapping-hub-tab-' . $activeTab . '"][aria-current="page"]');
        self::assertSelectorCount(1, '[data-testid="mapping-hub-tabs"] [aria-current="page"]');
    }
}
