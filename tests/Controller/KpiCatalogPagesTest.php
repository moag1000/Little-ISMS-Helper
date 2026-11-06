<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Service\ModuleConfigurationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UI wave D in the real app: dashboards read the KPI catalog (euro KPIs from
 * the quantitative risk fields), and a requested risk acceptance reaches the
 * approver in My Day with a business line and can be decided there.
 */
final class KpiCatalogPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private User $ciso;
    private Risk $risk;
    private string $uid;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $modules = static::getContainer()->get(ModuleConfigurationService::class);

        $this->uid = substr(uniqid('', true), -8);
        $this->tenant = (new Tenant())->setName('KPI ' . $this->uid)->setCode('kpi_' . $this->uid);
        $this->tenant->setActiveModules(array_values(array_unique([...$modules->getInstanceDefaultModules(), 'risks', 'risk_quant'])));
        $this->em->persist($this->tenant);

        $this->ciso = new User();
        $this->ciso->setEmail('kpi_' . $this->uid . '@example.com');
        $this->ciso->setFirstName('Kim');
        $this->ciso->setLastName('Ciso');
        $this->ciso->setRoles(['ROLE_CISO', 'ROLE_ADMIN']);
        $this->ciso->setPassword('hashed_password');
        $this->ciso->setTenant($this->tenant);
        $this->ciso->setIsActive(true);
        $this->em->persist($this->ciso);

        $this->risk = new Risk();
        $this->risk->setTenant($this->tenant);
        $this->risk->setTitle('Ransomware Kundenportal ' . $this->uid);
        $this->risk->setCategory('operational');
        $this->risk->setDescription('KPI catalog fixture.');
        $this->risk->setProbability(4);
        $this->risk->setImpact(4);
        $this->risk->setResidualProbability(3);
        $this->risk->setResidualImpact(4);
        $this->risk->setSingleLossExpectancy(250000);
        $this->risk->setAnnualRateOfOccurrence(0.4);
        $this->risk->setTreatmentStrategy(TreatmentStrategy::Accept);
        $this->risk->setAcceptanceJustification('Kosten der Maßnahme übersteigen den erwarteten Schaden.');
        $this->risk->setStatus(RiskStatus::Assessed);
        $this->risk->setCreatedAt(new DateTimeImmutable());
        $this->em->persist($this->risk);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $tenantId = $this->tenant?->getId();
        if ($tenantId !== null) {
            $conn = $this->em->getConnection();
            foreach ([
                'DELETE FROM risk WHERE tenant_id = ?',
                'DELETE FROM users WHERE tenant_id = ?',
                'DELETE FROM tenant WHERE id = ?',
            ] as $sql) {
                try {
                    $conn->executeStatement($sql, [$tenantId]);
                } catch (\Throwable) {
                    // best effort: audit rows written during the request may block
                }
            }
        }
        $this->tenant = null;

        parent::tearDown();
    }

    #[Test]
    public function cisoDashboardShowsTheExpectedAnnualLoss(): void
    {
        $this->client->loginUser($this->ciso);
        $this->client->request('GET', '/de/dashboards/ciso');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#risk-exposure-title');
        self::assertSelectorTextContains('section[aria-labelledby="risk-exposure-title"]', '100.000');
        self::assertSelectorTextContains('section[aria-labelledby="risk-exposure-title"]', 'Ransomware Kundenportal');
    }

    #[Test]
    public function requestedAcceptanceIsDecidedFromMyDay(): void
    {
        $this->client->loginUser($this->ciso);
        $crawler = $this->client->request('GET', '/de/my-day');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#risk-acceptance-pending');
        self::assertSelectorTextContains('#risk-acceptance-pending', 'Ransomware Kundenportal');
        self::assertSelectorTextContains('#risk-acceptance-pending', '€/Jahr');

        $form = $crawler->filter('#risk-acceptance-pending form')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/de/my-day');

        $this->em->clear();
        self::assertTrue($this->em->find(Risk::class, $this->risk->getId())?->isFormallyAccepted());
    }

    #[Test]
    public function riskPageShowsThePendingAcceptanceWithDecision(): void
    {
        $this->client->loginUser($this->ciso);
        $this->client->request('GET', '/de/risk/' . $this->risk->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#acceptance form[action$="/approve-acceptance"]');
        self::assertSelectorExists('#acceptance form[action$="/reject-acceptance"]');
    }
}
