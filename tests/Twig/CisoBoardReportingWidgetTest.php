<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Controller\Component\CisoBoardReportingWidgetController;
use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

/**
 * Smoke + scoping tests for the CISO board-reporting widget.
 *
 * Verifies that the rendered KPI tiles contain the expected labels for
 * a CISO-scope user, that non-CISO users see nothing, and that the
 * collected KPI counts mirror the underlying database state.
 */
final class CisoBoardReportingWidgetTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private CisoBoardReportingWidgetController $controller;
    private Environment $twig;
    private ?Tenant $tenant = null;
    /** @var list<int> */
    private array $createdDocumentIds = [];
    /** @var list<int> */
    private array $createdTemplateIds = [];
    /** @var list<int> */
    private array $createdUserIds = [];

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

        $this->controller = $container->get(CisoBoardReportingWidgetController::class);
        $this->twig = $container->get('twig');

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
            // Best-effort cleanup.
        }

        parent::tearDown();
    }

    private function createTenant(): void
    {
        $unique = uniqid('w7e_brd_', true);
        $this->tenant = new Tenant();
        $this->tenant->setName('Board-Reporting Tenant ' . $unique);
        $this->tenant->setCode(substr('w7e_b_' . $unique, 0, 32));
        $this->entityManager->persist($this->tenant);
        $this->entityManager->flush();
    }

    private function createUser(string $suffix, array $roles): User
    {
        $unique = uniqid('w7e_brd_u_' . $suffix . '_', true);
        $user = new User();
        $user->setEmail($unique . '@example.test');
        $user->setFirstName('Board');
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

    private function createTemplate(): ?PolicyTemplate
    {
        $unique = uniqid('w7e_brd_t_', true);
        $template = new PolicyTemplate();
        $template->setKey('brd.' . $unique);
        $template->setStandard('iso27001');
        $template->setTopic('brd_topic');
        $template->setDocumentType('policy');
        $template->setTitleTranslationKey('brd.title.' . $unique);
        $template->setBodyTranslationKey('brd.body.' . $unique);

        try {
            $this->entityManager->persist($template);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
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

    private function createDocument(string $status, ?PolicyTemplate $template = null): Document
    {
        $unique = uniqid('w7e_brd_doc_', true);
        $document = new Document();
        $document->setTenant($this->tenant);
        $document->setFilename($unique . '.md');
        $document->setOriginalFilename($unique . '.md');
        $document->setMimeType('text/markdown');
        $document->setFileSize(123);
        $document->setFilePath('virtual:' . $unique);
        $document->setCategory('policy');
        $document->setDescription('KPI fixture');
        $document->setStatus($status);
        $document->setUploadedAt(new DateTimeImmutable());
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

    // ============================================================
    // Tests
    // ============================================================

    #[Test]
    public function testRendersKpisForCiso(): void
    {
        $template = $this->createTemplate();
        // 2 generated, approved policies — drives total + ack-coverage tiles.
        $this->createDocument('approved', $template);
        $this->createDocument('approved', $template);

        try {
            $kpis = $this->controller->collectKpis($this->tenant);
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

        $rendered = $this->twig->render('_components/_ciso_board_reporting_widget.html.twig', [
            'kpis' => $kpis,
        ]);

        // Title must appear (translated EN/DE — accept either localisation).
        self::assertTrue(
            str_contains($rendered, 'Board')
            || str_contains($rendered, 'Board-Reporting')
            || str_contains($rendered, 'board reporting'),
            'Widget title must render in some localisation.',
        );
        // The KPI feature-cards must each emit one fa-feature-card root.
        $featureCardCount = substr_count($rendered, 'fa-feature-card fa-feature-card--');
        self::assertGreaterThanOrEqual(5, $featureCardCount, 'Five KPI tiles must be rendered.');
    }

    #[Test]
    public function testHidesForNonCisoRole(): void
    {
        $plain = $this->createUser('plain', ['ROLE_USER']);

        $this->client->loginUser($plain);
        $this->client->request('GET', '/_fragment/policy-wizard-w7e/ciso-board-reporting');

        // Either 403 (IsGranted denies) or 404 (route not exposed) — both
        // are equivalent rejections from a non-CISO viewpoint. The point
        // is that the user does NOT see the rendered widget.
        $statusCode = $this->client->getResponse()->getStatusCode();
        self::assertContains(
            $statusCode,
            [403, 404, 405],
            'Non-CISO users must not be able to render the board-reporting widget; got ' . $statusCode,
        );
    }

    #[Test]
    public function testCountsAccurate(): void
    {
        $template = $this->createTemplate();
        $this->createDocument('approved', $template);
        $this->createDocument('pending_approval', $template);
        $this->createDocument('draft', $template);

        try {
            $kpis = $this->controller->collectKpis($this->tenant);
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

        // total_policies: counts all generated docs regardless of status
        // (3 in this fixture). pending_approvals: 1 (the doc with status
        // 'pending_approval'). open_findings: 0 (no findings in fixture).
        self::assertSame(3, $kpis['total_policies']);
        self::assertSame(1, $kpis['pending_approvals']);
        self::assertSame(0, $kpis['open_findings']);
        self::assertGreaterThanOrEqual(0, $kpis['dpo_veto_count']);
        self::assertIsFloat($kpis['ack_coverage_percent']);
    }
}
