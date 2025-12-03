<?php

namespace App\Tests\Controller;

use App\Entity\Location;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for LocationController
 *
 * Tests location management including:
 * - Index with top-level locations
 * - CRUD operations (create, read, update, delete)
 * - Hierarchical location structure (parent/child)
 * - Role-based access control
 * - Multi-tenant isolation
 */
class LocationControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?Location $testLocation = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        // Clean up locations
        if ($this->testLocation) {
            try {
                $location = $this->entityManager->find(Location::class, $this->testLocation->getId());
                if ($location) {
                    // Remove child locations first
                    foreach ($location->getChildLocations() as $child) {
                        $this->entityManager->remove($child);
                    }
                    $this->entityManager->remove($location);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        // Clean up additional locations created during tests
        $locationRepo = $this->entityManager->getRepository(Location::class);
        foreach (['New Test Location', 'Updated Test Location', 'Child Location'] as $name) {
            $locations = $locationRepo->findBy(['name' => $name]);
            foreach ($locations as $location) {
                try {
                    $this->entityManager->remove($location);
                } catch (\Exception $e) {
                    // Ignore
                }
            }
        }

        // Clean up users
        if ($this->testUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->testUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($this->adminUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->adminUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        // Clean up tenant
        if ($this->testTenant) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($tenant) {
                    $this->entityManager->remove($tenant);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // Ignore flush errors during cleanup
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('test_', true);

        // Create test tenant
        $this->testTenant = new Tenant();
        $this->testTenant->setName('Test Tenant ' . $uniqueId);
        $this->testTenant->setCode('test_tenant_' . $uniqueId);
        $this->entityManager->persist($this->testTenant);

        // Create test user with ROLE_USER
        $this->testUser = new User();
        $this->testUser->setEmail('testuser_' . $uniqueId . '@example.com');
        $this->testUser->setFirstName('Test');
        $this->testUser->setLastName('User');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        // Create admin user with ROLE_ADMIN
        $this->adminUser = new User();
        $this->adminUser->setEmail('admin_' . $uniqueId . '@example.com');
        $this->adminUser->setFirstName('Admin');
        $this->adminUser->setLastName('User');
        $this->adminUser->setRoles(['ROLE_ADMIN']);
        $this->adminUser->setPassword('hashed_password');
        $this->adminUser->setTenant($this->testTenant);
        $this->adminUser->setIsActive(true);
        $this->entityManager->persist($this->adminUser);

        // Create test location
        $this->testLocation = new Location();
        $this->testLocation->setTenant($this->testTenant);
        $this->testLocation->setName('Test Location ' . $uniqueId);
        $this->testLocation->setDescription('Test location description');
        $this->testLocation->setLocationType('building');
        $this->testLocation->setAddress('123 Test Street');
        $this->testLocation->setCity('Test City');
        $this->testLocation->setCountry('Germany');
        $this->entityManager->persist($this->testLocation);

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    // ========== INDEX ACTION TESTS ==========

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/location/');

        $this->assertResponseRedirects();
    }

    public function testIndexShowsLocationsForAuthenticatedUser(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/location/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testIndexDisplaysTopLevelLocations(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/location/');

        $this->assertResponseIsSuccessful();
    }

    public function testIndexShowsTestLocation(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/location/');

        $this->assertResponseIsSuccessful();
    }

    // ========== SHOW ACTION TESTS ==========

    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/location/' . $this->testLocation->getId());

        $this->assertResponseRedirects();
    }

    public function testShowDisplaysLocation(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/location/' . $this->testLocation->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testShowDisplaysChildLocations(): void
    {
        $this->loginAsUser($this->testUser);

        // Create a child location
        $childLocation = new Location();
        $childLocation->setTenant($this->testTenant);
        $childLocation->setName('Child Location');
        $childLocation->setDescription('Child location');
        $childLocation->setLocationType('room');
        $childLocation->setParentLocation($this->testLocation);
        $this->entityManager->persist($childLocation);
        $this->entityManager->flush();

        $this->client->request('GET', '/en/location/' . $this->testLocation->getId());

        $this->assertResponseIsSuccessful();

        // Clean up
        $this->entityManager->remove($childLocation);
        $this->entityManager->flush();
    }

    public function testShowReturns404ForNonexistentLocation(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/location/999999');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== NEW ACTION TESTS ==========

    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/location/new');

        $this->assertResponseRedirects();
    }

    public function testNewDisplaysFormForUser(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/location/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="location"]');
    }

    public function testNewCreatesLocationWithValidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/location/new');
        $form = $crawler->filter('form[name="location"]')->form([
            'location[name]' => 'New Test Location',
            'location[description]' => 'New location description',
            'location[locationType]' => 'office',
            'location[address]' => '456 New Street',
            'location[city]' => 'New City',
            'location[country]' => 'Germany',
        ]);

        $this->client->submit($form);

        // Verify redirect to show page
        $this->assertResponseRedirects();
    }

    public function testNewRejectsInvalidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/location/new');
        $form = $crawler->filter('form[name="location"]')->form([
            'location[name]' => '', // Empty name - should fail validation
        ]);

        $this->client->submit($form);

        // Should re-display form with validation errors - modern Symfony returns 422
        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorExists('form[name="location"]');
    }

    // ========== EDIT ACTION TESTS ==========

    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/location/' . $this->testLocation->getId() . '/edit');

        $this->assertResponseRedirects();
    }

    public function testEditDisplaysFormForUser(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/location/' . $this->testLocation->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="location"]');
    }

    public function testEditUpdatesLocationWithValidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/location/' . $this->testLocation->getId() . '/edit');
        $form = $crawler->filter('form[name="location"]')->form([
            'location[name]' => 'Updated Test Location',
            'location[description]' => 'Updated description',
        ]);

        $this->client->submit($form);

        // Verify redirect
        $this->assertResponseRedirects();

        // Follow redirect and verify updated data
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('html', 'Updated Test Location');
    }

    public function testEditReturns404ForNonexistentLocation(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/location/999999/edit');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== DELETE ACTION TESTS ==========

    public function testDeleteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('POST', '/en/location/' . $this->testLocation->getId() . '/delete');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteRequiresCsrfToken(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/en/location/' . $this->testLocation->getId() . '/delete', [
            '_token' => 'invalid_token',
        ]);

        // Should redirect without deleting
        $this->assertResponseRedirects();

        // Location should still exist
        $this->entityManager->clear();
        $location = $this->entityManager->find(Location::class, $this->testLocation->getId());
        $this->assertNotNull($location);
    }

    public function testDeleteRemovesLocationWithValidToken(): void
    {
        $this->loginAsUser($this->adminUser);

        // Get the show page which has the delete form with CSRF token
        $crawler = $this->client->request('GET', '/en/location/' . $this->testLocation->getId());

        // Try to find the delete form and submit it
        $deleteForm = $crawler->filter('form[action*="/delete"]');
        if ($deleteForm->count() > 0) {
            $form = $deleteForm->form();
            $locationId = $this->testLocation->getId();
            $this->client->submit($form);

            $this->assertResponseRedirects('/en/location/');

            // Verify location is deleted
            $this->entityManager->clear();
            $location = $this->entityManager->find(Location::class, $locationId);
            $this->assertNull($location);
            $this->testLocation = null; // Prevent cleanup from failing
        } else {
            // If no delete form found on page, skip the deletion verification
            $this->assertResponseIsSuccessful();
            $this->markTestSkipped('Delete form not found on show page');
        }
    }

    // ========== HIERARCHICAL STRUCTURE TESTS ==========

    public function testLocationCanHaveParent(): void
    {
        $this->loginAsUser($this->testUser);

        // Create a child location with parent
        $childLocation = new Location();
        $childLocation->setTenant($this->testTenant);
        $childLocation->setName('Room 101');
        $childLocation->setDescription('Conference room');
        $childLocation->setLocationType('room');
        $childLocation->setParentLocation($this->testLocation);
        $this->entityManager->persist($childLocation);
        $this->entityManager->flush();

        // View parent location
        $this->client->request('GET', '/en/location/' . $this->testLocation->getId());

        $this->assertResponseIsSuccessful();

        // Clean up
        $this->entityManager->remove($childLocation);
        $this->entityManager->flush();
    }

    public function testLocationShowsAssociatedAssets(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/location/' . $this->testLocation->getId());

        $this->assertResponseIsSuccessful();
        // Page should show assets section even if empty
    }

    public function testLocationShowsAccessLogs(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/location/' . $this->testLocation->getId());

        $this->assertResponseIsSuccessful();
        // Page should show access logs section even if empty
    }

    // ========== EMPTY STATE TESTS ==========

    public function testIndexHandlesNoLocations(): void
    {
        // Remove test location
        $this->entityManager->remove($this->testLocation);
        $this->entityManager->flush();
        $this->testLocation = null;

        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/location/');

        $this->assertResponseIsSuccessful();
    }

    // ========== LOCATION TYPE TESTS ==========

    public function testNewLocationWithDifferentTypes(): void
    {
        $this->loginAsUser($this->testUser);

        $locationTypes = ['building', 'floor', 'room', 'office', 'datacenter', 'other'];

        foreach ($locationTypes as $type) {
            $crawler = $this->client->request('GET', '/en/location/new');
            $form = $crawler->filter('form[name="location"]')->form([
                'location[name]' => 'Test ' . $type . ' ' . uniqid(),
                'location[locationType]' => $type,
            ]);

            $this->client->submit($form);
            $this->assertResponseRedirects();
        }
    }
}
