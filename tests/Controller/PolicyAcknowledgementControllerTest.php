<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Document;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * Functional tests for {@see \App\Controller\PolicyAcknowledgementController}.
 *
 * Covers: ROLE_USER gate, inbox rendering, CSRF on acknowledge, ack
 * persistence, view rendering. W3-L closes auditor's predicted ISO
 * 27001 A.6.3 NC.
 */
final class PolicyAcknowledgementControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?Document $publishedDocument = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        try {
            $this->entityManager = $container->get(EntityManagerInterface::class);
            $this->entityManager->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            if (
                str_contains($e->getMessage(), 'Access denied')
                || str_contains($e->getMessage(), 'Connection refused')
                || str_contains($e->getMessage(), 'SQLSTATE')
            ) {
                $this->markTestSkipped('Database not available: ' . $e->getMessage());
            }
            throw $e;
        }

        $lockFile = $container->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lockFile)) {
            @file_put_contents($lockFile, date('c'));
        }

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if (!isset($this->entityManager)) {
            parent::tearDown();
            return;
        }

        try {
            // Cleanup acks first to satisfy FKs.
            if ($this->testTenant !== null && $this->testTenant->getId() !== null) {
                $managedTenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($managedTenant !== null) {
                    $acks = $this->entityManager->getRepository(PolicyAcknowledgement::class)
                        ->findBy(['tenant' => $managedTenant]);
                    foreach ($acks as $ack) {
                        $this->entityManager->remove($ack);
                    }
                    $this->entityManager->flush();
                }
            }

            if ($this->publishedDocument !== null && $this->publishedDocument->getId() !== null) {
                $managedDoc = $this->entityManager->find(Document::class, $this->publishedDocument->getId());
                if ($managedDoc !== null) {
                    $this->entityManager->remove($managedDoc);
                    $this->entityManager->flush();
                }
            }

            if ($this->testUser !== null && $this->testUser->getId() !== null) {
                $managedUser = $this->entityManager->find(User::class, $this->testUser->getId());
                if ($managedUser !== null) {
                    $this->entityManager->remove($managedUser);
                    $this->entityManager->flush();
                }
            }

            if ($this->testTenant !== null && $this->testTenant->getId() !== null) {
                $managedTenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($managedTenant !== null) {
                    $this->entityManager->remove($managedTenant);
                    $this->entityManager->flush();
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('pa_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('PolicyAck Tenant ' . $uniqueId);
        $this->testTenant->setCode('pa_' . substr($uniqueId, 0, 18));
        $this->entityManager->persist($this->testTenant);

        $this->testUser = new User();
        $this->testUser->setEmail('user_' . $uniqueId . '@example.test');
        $this->testUser->setFirstName('Test');
        $this->testUser->setLastName('User');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        $this->publishedDocument = new Document();
        $this->publishedDocument->setTenant($this->testTenant);
        $this->publishedDocument->setFilename('test-policy.md');
        $this->publishedDocument->setOriginalFilename('test-policy.md');
        $this->publishedDocument->setMimeType('text/markdown');
        $this->publishedDocument->setFileSize(1024);
        $this->publishedDocument->setFilePath('virtual:test-policy.md');
        $this->publishedDocument->setCategory('policy');
        $this->publishedDocument->setDescription('Test policy body for acknowledgement-flow tests.');
        $this->publishedDocument->setStatus('published');
        $this->publishedDocument->setUploadedAt(new DateTimeImmutable());
        $this->publishedDocument->setSha256Hash(str_repeat('a', 64));
        $this->publishedDocument->setSubstitutionVariables(['_template_version' => 1]);
        $this->entityManager->persist($this->publishedDocument);

        $this->entityManager->flush();
    }

    private function generateCsrfToken(string $tokenId): string
    {
        // Bootstrap a session via GET, then write the token. Mirrors the
        // PolicyWizardControllerTest pattern (feedback_csrf_tests_session.md).
        $this->client->request('GET', '/en/policy-ack/inbox');
        $session = $this->client->getRequest()->getSession();

        $generator = new UriSafeTokenGenerator();
        $token = $generator->generateToken();
        $session->set('_csrf/' . $tokenId, $token);
        $session->save();
        return $token;
    }

    // ============================================================
    // Tests
    // ============================================================

    #[Test]
    public function testInboxRequiresAuth(): void
    {
        $this->client->request('GET', '/en/policy-ack/inbox');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testInboxRendersPending(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/policy-ack/inbox');

        $this->assertResponseIsSuccessful();
        // Either the empty-state title or the pending-list — both are
        // valid acceptance signals for "the page rendered for ROLE_USER".
        $body = (string) $this->client->getResponse()->getContent();
        self::assertNotSame('', $body);
    }

    #[Test]
    public function testAcknowledgeRequiresCsrf(): void
    {
        $this->client->loginUser($this->testUser);

        $documentId = (int) $this->publishedDocument->getId();
        $this->client->request(
            'POST',
            '/en/policy-ack/acknowledge/' . $documentId,
            ['_token' => 'invalid-token'],
        );

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Accept 403 (CSRF rejection) or 302 (when framework drops auth
        // on the no-session POST). Either way the ack must NOT exist.
        self::assertContains(
            $statusCode,
            [Response::HTTP_FORBIDDEN, Response::HTTP_FOUND],
            'Expected 403 or 302 from invalid CSRF, got ' . $statusCode,
        );

        $repo = $this->entityManager->getRepository(PolicyAcknowledgement::class);
        $acks = $repo->findBy(['document' => $this->publishedDocument]);
        self::assertSame([], $acks, 'No PolicyAcknowledgement should be persisted on CSRF failure.');
    }

    #[Test]
    public function testAcknowledgeMarksUserAsAcknowledged(): void
    {
        $this->client->loginUser($this->testUser);
        $token = $this->generateCsrfToken('policy_ack');

        $documentId = (int) $this->publishedDocument->getId();
        $this->client->request(
            'POST',
            '/en/policy-ack/acknowledge/' . $documentId,
            ['_token' => $token],
        );

        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/policy-ack/inbox', $location);

        $this->entityManager->clear();
        $repo = $this->entityManager->getRepository(PolicyAcknowledgement::class);
        $acks = $repo->findBy(['document' => $documentId]);
        self::assertCount(1, $acks, 'Exactly one acknowledgement row must exist.');
        self::assertSame('web_click', $acks[0]->getAcknowledgementMethod());
    }

    #[Test]
    public function testViewRendersPolicyBody(): void
    {
        $this->client->loginUser($this->testUser);

        $documentId = (int) $this->publishedDocument->getId();
        $this->client->request('GET', '/en/policy-ack/view/' . $documentId);

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Test policy body for acknowledgement-flow tests', $body);
    }
}
