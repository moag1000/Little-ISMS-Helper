<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Asset;
use App\Entity\Risk;
use App\Enum\RiskStatus;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for SearchController and SearchService.
 */
class SearchControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?Tenant $otherTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?User $otherUser = null;
    private ?Asset $testAsset = null;
    private ?Risk $testRisk = null;
    private ?Asset $otherTenantAsset = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        // Ensure setup-complete lock exists
        $lockFile = $container->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lockFile)) {
            @file_put_contents($lockFile, date('c'));
        }

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        foreach ([$this->testAsset, $this->otherTenantAsset] as $asset) {
            if ($asset) {
                try {
                    $found = $this->entityManager->find(Asset::class, $asset->getId());
                    if ($found) {
                        $this->entityManager->remove($found);
                    }
                } catch (\Exception) {
                }
            }
        }

        if ($this->testRisk) {
            try {
                $found = $this->entityManager->find(Risk::class, $this->testRisk->getId());
                if ($found) {
                    $this->entityManager->remove($found);
                }
            } catch (\Exception) {
            }
        }

        foreach ([$this->testUser, $this->adminUser, $this->otherUser] as $user) {
            if ($user) {
                try {
                    $found = $this->entityManager->find(User::class, $user->getId());
                    if ($found) {
                        $this->entityManager->remove($found);
                    }
                } catch (\Exception) {
                }
            }
        }

        foreach ([$this->testTenant, $this->otherTenant] as $tenant) {
            if ($tenant) {
                try {
                    $found = $this->entityManager->find(Tenant::class, $tenant->getId());
                    if ($found) {
                        $this->entityManager->remove($found);
                    }
                } catch (\Exception) {
                }
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception) {
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uid = uniqid('srch_', true);

        // Primary tenant + users
        $shortUid = substr(md5($uid), 0, 8);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('Search Test Tenant ' . $uid);
        $this->testTenant->setCode('s1_' . $shortUid);
        $this->entityManager->persist($this->testTenant);

        $this->testUser = new User();
        $this->testUser->setEmail('srch_user_' . $uid . '@example.com');
        $this->testUser->setFirstName('Search');
        $this->testUser->setLastName('User');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        $this->adminUser = new User();
        $this->adminUser->setEmail('srch_admin_' . $uid . '@example.com');
        $this->adminUser->setFirstName('Search');
        $this->adminUser->setLastName('Admin');
        $this->adminUser->setRoles(['ROLE_ADMIN']);
        $this->adminUser->setPassword('hashed_password');
        $this->adminUser->setTenant($this->testTenant);
        $this->adminUser->setIsActive(true);
        $this->entityManager->persist($this->adminUser);

        // Test asset on primary tenant — unique name for assertion
        // CIA values are required (NOT NULL) in the asset table
        $this->testAsset = new Asset();
        $this->testAsset->setName('UniqueSrchAsset_' . $uid);
        $this->testAsset->setAssetType('hardware');
        $this->testAsset->setDescription('Asset used for global-search tests');
        $this->testAsset->setConfidentialityValue(3);
        $this->testAsset->setIntegrityValue(3);
        $this->testAsset->setAvailabilityValue(3);
        $this->testAsset->setTenant($this->testTenant);
        $this->entityManager->persist($this->testAsset);

        // Test risk on primary tenant (probability/impact/status are NOT NULL in test DB)
        $this->testRisk = new Risk();
        $this->testRisk->setTitle('UniqueSrchRisk_' . $uid);
        $this->testRisk->setDescription('Risk for search isolation test');
        $this->testRisk->setCategory('operational');
        $this->testRisk->setProbability(2);
        $this->testRisk->setImpact(2);
        $this->testRisk->setResidualProbability(1);
        $this->testRisk->setResidualImpact(1);
        $this->testRisk->setStatus(RiskStatus::Identified);
        $this->testRisk->setTenant($this->testTenant);
        $this->entityManager->persist($this->testRisk);

        // Second tenant + user + asset — for isolation tests
        $this->otherTenant = new Tenant();
        $this->otherTenant->setName('Other Search Tenant ' . $uid);
        $this->otherTenant->setCode('s2_' . $shortUid);
        $this->entityManager->persist($this->otherTenant);

        $this->otherUser = new User();
        $this->otherUser->setEmail('srch_other_' . $uid . '@example.com');
        $this->otherUser->setFirstName('Other');
        $this->otherUser->setLastName('Tenant');
        $this->otherUser->setRoles(['ROLE_USER']);
        $this->otherUser->setPassword('hashed_password');
        $this->otherUser->setTenant($this->otherTenant);
        $this->otherUser->setIsActive(true);
        $this->entityManager->persist($this->otherUser);

        $this->otherTenantAsset = new Asset();
        $this->otherTenantAsset->setName('UniqueSrchAsset_' . $uid . '_other');
        $this->otherTenantAsset->setAssetType('software');
        $this->otherTenantAsset->setDescription('Asset on second tenant — must NOT appear for primary tenant user');
        $this->otherTenantAsset->setConfidentialityValue(1);
        $this->otherTenantAsset->setIntegrityValue(1);
        $this->otherTenantAsset->setAvailabilityValue(1);
        $this->otherTenantAsset->setTenant($this->otherTenant);
        $this->entityManager->persist($this->otherTenantAsset);

        $this->entityManager->flush();
    }

    // -------------------------------------------------------------------------
    // Authentication gate
    // -------------------------------------------------------------------------

    #[Test]
    public function testSearchRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/api/search?q=test');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testAssetPreviewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/api/asset/1/preview');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testRiskPreviewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/api/risk/1/preview');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testIncidentPreviewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/api/incident/1/preview');
        $this->assertResponseRedirects();
    }

    // -------------------------------------------------------------------------
    // Basic response shape
    // -------------------------------------------------------------------------

    #[Test]
    public function testSearchReturnsJsonForUser(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/api/search?q=test');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    #[Test]
    public function testSearchWithShortQueryReturnsEmptyTotal(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/api/search?q=x');
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(0, $data['total']);
    }

    #[Test]
    public function testSearchWithEmptyQueryReturnsEmptyTotal(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/api/search?q=');
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(0, $data['total']);
    }

    #[Test]
    public function testSearchReturnsAllExpectedCategories(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/api/search?q=test');
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $expectedCategories = [
            'navigation', 'assets', 'risks', 'controls', 'incidents', 'trainings',
            'documents', 'suppliers', 'processing_activities', 'dpias', 'data_breaches',
            'audit_findings', 'corrective_actions', 'change_requests', 'internal_audits',
            'business_processes', 'bc_plans', 'bc_exercises', 'crisis_teams',
            'management_reviews', 'objectives', 'vulnerabilities', 'patches',
            'threat_intelligence', 'persons', 'interested_parties', 'consents',
            'data_subject_requests', 'compliance_frameworks', 'compliance_requirements',
        ];

        foreach ($expectedCategories as $category) {
            $this->assertArrayHasKey($category, $data, "Missing category: {$category}");
            $this->assertIsArray($data[$category], "Category {$category} must be an array");
        }
    }

    // -------------------------------------------------------------------------
    // testSearchReturnsResultsForKnownEntity
    // -------------------------------------------------------------------------

    #[Test]
    public function testSearchReturnsResultsForKnownEntity(): void
    {
        // Search for the full unique asset name (minus trailing chars to stay <= 100 chars)
        $searchQuery = mb_substr($this->testAsset->getName(), 0, 30);
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/api/search?q=' . urlencode($searchQuery));
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertGreaterThan(0, $data['total']);
        $this->assertNotEmpty($data['assets'], 'Expected at least one asset result');

        $found = false;
        foreach ($data['assets'] as $result) {
            if ($result['title'] === $this->testAsset->getName()) {
                $found = true;
                $this->assertArrayHasKey('url', $result);
                $this->assertArrayHasKey('icon', $result);
                $this->assertArrayHasKey('type', $result);
                $this->assertSame('asset', $result['type']);
                break;
            }
        }
        $this->assertTrue($found, 'Test asset not found in search results');
    }

    #[Test]
    public function testSearchReturnsEmptyForUnknownQuery(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/api/search?q=xNeverMatchesAnythingXyZq99');
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(0, $data['total']);
        $this->assertEmpty($data['assets'] ?? []);
        $this->assertEmpty($data['navigation'] ?? []);
    }

    // -------------------------------------------------------------------------
    // testSearchRespectsTenantIsolation
    // -------------------------------------------------------------------------

    #[Test]
    public function testSearchRespectsTenantIsolation(): void
    {
        // Both assets share the prefix 'UniqueSrchAsset_' + first 20 chars of name
        // Primary tenant asset does NOT end with '_other'; other tenant asset does.
        $searchQuery = mb_substr($this->testAsset->getName(), 0, 25);

        // Primary tenant user should find their own asset but NOT the other-tenant asset
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/api/search?q=' . urlencode($searchQuery));
        $this->assertResponseIsSuccessful();
        $primary = json_decode($this->client->getResponse()->getContent(), true);

        $primaryTitles = array_column($primary['assets'] ?? [], 'title');
        $this->assertContains($this->testAsset->getName(), $primaryTitles, 'Primary tenant user must see their own asset');
        $this->assertNotContains($this->otherTenantAsset->getName(), $primaryTitles, 'Primary tenant user must NOT see other tenant asset');

        // Other tenant user searches for their own unique asset suffix
        $otherSearchQuery = mb_substr($this->otherTenantAsset->getName(), 0, 30);
        $this->client->loginUser($this->otherUser);
        $this->client->request('GET', '/en/api/search?q=' . urlencode($otherSearchQuery));
        $this->assertResponseIsSuccessful();
        $other = json_decode($this->client->getResponse()->getContent(), true);

        $otherTitles = array_column($other['assets'] ?? [], 'title');
        $this->assertContains($this->otherTenantAsset->getName(), $otherTitles, 'Other tenant user must see their own asset');
        $this->assertNotContains($this->testAsset->getName(), $otherTitles, 'Other tenant user must NOT see primary tenant asset');
    }

    // -------------------------------------------------------------------------
    // testSearchHonorsRoleVisibilityForNavigation
    // -------------------------------------------------------------------------

    #[Test]
    public function testSearchHonorsRoleVisibilityForNavigation(): void
    {
        // Admin user: should see admin-only navigation entries (e.g. "Admin Dashboard")
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/en/api/search?q=Admin');
        $this->assertResponseIsSuccessful();
        $adminData = json_decode($this->client->getResponse()->getContent(), true);
        $adminNavTitles = array_column($adminData['navigation'] ?? [], 'title');
        $this->assertNotEmpty($adminNavTitles, 'Admin should see navigation results matching "Admin"');

        // ROLE_USER (non-admin): admin-only routes must NOT appear
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/api/search?q=Admin+Dashboard');
        $this->assertResponseIsSuccessful();
        $userData = json_decode($this->client->getResponse()->getContent(), true);
        $userNavTitles = array_column($userData['navigation'] ?? [], 'title');
        $this->assertNotContains('Admin Dashboard', $userNavTitles, 'ROLE_USER must not see Admin Dashboard navigation result');
    }

    // -------------------------------------------------------------------------
    // Result shape validation
    // -------------------------------------------------------------------------

    #[Test]
    public function testNavigationResultsHaveRequiredFields(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/en/api/search?q=Dashboard');
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        foreach ($data['navigation'] ?? [] as $result) {
            $this->assertArrayHasKey('title', $result, 'Navigation result must have title');
            $this->assertArrayHasKey('description', $result, 'Navigation result must have description');
            $this->assertArrayHasKey('url', $result, 'Navigation result must have url');
            $this->assertArrayHasKey('icon', $result, 'Navigation result must have icon');
            $this->assertSame('navigation', $result['type']);
        }
    }

    #[Test]
    public function testEntityResultsContainUrlAndIcon(): void
    {
        $searchQuery = mb_substr($this->testAsset->getName(), 0, 30);
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/api/search?q=' . urlencode($searchQuery));
        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNotEmpty($data['assets']);

        $result = $data['assets'][0];
        $this->assertNotEmpty($result['url'], 'url must not be empty');
        $this->assertNotEmpty($result['icon'], 'icon must not be empty');
        $this->assertStringContainsString('/asset/', $result['url'], 'url must point to the asset route');
    }
}
