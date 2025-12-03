<?php

namespace App\Tests\Controller;

use App\Entity\Consent;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for ConsentController
 *
 * Tests consent management including:
 * - Index with listing
 * - CRUD operations
 * - Verify and revoke actions
 */
class ConsentControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?ProcessingActivity $testProcessingActivity = null;
    private ?Consent $testConsent = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if ($this->testConsent) {
            try {
                $consent = $this->entityManager->find(Consent::class, $this->testConsent->getId());
                if ($consent) {
                    $this->entityManager->remove($consent);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($this->testProcessingActivity) {
            try {
                $pa = $this->entityManager->find(ProcessingActivity::class, $this->testProcessingActivity->getId());
                if ($pa) {
                    $this->entityManager->remove($pa);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

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
            // Ignore
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('test_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('Test Tenant ' . $uniqueId);
        $this->testTenant->setCode('test_tenant_' . $uniqueId);
        $this->entityManager->persist($this->testTenant);

        $this->testUser = new User();
        $this->testUser->setEmail('testuser_' . $uniqueId . '@example.com');
        $this->testUser->setFirstName('Test');
        $this->testUser->setLastName('User');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        $this->testProcessingActivity = new ProcessingActivity();
        $this->testProcessingActivity->setTenant($this->testTenant);
        $this->testProcessingActivity->setName('Test Processing Activity ' . $uniqueId);
        $this->testProcessingActivity->setPurposes(['testing']);
        $this->testProcessingActivity->setDataSubjectCategories(['customers']);
        $this->testProcessingActivity->setPersonalDataCategories(['identification']);
        $this->testProcessingActivity->setLegalBasis('consent');
        $this->entityManager->persist($this->testProcessingActivity);

        $this->testConsent = new Consent();
        $this->testConsent->setTenant($this->testTenant);
        $this->testConsent->setProcessingActivity($this->testProcessingActivity);
        $this->testConsent->setDataSubjectIdentifier('test-subject-123');
        $this->testConsent->setIdentifierType('email');
        $this->testConsent->setPurposes(['marketing', 'analytics']);
        $this->testConsent->setConsentMethod('email');
        $this->testConsent->setConsentText('I agree to the terms');
        $this->testConsent->setGrantedAt(new \DateTimeImmutable());
        $this->testConsent->setDocumentedAt(new \DateTimeImmutable());
        $this->testConsent->setStatus('pending');
        $this->entityManager->persist($this->testConsent);

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    // ========== INDEX TESTS ==========

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/consent/');
        $this->assertResponseRedirects();
    }

    public function testIndexDisplaysForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/consent/');
        $this->assertResponseIsSuccessful();
    }

    // ========== NEW TESTS ==========

    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/consent/new');
        $this->assertResponseRedirects();
    }

    public function testNewDisplaysFormForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $crawler = $this->client->request('GET', '/en/consent/new');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    // ========== SHOW TESTS ==========

    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/consent/' . $this->testConsent->getId());
        $this->assertResponseRedirects();
    }

    public function testShowDisplaysConsentForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/consent/' . $this->testConsent->getId());
        $this->assertResponseIsSuccessful();
    }

    public function testShowReturns404ForNonexistent(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/consent/999999');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== EDIT TESTS ==========

    public function testEditRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/consent/' . $this->testConsent->getId() . '/edit');
        $this->assertResponseRedirects();
    }

    public function testEditDisplaysFormOrRedirectsForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $crawler = $this->client->request('GET', '/en/consent/' . $this->testConsent->getId() . '/edit');
        // Edit may display form or redirect (e.g., for locked consents)
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            $statusCode === 200 || $statusCode === 302,
            'Expected success or redirect'
        );
    }

    // ========== VERIFY TESTS ==========

    public function testVerifyRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/consent/' . $this->testConsent->getId() . '/verify');
        $this->assertResponseRedirects();
    }

    public function testVerifyRequiresPost(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/consent/' . $this->testConsent->getId() . '/verify');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== REVOKE TESTS ==========

    public function testRevokeRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/consent/' . $this->testConsent->getId() . '/revoke');
        $this->assertResponseRedirects();
    }

    public function testRevokeRequiresPost(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/consent/' . $this->testConsent->getId() . '/revoke');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== DELETE TESTS ==========

    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/consent/' . $this->testConsent->getId() . '/delete');
        $this->assertResponseRedirects();
    }

    public function testDeleteRequiresPost(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/consent/' . $this->testConsent->getId() . '/delete');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }
}
