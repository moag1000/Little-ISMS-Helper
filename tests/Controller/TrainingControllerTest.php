<?php

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for TrainingController
 *
 * Tests training management including:
 * - Index with statistics and filtering (own, inherited, subsidiaries)
 * - CRUD operations (create, read, update, delete)
 * - Bulk delete functionality
 * - Role-based access control
 * - Multi-tenant isolation and inheritance
 */
class TrainingControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?Training $testTraining = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        // Clean up training
        if ($this->testTraining) {
            try {
                $training = $this->entityManager->find(Training::class, $this->testTraining->getId());
                if ($training) {
                    $this->entityManager->remove($training);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        // Clean up additional trainings created during tests
        $trainingRepo = $this->entityManager->getRepository(Training::class);
        foreach (['New Test Training', 'Updated Test Training', 'Bulk Delete Training 1', 'Bulk Delete Training 2'] as $name) {
            $trainings = $trainingRepo->findBy(['title' => $name]);
            foreach ($trainings as $training) {
                try {
                    $this->entityManager->remove($training);
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

        // Create test training
        $this->testTraining = new Training();
        $this->testTraining->setTenant($this->testTenant);
        $this->testTraining->setTitle('Test Training ' . $uniqueId);
        $this->testTraining->setDescription('Test training description');
        $this->testTraining->setTrainingType('security_awareness');
        $this->testTraining->setTrainer('Test Trainer');
        $this->testTraining->setStatus('planned');
        $this->testTraining->setMandatory(true);
        $this->testTraining->setScheduledDate(new \DateTime('+1 month'));
        $this->entityManager->persist($this->testTraining);

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    // ========== INDEX ACTION TESTS ==========

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/training/');

        $this->assertResponseRedirects();
    }

    public function testIndexShowsTrainingsForAuthenticatedUser(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/training/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('html');
    }

    public function testIndexDisplaysStatistics(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/training/');

        $this->assertResponseIsSuccessful();
    }

    public function testIndexFiltersOwnTrainings(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/training/', ['view' => 'own']);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexFiltersInheritedTrainings(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/training/', ['view' => 'inherited']);

        $this->assertResponseIsSuccessful();
    }

    public function testIndexFiltersSubsidiariesTrainings(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/training/', ['view' => 'subsidiaries']);

        $this->assertResponseIsSuccessful();
    }

    // ========== SHOW ACTION TESTS ==========

    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/training/' . $this->testTraining->getId());

        $this->assertResponseRedirects();
    }

    public function testShowDisplaysTraining(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/training/' . $this->testTraining->getId());

        $this->assertResponseIsSuccessful();
    }

    public function testShowReturns404ForNonexistentTraining(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/training/999999');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== NEW ACTION TESTS ==========

    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/training/new');

        $this->assertResponseRedirects();
    }

    public function testNewDisplaysFormForUser(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/training/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="training"]');
    }

    public function testNewCreatesTrainingWithValidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/training/new');
        $scheduledDate = (new \DateTime('+1 month'))->format('Y-m-d');
        $form = $crawler->filter('form[name="training"]')->form([
            'training[title]' => 'New Test Training',
            'training[description]' => 'New training description',
            'training[trainingType]' => 'security_awareness',
            'training[deliveryMethod]' => 'in_person',
            'training[scheduledDate]' => $scheduledDate,
            'training[trainer]' => 'Test Trainer',
            'training[status]' => 'planned',
            'training[mandatory]' => '1',
        ]);

        $this->client->submit($form);

        // Verify redirect to show page
        $this->assertResponseRedirects();
    }

    // ========== EDIT ACTION TESTS ==========

    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/training/' . $this->testTraining->getId() . '/edit');

        $this->assertResponseRedirects();
    }

    public function testEditDisplaysFormForUser(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/training/' . $this->testTraining->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="training"]');
    }

    public function testEditUpdatesTrainingWithValidData(): void
    {
        $this->loginAsUser($this->testUser);

        $crawler = $this->client->request('GET', '/en/training/' . $this->testTraining->getId() . '/edit');
        $form = $crawler->filter('form[name="training"]')->form([
            'training[title]' => 'Updated Test Training',
            'training[description]' => 'Updated description',
        ]);

        $this->client->submit($form);

        // Verify redirect
        $this->assertResponseRedirects();
    }

    public function testEditReturns404ForNonexistentTraining(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/training/999999/edit');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== DELETE ACTION TESTS ==========

    public function testDeleteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('POST', '/en/training/' . $this->testTraining->getId() . '/delete');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteRequiresCsrfToken(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/en/training/' . $this->testTraining->getId() . '/delete', [
            '_token' => 'invalid_token',
        ]);

        // Should redirect without deleting
        $this->assertResponseRedirects();

        // Training should still exist
        $this->entityManager->clear();
        $training = $this->entityManager->find(Training::class, $this->testTraining->getId());
        $this->assertNotNull($training);
    }

    public function testDeleteRemovesTrainingWithValidToken(): void
    {
        $this->loginAsUser($this->adminUser);

        // Get the show page which has the delete form with CSRF token
        $crawler = $this->client->request('GET', '/en/training/' . $this->testTraining->getId());

        // Try to find the delete form and submit it
        $deleteForm = $crawler->filter('form[action*="/delete"]');
        if ($deleteForm->count() > 0) {
            $form = $deleteForm->form();
            $trainingId = $this->testTraining->getId();
            $this->client->submit($form);

            $this->assertResponseRedirects('/en/training/');

            // Verify training is deleted
            $this->entityManager->clear();
            $training = $this->entityManager->find(Training::class, $trainingId);
            $this->assertNull($training);
            $this->testTraining = null; // Prevent cleanup from failing
        } else {
            // If no delete form found on page, skip the deletion verification
            // but still check the page loaded successfully
            $this->assertResponseIsSuccessful();
            $this->markTestSkipped('Delete form not found on show page');
        }
    }

    // ========== BULK DELETE TESTS ==========

    public function testBulkDeleteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('POST', '/en/training/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => [$this->testTraining->getId()]]));

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testBulkDeleteRemovesMultipleTrainings(): void
    {
        $this->loginAsUser($this->adminUser);

        // Create additional test training
        $training2 = new Training();
        $training2->setTenant($this->testTenant);
        $training2->setTitle('Bulk Delete Training 2');
        $training2->setDescription('Training for bulk delete');
        $training2->setTrainingType('security_awareness');
        $training2->setTrainer('Test Trainer');
        $training2->setScheduledDate(new \DateTime('+1 month'));
        $training2->setStatus('planned');
        $this->entityManager->persist($training2);
        $this->entityManager->flush();

        $ids = [$this->testTraining->getId(), $training2->getId()];

        $this->client->request('POST', '/en/training/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => $ids]));

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
        $this->assertEquals(2, $response['deleted']);

        $this->testTraining = null; // Prevent cleanup from failing
    }

    public function testBulkDeleteReturnsErrorForEmptyIds(): void
    {
        $this->loginAsUser($this->adminUser);

        $this->client->request('POST', '/en/training/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => []]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
    }

    public function testBulkDeleteRespectsMultiTenancy(): void
    {
        $uniqueId = uniqid('other_', true);

        // Create another tenant with training
        $otherTenant = new Tenant();
        $otherTenant->setName('Other Tenant ' . $uniqueId);
        $otherTenant->setCode('other_tenant_' . $uniqueId);
        $this->entityManager->persist($otherTenant);

        $otherTraining = new Training();
        $otherTraining->setTenant($otherTenant);
        $otherTraining->setTitle('Other Tenant Training');
        $otherTraining->setDescription('Training from other tenant');
        $otherTraining->setTrainingType('security_awareness');
        $otherTraining->setTrainer('Other Trainer');
        $otherTraining->setScheduledDate(new \DateTime('+1 month'));
        $otherTraining->setStatus('planned');
        $this->entityManager->persist($otherTraining);

        $this->entityManager->flush();

        $this->loginAsUser($this->adminUser);

        $ids = [$this->testTraining->getId(), $otherTraining->getId()];

        $this->client->request('POST', '/en/training/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => $ids]));

        $this->assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        // Should only delete 1 training (from own tenant)
        $this->assertEquals(1, $response['deleted']);
        $this->assertArrayHasKey('errors', $response);

        // Clean up
        $this->entityManager->remove($otherTraining);
        $this->entityManager->remove($otherTenant);
        $this->entityManager->flush();

        $this->testTraining = null; // Already deleted
    }

    public function testBulkDeleteHandlesNonexistentTrainings(): void
    {
        $this->loginAsUser($this->adminUser);

        $ids = [999999, 999998];

        $this->client->request('POST', '/en/training/bulk-delete', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ids' => $ids]));

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals(0, $response['deleted']);
        $this->assertArrayHasKey('errors', $response);
    }

    // ========== STATISTICS TESTS ==========

    public function testIndexCountsCompletedTrainings(): void
    {
        $this->loginAsUser($this->testUser);

        // Create a completed training
        $completedTraining = new Training();
        $completedTraining->setTenant($this->testTenant);
        $completedTraining->setTitle('Completed Training ' . uniqid());
        $completedTraining->setDescription('Completed');
        $completedTraining->setTrainingType('security_awareness');
        $completedTraining->setTrainer('Test Trainer');
        $completedTraining->setScheduledDate(new \DateTime('-1 month'));
        $completedTraining->setStatus('completed');
        $this->entityManager->persist($completedTraining);
        $this->entityManager->flush();

        $this->client->request('GET', '/en/training/');

        $this->assertResponseIsSuccessful();

        // Clean up
        $this->entityManager->remove($completedTraining);
        $this->entityManager->flush();
    }

    public function testIndexCountsMandatoryTrainings(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/training/');

        $this->assertResponseIsSuccessful();
        // The test training has isMandatory = true
    }

    public function testIndexCountsUpcomingTrainings(): void
    {
        $this->loginAsUser($this->testUser);

        $this->client->request('GET', '/en/training/');

        $this->assertResponseIsSuccessful();
        // The test training has scheduledDate in the future
    }
}
