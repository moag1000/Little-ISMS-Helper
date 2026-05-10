<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DocumentController;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DocumentRepository;
use App\Service\AuditLogger;
use App\Service\DocumentService;
use App\Service\FileUploadSecurityService;
use App\Service\SecurityEventLogger;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Effectiveness-review action of {@see DocumentController::markEffectivenessReviewed}
 * (Auditor MINOR-NC reply, 2026-05-10).
 *
 * Three behavioural facets:
 *  1. happy path — fields persisted + audit-event emitted
 *  2. CSRF rejection — invalid token short-circuits with NO mutation
 *  3. SoA boundary — the action does NOT touch the document's
 *     status / SoA implementation status (the column is independent)
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentEffectivenessControllerTest extends TestCase
{
    private MockObject $documentRepository;
    private MockObject $documentService;
    private MockObject $entityManager;
    private RateLimiterFactory $rateLimiterFactory;
    private MockObject $fileUploadSecurityService;
    private MockObject $securityEventLogger;
    private MockObject $translator;
    private MockObject $security;
    private MockObject $container;
    private MockObject $csrfTokenManager;
    private MockObject $urlGenerator;
    private MockObject $authorizationChecker;
    private MockObject $auditLogger;
    private RequestStack $requestStack;
    private Session $session;
    private DocumentController $controller;

    protected function setUp(): void
    {
        $this->documentRepository = $this->createMock(DocumentRepository::class);
        $this->documentService = $this->createMock(DocumentService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->rateLimiterFactory = new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'no_limit'],
            new InMemoryStorage(),
        );
        $this->fileUploadSecurityService = $this->createMock(FileUploadSecurityService::class);
        $this->securityEventLogger = $this->createMock(SecurityEventLogger::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturn('translated');
        $this->security = $this->createMock(Security::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->urlGenerator->method('generate')->willReturn('/dummy-url');
        $this->authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->authorizationChecker->method('isGranted')->willReturn(true);
        $this->auditLogger = $this->createMock(AuditLogger::class);

        $this->session = new Session(new MockArraySessionStorage(), null, new FlashBag());
        $this->requestStack = new RequestStack();

        $this->container->method('has')->willReturnCallback(static fn (string $id): bool => in_array($id, [
            'security.authorization_checker',
            'security.csrf.token_manager',
            'request_stack',
            'router',
        ], true));
        $this->container->method('get')->willReturnCallback(fn (string $id) => match ($id) {
            'security.authorization_checker' => $this->authorizationChecker,
            'security.csrf.token_manager' => $this->csrfTokenManager,
            'request_stack' => $this->requestStack,
            'router' => $this->urlGenerator,
            default => null,
        });

        $this->controller = new DocumentController(
            $this->documentRepository,
            $this->documentService,
            $this->entityManager,
            '/tmp/test-project',
            $this->rateLimiterFactory,
            $this->fileUploadSecurityService,
            $this->securityEventLogger,
            $this->translator,
            $this->security,
            null,
            null,
            null,
            $this->auditLogger,
        );
        $this->controller->setContainer($this->container);
    }

    #[Test]
    public function testHappyPathPersistsFieldsAndEmitsAuditEvent(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(7, $tenant);
        $document = $this->makeDocument(101, $tenant, status: 'in_progress');

        $this->security->method('getUser')->willReturn($user);
        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);

        // Audit event MUST fire exactly once with the agreed contract.
        $this->auditLogger->expects($this->once())
            ->method('logCustom')
            ->with(
                'document_effectiveness_reviewed',
                'Document',
                101,
                null,
                $this->callback(static function (?array $new): bool {
                    return is_array($new)
                        && ($new['document_id'] ?? null) === 101
                        && ($new['reviewed_by_user_id'] ?? null) === 7
                        && isset($new['reviewed_at'])
                        && ($new['notes_length'] ?? null) > 0
                        && ($new['confirmed'] ?? null) === true;
                }),
                $this->callback(static fn (?string $description): bool => is_string($description) && $description !== ''),
            );

        $this->entityManager->expects($this->once())->method('flush');

        $request = $this->makeRequest([
            '_token' => 'valid',
            'notes' => 'Q1 sample audit complete; control verified.',
            'confirmed' => '1',
        ]);

        $response = $this->controller->markEffectivenessReviewed($request, $document);

        self::assertEquals(302, $response->getStatusCode());
        self::assertNotNull($document->getLastEffectivenessReviewAt());
        self::assertSame($user, $document->getLastEffectivenessReviewBy());
        self::assertSame('Q1 sample audit complete; control verified.', $document->getEffectivenessReviewNotes());
        // SoA-Status boundary: status field MUST stay untouched.
        self::assertSame('in_progress', $document->getStatus());
    }

    #[Test]
    public function testCsrfRejectionDoesNotMutateOrAudit(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(7, $tenant);
        $document = $this->makeDocument(102, $tenant);

        $this->security->method('getUser')->willReturn($user);
        $this->csrfTokenManager->method('isTokenValid')->willReturn(false);

        $this->auditLogger->expects($this->never())->method('logCustom');
        $this->entityManager->expects($this->never())->method('flush');

        $request = $this->makeRequest(['_token' => 'invalid', 'notes' => 'x']);
        $response = $this->controller->markEffectivenessReviewed($request, $document);

        self::assertEquals(302, $response->getStatusCode());
        self::assertNull($document->getLastEffectivenessReviewAt());
        self::assertNull($document->getLastEffectivenessReviewBy());
        self::assertNull($document->getEffectivenessReviewNotes());
    }

    #[Test]
    public function testEmptyNotesStoredAsNullAndAuditReportsZeroLength(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(7, $tenant);
        $document = $this->makeDocument(103, $tenant);

        $this->security->method('getUser')->willReturn($user);
        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);

        $this->auditLogger->expects($this->once())
            ->method('logCustom')
            ->with(
                'document_effectiveness_reviewed',
                'Document',
                103,
                null,
                $this->callback(static function (?array $new): bool {
                    return is_array($new)
                        && ($new['notes_length'] ?? null) === 0
                        && ($new['confirmed'] ?? null) === false;
                }),
                $this->anything(),
            );

        $this->entityManager->expects($this->once())->method('flush');

        $request = $this->makeRequest(['_token' => 'valid', 'notes' => '   ']);
        $response = $this->controller->markEffectivenessReviewed($request, $document);

        self::assertEquals(302, $response->getStatusCode());
        self::assertNull($document->getEffectivenessReviewNotes());
    }

    // ─────────────────────────── helpers ────────────────────────────────

    /**
     * @param array<string, string> $post
     */
    private function makeRequest(array $post): Request
    {
        $req = new Request(request: $post);
        $req->setMethod('POST');
        $req->setSession($this->session);
        $this->requestStack->push($req);
        return $req;
    }

    private function createTenant(int $id): Tenant
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getSubsidiaries')->willReturn(new ArrayCollection());
        return $tenant;
    }

    private function createUser(int $id, Tenant $tenant): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getTenant')->willReturn($tenant);
        $user->method('getFullName')->willReturn('Isabel ISB');
        $user->method('getEmail')->willReturn('isb@example.com');
        return $user;
    }

    private function makeDocument(int $id, Tenant $tenant, string $status = 'active'): Document
    {
        $doc = new Document();
        $doc->setTenant($tenant);
        $doc->setFilename('p.md');
        $doc->setOriginalFilename('p.md');
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(1);
        $doc->setFilePath('virtual:test');
        $doc->setCategory('policy');
        $doc->setStatus($status);
        $doc->setUploadedAt(new DateTimeImmutable());

        $ref = new \ReflectionProperty(Document::class, 'id');
        $ref->setValue($doc, $id);
        return $doc;
    }
}
