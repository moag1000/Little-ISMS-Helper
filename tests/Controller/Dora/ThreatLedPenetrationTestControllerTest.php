<?php

declare(strict_types=1);

namespace App\Tests\Controller\Dora;

use App\Entity\Tenant;
use App\Entity\ThreatLedPenetrationTest;
use App\Entity\User;
use App\Lifecycle\LifecycleService;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * W6 (DORA Art. 26): the TLPT page — managers plan and edit, every member
 * reads, other organisations' tests stay hidden, and the lifecycle steps
 * stamp the actual test dates.
 */
final class ThreatLedPenetrationTestControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    #[Test]
    public function managerPlansATestAndMembersReadIt(): void
    {
        $tenant = $this->tenant();
        $this->client->loginUser($this->user('ROLE_ISB', $tenant));
        $crawler = $this->client->request('GET', '/de/tlpt/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="threat_led_penetration_test"]')->form();
        self::assertMatchesRegularExpression('/^TLPT-\d{4}-01$/', (string) $form['threat_led_penetration_test[engagementNumber]']->getValue());
        $form['threat_led_penetration_test[title]'] = 'Red team payments';
        $form['threat_led_penetration_test[plannedEndDate]'] = (new \DateTimeImmutable('+60 days'))->format('Y-m-d');
        $this->client->submit($form);
        self::assertResponseRedirects();

        $test = $this->em()->getRepository(ThreatLedPenetrationTest::class)->findOneBy(['title' => 'Red team payments']);
        self::assertInstanceOf(ThreatLedPenetrationTest::class, $test);
        self::assertSame($tenant->getId(), $test->getTenant()?->getId());
        self::assertSame(ThreatLedPenetrationTest::STATUS_PLANNED, $test->getStatus());

        $this->client->loginUser($this->user('ROLE_USER', $tenant));
        $this->client->request('GET', '/de/tlpt/' . $test->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Red team payments');
        $this->client->request('GET', '/de/tlpt');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Red team payments');
        $this->client->request('GET', '/de/tlpt/new');
        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function anotherOrganisationsTestIsNotFound(): void
    {
        $test = $this->record($this->tenant());
        $this->client->loginUser($this->user('ROLE_ISB', $this->tenant()));
        $this->client->request('GET', '/de/tlpt/' . $test->getId());
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function lifecycleStepsStampTheActualDates(): void
    {
        $tenant = $this->tenant();
        $user = $this->user('ROLE_CISO', $tenant);
        $test = $this->record($tenant);
        $lifecycle = static::getContainer()->get(LifecycleService::class);
        static::getContainer()->get(\App\Service\TenantContext::class)->setCurrentTenant($tenant);
        foreach (['start_scoping', 'start_red_team'] as $step) {
            $lifecycle->transition($test, 'threat_led_penetration_test_lifecycle', $step, $user);
        }
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $test->getActualStartDate()?->format('Y-m-d'));
        self::assertNull($test->getActualEndDate());
        $lifecycle->transition($test, 'threat_led_penetration_test_lifecycle', 'start_reporting', $user);
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $test->getActualEndDate()?->format('Y-m-d'));
    }

    #[Test]
    public function closedTestsAreNotEditable(): void
    {
        $tenant = $this->tenant();
        $test = $this->record($tenant)->setStatus(ThreatLedPenetrationTest::STATUS_CLOSED);
        $this->em()->flush();
        $this->client->loginUser($this->user('ROLE_ISB', $tenant));
        $this->client->request('GET', '/de/tlpt/' . $test->getId() . '/edit');
        self::assertResponseRedirects('/de/tlpt/' . $test->getId());
    }

    private function record(Tenant $tenant): ThreatLedPenetrationTest
    {
        $test = (new ThreatLedPenetrationTest())->setTenant($this->em()->find(Tenant::class, $tenant->getId()))
            ->setEngagementNumber('TLPT-T-' . bin2hex(random_bytes(3)))->setTitle('t');
        $this->em()->persist($test);
        $this->em()->flush();

        return $test;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function tenant(): Tenant
    {
        $id = bin2hex(random_bytes(5));
        $modules = static::getContainer()->get(ModuleConfigurationService::class);
        $tenant = (new Tenant())->setName('TLPT ' . $id)->setCode('tlpt_' . $id)
            ->setActiveModules(array_values($modules->resolveModuleDependencies(['nis2_dora', 'audits'])['modules']));
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function user(string $role, Tenant $tenant): User
    {
        $user = (new User())->setEmail('tlpt-' . bin2hex(random_bytes(5)) . '@example.test')->setFirstName('T')->setLastName('P')
            ->setRoles([$role])->setPassword('hashed')->setTenant($this->em()->find(Tenant::class, $tenant->getId()))->setIsActive(true);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }
}
