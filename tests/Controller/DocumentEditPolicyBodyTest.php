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
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Editable-policy-body integration in {@see DocumentController::edit}.
 *
 * Three behavioural facets:
 *   1. when the form has a `policyBody` field AND the user actually
 *      changed the persisted body, the controller stamps editedAt /
 *      editedBy AND emits a `policy_body_edited` audit-log event with
 *      the chars-delta payload.
 *   2. saving the form WITHOUT touching the textarea (no diff against
 *      the persisted body) MUST NOT emit the audit event nor stamp
 *      the edit-tracking columns.
 *   3. controller still respects the existing access-control gates —
 *      the new field never exposes a bypass for non-editable docs.
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentEditPolicyBodyTest extends TestCase
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
    private MockObject $twig;
    private MockObject $formFactory;
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
        $this->twig = $this->createMock(Environment::class);
        $this->formFactory = $this->createMock(FormFactoryInterface::class);
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->urlGenerator->method('generate')->willReturn('/dummy-url');
        $this->authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->authorizationChecker->method('isGranted')->willReturn(true);
        $this->auditLogger = $this->createMock(AuditLogger::class);

        $this->session = new Session(new MockArraySessionStorage(), null, new FlashBag());
        $this->requestStack = new RequestStack();

        $this->container->method('has')->willReturnCallback(static fn (string $id): bool => in_array($id, [
            'twig',
            'form.factory',
            'security.authorization_checker',
            'security.csrf.token_manager',
            'request_stack',
            'router',
        ], true));
        $this->container->method('get')->willReturnCallback(fn (string $id) => match ($id) {
            'twig' => $this->twig,
            'form.factory' => $this->formFactory,
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
    public function testEditPersistsPolicyBodyAndEmitsAuditLog(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(1, $tenant);
        $document = $this->makeWizardGeneratedDocument(101, $tenant, oldBody: '# Wizard baseline');

        $this->security->method('getUser')->willReturn($user);
        $this->documentService->method('canEditDocument')->willReturn(true);

        // Simulate the form binding the new (different) policyBody
        // value onto the entity. handleRequest stamps the new value
        // BEFORE the controller checks for a diff.
        $form = $this->createMock(FormInterface::class);
        $form->method('handleRequest')->willReturnCallback(function () use ($document, &$form) {
            $document->setPolicyBody('# Tenant override — ABC GmbH appends its own clause.');
            return $form;
        });
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $form->method('has')->willReturnCallback(static fn (string $name): bool => $name === 'policyBody');
        $form->method('createView')->willReturn($this->createMock(FormView::class));
        $this->formFactory->method('create')->willReturn($form);

        // Audit-trail entry MUST fire exactly once with action key
        // policy_body_edited and the chars-delta payload.
        $this->auditLogger->expects($this->once())
            ->method('logCustom')
            ->with(
                'policy_body_edited',
                'Document',
                101,
                $this->callback(static fn (?array $old): bool => isset($old['policy_body_chars']) && $old['policy_body_chars'] === strlen('# Wizard baseline')),
                $this->callback(static fn (?array $new): bool =>
                    isset($new['policy_body_chars'], $new['chars_delta'], $new['cleared'])
                    && $new['cleared'] === false
                    && $new['chars_delta'] > 0
                ),
                $this->callback(static fn (?string $description): bool => is_string($description) && $description !== ''),
            );

        $this->entityManager->expects($this->once())->method('flush');

        $request = $this->makeRequest();
        $response = $this->controller->edit($request, $document);

        self::assertEquals(302, $response->getStatusCode());
        self::assertNotNull($document->getPolicyBodyEditedAt());
        self::assertSame($user, $document->getPolicyBodyEditedBy());
    }

    #[Test]
    public function testEditDoesNotEmitAuditWhenPolicyBodyUnchanged(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(1, $tenant);
        $document = $this->makeWizardGeneratedDocument(102, $tenant, oldBody: '# Untouched');

        $this->security->method('getUser')->willReturn($user);
        $this->documentService->method('canEditDocument')->willReturn(true);

        // Form binds the SAME body — no-op for the policy-body audit.
        $form = $this->createMock(FormInterface::class);
        $form->method('handleRequest')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $form->method('has')->willReturnCallback(static fn (string $name): bool => $name === 'policyBody');
        $form->method('createView')->willReturn($this->createMock(FormView::class));
        $this->formFactory->method('create')->willReturn($form);

        $this->auditLogger->expects($this->never())->method('logCustom');

        $request = $this->makeRequest();
        $response = $this->controller->edit($request, $document);

        self::assertEquals(302, $response->getStatusCode());
        self::assertNull($document->getPolicyBodyEditedAt());
        self::assertNull($document->getPolicyBodyEditedBy());
    }

    #[Test]
    public function testEditHonoursAccessControlForNonEditableInheritedDoc(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(1, $tenant);
        $document = $this->makeWizardGeneratedDocument(103, $tenant, oldBody: '# Inherited');

        $this->security->method('getUser')->willReturn($user);
        // Inherited from holding → tenant cannot edit. The new
        // policy-body field MUST NOT introduce a bypass — the
        // controller short-circuits with a redirect to show().
        $this->documentService->method('canEditDocument')->willReturn(false);

        $this->auditLogger->expects($this->never())->method('logCustom');
        $this->entityManager->expects($this->never())->method('flush');

        $request = $this->makeRequest();
        $response = $this->controller->edit($request, $document);

        self::assertEquals(302, $response->getStatusCode());
        self::assertNull($document->getPolicyBodyEditedAt());
    }

    // ───────────────────────── helpers ──────────────────────────────────

    private function makeRequest(): Request
    {
        $req = new Request();
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
        $user->method('getFullName')->willReturn('Test User');
        $user->method('getEmail')->willReturn('test@example.com');
        return $user;
    }

    private function makeWizardGeneratedDocument(int $id, Tenant $tenant, string $oldBody): Document
    {
        // Real Document so the controller sees the unmocked
        // setPolicyBody* + hasPostGenerationEdits behaviour.
        $doc = new Document();
        $doc->setTenant($tenant);
        $doc->setFilename('p.md');
        $doc->setOriginalFilename('p.md');
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(1);
        $doc->setFilePath('virtual:test');
        $doc->setCategory('policy');
        $doc->setStatus('draft');
        $doc->setUploadedAt(new DateTimeImmutable());
        $doc->setPolicyBody($oldBody);

        $ref = new \ReflectionProperty(Document::class, 'id');
        $ref->setValue($doc, $id);
        return $doc;
    }
}
