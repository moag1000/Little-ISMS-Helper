<?php

declare(strict_types=1);

namespace App\Tests\Controller\Component;

use App\Controller\Component\PoliciesYouOwnWidgetController;
use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for {@see PoliciesYouOwnWidgetController}.
 *
 * Closes the W7-E "Policies you own" widget gap. Verifies:
 *   - documents authored by the user are surfaced;
 *   - documents whose source PolicyTemplate.affectedFunctions intersect
 *     the user's roles are surfaced (Risk-Owner P1 — function-owner slot);
 *   - the widget responds with empty content when no ownership exists.
 */
final class PoliciesYouOwnWidgetControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private PoliciesYouOwnWidgetController $controller;
    private ?Tenant $tenant = null;
    /** @var list<int> */
    private array $createdDocumentIds = [];
    /** @var list<int> */
    private array $createdTemplateIds = [];
    /** @var list<int> */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
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

        $this->controller = $container->get(PoliciesYouOwnWidgetController::class);

        $lockFile = $container->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lockFile)) {
            @file_put_contents($lockFile, date('c'));
        }

        $this->createTenant();
    }

    protected function tearDown(): void
    {
        if (!isset($this->entityManager)) {
            parent::tearDown();
            return;
        }

        try {
            foreach ($this->createdDocumentIds as $id) {
                $document = $this->entityManager->find(Document::class, $id);
                if ($document !== null) {
                    $this->entityManager->remove($document);
                }
            }
            $this->entityManager->flush();

            foreach ($this->createdTemplateIds as $id) {
                $template = $this->entityManager->find(PolicyTemplate::class, $id);
                if ($template !== null) {
                    $this->entityManager->remove($template);
                }
            }
            $this->entityManager->flush();

            foreach ($this->createdUserIds as $id) {
                $user = $this->entityManager->find(User::class, $id);
                if ($user !== null) {
                    $this->entityManager->remove($user);
                }
            }
            $this->entityManager->flush();

            if ($this->tenant !== null && $this->tenant->getId() !== null) {
                $tenant = $this->entityManager->find(Tenant::class, $this->tenant->getId());
                if ($tenant !== null) {
                    $this->entityManager->remove($tenant);
                    $this->entityManager->flush();
                }
            }
        } catch (\Throwable) {
            // Best-effort cleanup; some referential FKs may need cascades
            // outside the scope of this test.
        }

        parent::tearDown();
    }

    private function createTenant(): void
    {
        $unique = uniqid('w7e_own_', true);
        $this->tenant = new Tenant();
        $this->tenant->setName('W7E Tenant ' . $unique);
        $this->tenant->setCode(substr('w7e_' . $unique, 0, 32));
        $this->entityManager->persist($this->tenant);
        $this->entityManager->flush();
    }

    private function createUser(string $suffix, array $roles = ['ROLE_USER']): User
    {
        $unique = uniqid('w7e_u_' . $suffix . '_', true);
        $user = new User();
        $user->setEmail($unique . '@example.test');
        $user->setFirstName('User');
        $user->setLastName($suffix);
        $user->setPassword('hashed');
        $user->setRoles($roles);
        $user->setTenant($this->tenant);
        $user->setIsActive(true);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $userId = $user->getId();
        if ($userId !== null) {
            $this->createdUserIds[] = $userId;
        }
        return $user;
    }

    private function createDocument(?User $owner = null, ?PolicyTemplate $template = null, string $status = 'approved'): Document
    {
        $unique = uniqid('w7e_doc_', true);
        $document = new Document();
        $document->setTenant($this->tenant);
        $document->setFilename($unique . '.md');
        $document->setOriginalFilename($unique . '.md');
        $document->setMimeType('text/markdown');
        $document->setFileSize(123);
        $document->setFilePath('virtual:' . $unique);
        $document->setCategory('policy');
        $document->setDescription('Test policy for ' . $unique);
        $document->setStatus($status);
        $document->setUploadedAt(new DateTimeImmutable());
        $document->setUploadedBy($owner);
        if ($template !== null) {
            $document->setGeneratedFromTemplate($template);
        }
        $this->entityManager->persist($document);
        $this->entityManager->flush();
        $docId = $document->getId();
        if ($docId !== null) {
            $this->createdDocumentIds[] = $docId;
        }
        return $document;
    }

    private function createTemplate(array $affectedFunctions): PolicyTemplate
    {
        $unique = uniqid('w7e_t_', true);
        $template = new PolicyTemplate();
        $template->setKey('test.' . $unique);
        $template->setStandard('iso27001');
        $template->setTopic('test_topic');
        $template->setDocumentType('policy');
        $template->setTitleTranslationKey('test.title.' . $unique);
        $template->setBodyTranslationKey('test.body.' . $unique);
        $template->setAffectedFunctions($affectedFunctions);

        try {
            $this->entityManager->persist($template);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            // Test DB schema may be behind on the W5 BSI tier columns.
            // The W7-E test is independent of the BSI tier semantics — skip
            // gracefully when the schema is missing the dependency.
            if (str_contains($e->getMessage(), 'linked_bsi_bausteine')
                || str_contains($e->getMessage(), 'bsi_tier')
                || str_contains($e->getMessage(), 'iso27701_clauses')
            ) {
                $this->markTestSkipped(
                    'PolicyTemplate schema not migrated in test DB: ' . $e->getMessage(),
                );
            }
            throw $e;
        }

        $tplId = $template->getId();
        if ($tplId !== null) {
            $this->createdTemplateIds[] = $tplId;
        }
        return $template;
    }

    // ============================================================
    // Tests
    // ============================================================

    #[Test]
    public function testListsOwnedDocuments(): void
    {
        $owner = $this->createUser('owner');
        $other = $this->createUser('other');

        $owned = $this->createDocument(owner: $owner);
        $this->createDocument(owner: $other); // noise

        try {
            $documents = $this->controller->ownedDocuments($this->tenant, $owner);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'linked_bsi_bausteine')
                || str_contains($e->getMessage(), 'bsi_tier')
                || str_contains($e->getMessage(), 'iso27701_clauses')
            ) {
                $this->markTestSkipped(
                    'PolicyTemplate join column missing in test DB: ' . $e->getMessage(),
                );
            }
            throw $e;
        }

        $foundIds = array_map(static fn (Document $d): ?int => $d->getId(), $documents);
        self::assertContains($owned->getId(), $foundIds, 'Documents authored by user must surface in the widget.');
    }

    #[Test]
    public function testListsAffectedFunctionDocuments(): void
    {
        $hrUser = $this->createUser('hr', ['ROLE_USER', 'ROLE_HR']);
        $template = $this->createTemplate(['HR']);
        $document = $this->createDocument(owner: null, template: $template);

        try {
            $documents = $this->controller->ownedDocuments($this->tenant, $hrUser);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'linked_bsi_bausteine')
                || str_contains($e->getMessage(), 'bsi_tier')
                || str_contains($e->getMessage(), 'iso27701_clauses')
            ) {
                $this->markTestSkipped(
                    'PolicyTemplate join column missing in test DB: ' . $e->getMessage(),
                );
            }
            throw $e;
        }

        $foundIds = array_map(static fn (Document $d): ?int => $d->getId(), $documents);
        self::assertContains(
            $document->getId(),
            $foundIds,
            'Documents whose source-template affectedFunctions match the user roles must surface.',
        );
    }

    #[Test]
    public function testEmptyStateWhenNoOwnership(): void
    {
        $stranger = $this->createUser('stranger');
        $someoneElse = $this->createUser('else');
        $template = $this->createTemplate(['IT_OPERATIONS']); // role stranger does not have

        $this->createDocument(owner: $someoneElse, template: $template);

        try {
            $documents = $this->controller->ownedDocuments($this->tenant, $stranger);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'linked_bsi_bausteine')
                || str_contains($e->getMessage(), 'bsi_tier')
                || str_contains($e->getMessage(), 'iso27701_clauses')
            ) {
                $this->markTestSkipped(
                    'PolicyTemplate join column missing in test DB: ' . $e->getMessage(),
                );
            }
            throw $e;
        }

        self::assertSame(
            [],
            $documents,
            'A user who is neither uploader nor function-owner must see no policies.',
        );
    }
}
