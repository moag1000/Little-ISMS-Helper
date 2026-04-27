<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\Vulnerability;
use App\Repository\RiskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for Junior-Finding #8 / CM Data-Reuse Blocker:
 * One-click derivation of Risk from Vulnerability (and cross-tenant isolation).
 *
 * Covers the entity link matrix in /risk/new?fromVulnerability={id}.
 */
class RiskControllerLinkMatrixTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $tenantA = null;
    private ?Tenant $tenantB = null;
    private ?User $userA = null;
    private ?Vulnerability $vulnA = null;
    private ?Vulnerability $vulnB = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->createFixtures();
    }

    protected function tearDown(): void
    {
        // Clean up risks that may have been created during test runs
        $riskRepo = $this->entityManager->getRepository(Risk::class);
        foreach ($riskRepo->findBy(['tenant' => $this->tenantA]) as $risk) {
            try { $this->entityManager->remove($risk); } catch (\Exception $e) {}
        }
        foreach ($riskRepo->findBy(['tenant' => $this->tenantB]) as $risk) {
            try { $this->entityManager->remove($risk); } catch (\Exception $e) {}
        }

        foreach ([$this->vulnA, $this->vulnB] as $vuln) {
            if ($vuln) {
                try {
                    $managed = $this->entityManager->find(Vulnerability::class, $vuln->getId());
                    if ($managed) {
                        $this->entityManager->remove($managed);
                    }
                } catch (\Exception $e) {}
            }
        }

        if ($this->userA) {
            try {
                $managed = $this->entityManager->find(User::class, $this->userA->getId());
                if ($managed) {
                    $this->entityManager->remove($managed);
                }
            } catch (\Exception $e) {}
        }

        foreach ([$this->tenantA, $this->tenantB] as $tenant) {
            if ($tenant) {
                try {
                    $managed = $this->entityManager->find(Tenant::class, $tenant->getId());
                    if ($managed) {
                        $this->entityManager->remove($managed);
                    }
                } catch (\Exception $e) {}
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {}

        parent::tearDown();
    }

    private function createFixtures(): void
    {
        $uid = uniqid('lm_', true);

        $this->tenantA = new Tenant();
        $this->tenantA->setName('Tenant A ' . $uid);
        $this->tenantA->setCode('tenant_a_' . $uid);
        $this->entityManager->persist($this->tenantA);

        $this->tenantB = new Tenant();
        $this->tenantB->setName('Tenant B ' . $uid);
        $this->tenantB->setCode('tenant_b_' . $uid);
        $this->entityManager->persist($this->tenantB);

        $this->userA = new User();
        $this->userA->setEmail('lm_user_' . $uid . '@example.com');
        $this->userA->setFirstName('LinkMatrix');
        $this->userA->setLastName('User');
        $this->userA->setRoles(['ROLE_USER']);
        $this->userA->setPassword('hashed_password');
        $this->userA->setTenant($this->tenantA);
        $this->userA->setIsActive(true);
        $this->entityManager->persist($this->userA);

        // Vulnerability in same tenant
        $this->vulnA = new Vulnerability();
        $this->vulnA->setTenant($this->tenantA);
        $this->vulnA->setCveId('CVE-2099-A' . substr($uid, -6));
        $this->vulnA->setTitle('Log4Shell-like RCE in TestApp');
        $this->vulnA->setDescription('Remote code execution via crafted payloads.');
        $this->vulnA->setSeverity("critical");
        $this->vulnA->setSource('internal');
        $this->vulnA->setStatus('open');
        $this->vulnA->setDiscoveredDate(new \DateTimeImmutable());
        $this->entityManager->persist($this->vulnA);

        // Vulnerability in OTHER tenant — must never leak into prefill
        $this->vulnB = new Vulnerability();
        $this->vulnB->setTenant($this->tenantB);
        $this->vulnB->setCveId('CVE-2099-B' . substr($uid, -6));
        $this->vulnB->setTitle('Cross-Tenant Leakage Probe');
        $this->vulnB->setDescription('This MUST NOT leak into another tenant.');
        $this->vulnB->setSeverity("high");
        $this->vulnB->setSource('internal');
        $this->vulnB->setStatus('open');
        $this->vulnB->setDiscoveredDate(new \DateTimeImmutable());
        $this->entityManager->persist($this->vulnB);

        $this->entityManager->flush();
    }

    #[Test]
    public function testRiskNewPrefillsFromSameTenantVulnerability(): void
    {
        $this->client->loginUser($this->userA);

        $crawler = $this->client->request(
            'GET',
            '/en/risk/new',
            ['fromVulnerability' => $this->vulnA->getId()]
        );

        $this->assertResponseIsSuccessful();

        // Form title should contain the vulnerability title (via the prefill translation)
        $titleField = $crawler->filter('input[name="risk[title]"]');
        $this->assertCount(1, $titleField, 'Expected risk[title] input on /risk/new');
        $this->assertStringContainsString(
            $this->vulnA->getTitle(),
            (string) $titleField->attr('value'),
            'Risk title should be pre-filled with vulnerability title'
        );

        // linkedVulnerability select should have the vuln pre-selected
        $linkedSelect = $crawler->filter('select[name="risk[linkedVulnerability]"]');
        $this->assertCount(1, $linkedSelect, 'Expected linkedVulnerability select on /risk/new');
        $selectedOption = $linkedSelect->filter('option[selected]');
        $this->assertGreaterThan(
            0,
            $selectedOption->count(),
            'linkedVulnerability should have a pre-selected option when prefill is applied'
        );
        $this->assertSame(
            (string) $this->vulnA->getId(),
            (string) $selectedOption->attr('value'),
            'Pre-selected linkedVulnerability must match the source vulnerability'
        );
    }

    #[Test]
    public function testRiskNewIgnoresCrossTenantVulnerability(): void
    {
        $this->client->loginUser($this->userA);

        $crawler = $this->client->request(
            'GET',
            '/en/risk/new',
            ['fromVulnerability' => $this->vulnB->getId()]
        );

        $this->assertResponseIsSuccessful();

        // Title should NOT contain the cross-tenant vulnerability title
        $titleField = $crawler->filter('input[name="risk[title]"]');
        $this->assertCount(1, $titleField);
        $this->assertStringNotContainsString(
            'Cross-Tenant Leakage Probe',
            (string) $titleField->attr('value'),
            'Cross-tenant vulnerability MUST NOT leak into prefill'
        );

        // linkedVulnerability must not be pre-selected with tenant B's vulnerability
        $selectedOption = $crawler->filter('select[name="risk[linkedVulnerability]"] option[selected]');
        foreach ($selectedOption as $option) {
            $this->assertNotSame(
                (string) $this->vulnB->getId(),
                (string) $option->getAttribute('value'),
                'Cross-tenant vulnerability must not be pre-selected'
            );
        }
    }

    #[Test]
    public function testVulnerabilityShowRendersLinkMatrixSection(): void
    {
        $this->client->loginUser($this->userA);

        $crawler = $this->client->request('GET', '/en/vulnerability/' . $this->vulnA->getId());

        $this->assertResponseIsSuccessful();
        // The entity link matrix component must be present on the vulnerability show page
        $this->assertGreaterThan(
            0,
            $crawler->filter('.entity-link-matrix')->count(),
            'Vulnerability show page must contain the entity link matrix section'
        );
        // And the "Derive Risk from Vulnerability" button must link to /risk/new with the id
        $deriveLinks = $crawler->filter('a[href*="fromVulnerability=' . $this->vulnA->getId() . '"]');
        $this->assertGreaterThan(
            0,
            $deriveLinks->count(),
            'Vulnerability show must expose a "Derive Risk" one-click button'
        );
    }
}
