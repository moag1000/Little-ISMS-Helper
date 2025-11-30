<?php

namespace App\Tests\Controller;

use App\Controller\StatementOfApplicabilityController;
use App\Entity\Control;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\ControlType;
use App\Repository\ControlRepository;
use App\Service\SoAReportService;
use App\Service\WorkflowAutoProgressionService;
use DateTime;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Unit tests for StatementOfApplicabilityController
 *
 * Tests all public action methods for Control (ISO 27001) management
 * including access control, tenant filtering, and report generation.
 */
class StatementOfApplicabilityControllerTest extends TestCase
{
    private MockObject $controlRepository;
    private MockObject $entityManager;
    private MockObject $translator;
    private MockObject $soaReportService;
    private MockObject $security;
    private MockObject $workflowAutoProgressionService;
    private MockObject $container;
    private MockObject $twig;
    private MockObject $formFactory;
    private MockObject $csrfTokenManager;
    private MockObject $urlGenerator;
    private RequestStack $requestStack;
    private SessionInterface $session;
    private StatementOfApplicabilityController $controller;

    protected function setUp(): void
    {
        $this->controlRepository = $this->createMock(ControlRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->soaReportService = $this->createMock(SoAReportService::class);
        $this->security = $this->createMock(Security::class);
        $this->workflowAutoProgressionService = $this->createMock(WorkflowAutoProgressionService::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->twig = $this->createMock(Environment::class);
        $this->formFactory = $this->createMock(FormFactoryInterface::class);
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        // Create session with flash bag support
        $storage = new MockArraySessionStorage();
        $this->session = new Session($storage, null, new FlashBag());

        // Create request stack
        $this->requestStack = new RequestStack();

        // Configure CSRF token manager to always return true for testing
        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);

        // Configure URL generator to return dummy URLs
        $this->urlGenerator->method('generate')->willReturn('/soa/1');

        // Configure container to return mocked services
        $this->container->method('has')->willReturnCallback(function ($id) {
            return in_array($id, [
                'twig',
                'form.factory',
                'security.authorization_checker',
                'security.csrf.token_manager',
                'request_stack',
                'router',
            ]);
        });

        $this->container->method('get')->willReturnCallback(function ($id) {
            return match ($id) {
                'twig' => $this->twig,
                'form.factory' => $this->formFactory,
                'security.authorization_checker' => $this->createMock(AuthorizationCheckerInterface::class),
                'security.csrf.token_manager' => $this->csrfTokenManager,
                'request_stack' => $this->requestStack,
                'router' => $this->urlGenerator,
                default => null,
            };
        });

        $this->controller = new StatementOfApplicabilityController(
            $this->controlRepository,
            $this->entityManager,
            $this->translator,
            $this->soaReportService,
            $this->security,
            $this->workflowAutoProgressionService
        );
        $this->controller->setContainer($this->container);
    }

    // ========== INDEX ACTION TESTS ==========

    /**
     * Test index action renders controls with inherited view (default)
     */
    public function testIndexWithInheritedView(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(1, $tenant);
        $controls = [
            $this->createControl(1, '5.1', 'Organizational'),
            $this->createControl(2, '8.3', 'Physical'),
        ];

        $this->security->method('getUser')->willReturn($user);
        $this->controlRepository->method('findByTenantIncludingParent')
            ->with($tenant)
            ->willReturn($controls);
        $this->controlRepository->method('getImplementationStats')->willReturn([
            'total' => 93,
            'implemented' => 45,
            'in_progress' => 20,
            'not_started' => 28,
        ]);
        $this->controlRepository->method('countByCategory')->willReturn([
            'organizational' => 37,
            'technological' => 34,
            'physical' => 14,
            'people' => 8,
        ]);

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'soa/index.html.twig',
                $this->callback(function ($params) {
                    return isset($params['controls'])
                        && isset($params['stats'])
                        && isset($params['categoryStats'])
                        && isset($params['inheritanceInfo'])
                        && $params['inheritanceInfo']['currentView'] === 'inherited';
                })
            )
            ->willReturn('rendered content');

        $request = new Request();
        $response = $this->controller->index($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test index action with 'own' view filter
     */
    public function testIndexWithOwnView(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(1, $tenant);
        $controls = [$this->createControl(1, '5.1', 'Organizational')];

        $this->security->method('getUser')->willReturn($user);
        $this->controlRepository->method('findByTenant')
            ->with($tenant)
            ->willReturn($controls);
        $this->controlRepository->method('getImplementationStats')->willReturn([]);
        $this->controlRepository->method('countByCategory')->willReturn([]);

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'soa/index.html.twig',
                $this->callback(function ($params) {
                    return $params['inheritanceInfo']['currentView'] === 'own';
                })
            )
            ->willReturn('rendered');

        $request = new Request(['view' => 'own']);
        $response = $this->controller->index($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test index action with 'subsidiaries' view filter
     */
    public function testIndexWithSubsidiariesView(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(1, $tenant);
        $controls = [$this->createControl(1, '5.1', 'Organizational')];

        $this->security->method('getUser')->willReturn($user);
        $this->controlRepository->method('findByTenantIncludingSubsidiaries')
            ->with($tenant)
            ->willReturn($controls);
        $this->controlRepository->method('getImplementationStats')->willReturn([]);
        $this->controlRepository->method('countByCategory')->willReturn([]);

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'soa/index.html.twig',
                $this->callback(function ($params) {
                    return $params['inheritanceInfo']['currentView'] === 'subsidiaries';
                })
            )
            ->willReturn('rendered');

        $request = new Request(['view' => 'subsidiaries']);
        $response = $this->controller->index($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test index action sorts controls by ISO reference (controlId)
     */
    public function testIndexSortsControlsByIsoReference(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(1, $tenant);

        // Create controls with ISO references in non-sorted order
        $control1 = $this->createControl(1, '5.10', 'Organizational');
        $control2 = $this->createControl(2, '5.2', 'Organizational');
        $control3 = $this->createControl(3, '5.1', 'Organizational');
        $controls = [$control1, $control2, $control3];

        $this->security->method('getUser')->willReturn($user);
        $this->controlRepository->method('findByTenantIncludingParent')->willReturn($controls);
        $this->controlRepository->method('getImplementationStats')->willReturn([]);
        $this->controlRepository->method('countByCategory')->willReturn([]);

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'soa/index.html.twig',
                $this->callback(function ($params) {
                    $controls = $params['controls'];
                    // Check natural sort: 5.1, 5.2, 5.10 (not 5.1, 5.10, 5.2)
                    return $controls[0]->getControlId() === '5.1'
                        && $controls[1]->getControlId() === '5.2'
                        && $controls[2]->getControlId() === '5.10';
                })
            )
            ->willReturn('rendered');

        $request = new Request();
        $this->controller->index($request);
    }

    /**
     * Test index action when user has no tenant
     */
    public function testIndexWhenUserHasNoTenant(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getTenant')->willReturn(null);
        $controls = [$this->createControl(1, '5.1', 'Organizational')];

        $this->security->method('getUser')->willReturn($user);
        $this->controlRepository->method('findAllInIsoOrder')->willReturn($controls);
        $this->controlRepository->method('getImplementationStats')->willReturn([]);
        $this->controlRepository->method('countByCategory')->willReturn([]);

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'soa/index.html.twig',
                $this->callback(function ($params) {
                    return $params['inheritanceInfo']['hasParent'] === false
                        && $params['inheritanceInfo']['hasSubsidiaries'] === false;
                })
            )
            ->willReturn('rendered');

        $request = new Request();
        $response = $this->controller->index($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test index calculates detailed statistics correctly
     */
    public function testIndexCalculatesDetailedStatistics(): void
    {
        // Set up tenant hierarchy with proper IDs
        $tenant = $this->createTenant(1);
        $parentTenant = $this->createTenant(2);
        $subsidiaryTenant = $this->createTenant(3);

        // Configure parent tenant to have the correct ID
        $parentTenant->method('getId')->willReturn(2);
        $subsidiaryTenant->method('getId')->willReturn(3);

        $tenant->method('getParent')->willReturn($parentTenant);
        $tenant->method('getSubsidiaries')->willReturn(new ArrayCollection([$subsidiaryTenant]));
        $tenant->method('getAllAncestors')->willReturn([$parentTenant]);
        $tenant->method('getAllSubsidiaries')->willReturn([$subsidiaryTenant]);

        $user = $this->createUser(1, $tenant);

        // Create controls from different tenants
        $ownControl = $this->createControl(1, '5.1', 'Organizational');
        $ownControl->setTenant($tenant);

        $inheritedControl = $this->createControl(2, '5.2', 'Organizational');
        $inheritedControl->setTenant($parentTenant);

        $subsidiaryControl = $this->createControl(3, '5.3', 'Organizational');
        $subsidiaryControl->setTenant($subsidiaryTenant);

        $controls = [$ownControl, $inheritedControl, $subsidiaryControl];

        $this->security->method('getUser')->willReturn($user);
        $this->controlRepository->method('findByTenantIncludingParent')->willReturn($controls);
        $this->controlRepository->method('getImplementationStats')->willReturn([]);
        $this->controlRepository->method('countByCategory')->willReturn([]);

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'soa/index.html.twig',
                $this->callback(function ($params) {
                    // Verify that detailed stats structure exists
                    $stats = $params['detailedStats'];
                    return isset($stats['own'])
                        && isset($stats['inherited'])
                        && isset($stats['subsidiaries'])
                        && isset($stats['total'])
                        && count($params['controls']) === 3;  // We should have 3 total controls in the list
                })
            )
            ->willReturn('rendered');

        $request = new Request();
        $response = $this->controller->index($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    // ========== BY CATEGORY ACTION TESTS ==========

    /**
     * Test byCategory action displays controls for specific category
     */
    public function testByCategoryDisplaysControlsForCategory(): void
    {
        $controls = [
            $this->createControl(1, '5.1', 'Organizational'),
            $this->createControl(2, '5.2', 'Organizational'),
        ];

        $this->controlRepository->method('findByCategoryInIsoOrder')
            ->with('organizational')
            ->willReturn($controls);

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'soa/category.html.twig',
                $this->callback(function ($params) {
                    return $params['category'] === 'organizational'
                        && count($params['controls']) === 2;
                })
            )
            ->willReturn('rendered');

        $response = $this->controller->byCategory('organizational');

        $this->assertInstanceOf(Response::class, $response);
    }

    // ========== EXPORT ACTION TESTS ==========

    /**
     * Test export action generates HTML export
     */
    public function testExportGeneratesHtmlExport(): void
    {
        $controls = [
            $this->createControl(1, '5.1', 'Organizational'),
            $this->createControl(2, '8.3', 'Physical'),
        ];

        $this->controlRepository->method('findAllInIsoOrder')->willReturn($controls);

        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->once())->method('save');

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'soa/export.html.twig',
                $this->callback(function ($params) {
                    return count($params['controls']) === 2
                        && $params['generatedAt'] instanceof DateTime;
                })
            )
            ->willReturn('rendered');

        $request = new Request();
        $request->setSession($session);

        $response = $this->controller->export($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test export action saves session before export
     */
    public function testExportSavesSessionBeforeExport(): void
    {
        $controls = [$this->createControl(1, '5.1', 'Organizational')];

        $this->controlRepository->method('findAllInIsoOrder')->willReturn($controls);

        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->once())->method('save');

        $this->twig->method('render')->willReturn('rendered');

        $request = new Request();
        $request->setSession($session);

        $this->controller->export($request);
    }

    // ========== EXPORT PDF TESTS ==========

    /**
     * Test exportPdf action generates PDF download
     */
    public function testExportPdfGeneratesPdfDownload(): void
    {
        $pdfResponse = new Response('PDF content', 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="soa-report.pdf"',
        ]);

        $this->soaReportService->method('downloadSoAReport')->willReturn($pdfResponse);

        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->once())->method('save');

        $request = new Request();
        $request->setSession($session);

        $response = $this->controller->exportPdf($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
    }

    /**
     * Test exportPdf closes session before PDF generation
     */
    public function testExportPdfClosesSessionBeforeGeneration(): void
    {
        $pdfResponse = new Response('PDF content');

        $this->soaReportService->method('downloadSoAReport')->willReturn($pdfResponse);

        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->once())->method('save');

        $request = new Request();
        $request->setSession($session);

        $this->controller->exportPdf($request);
    }

    // ========== PREVIEW PDF TESTS ==========

    /**
     * Test previewPdf action streams PDF for browser preview
     */
    public function testPreviewPdfStreamsPdfInBrowser(): void
    {
        $pdfResponse = new Response('PDF content', 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="soa-report.pdf"',
        ]);

        $this->soaReportService->method('streamSoAReport')->willReturn($pdfResponse);

        $response = $this->controller->previewPdf();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    // ========== SHOW ACTION TESTS ==========

    /**
     * Test show action displays control details
     */
    public function testShowDisplaysControlDetails(): void
    {
        $control = $this->createControl(1, '5.1', 'Organizational');

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'soa/show.html.twig',
                $this->callback(function ($params) use ($control) {
                    return $params['control'] === $control;
                })
            )
            ->willReturn('rendered');

        $response = $this->controller->show($control);

        $this->assertInstanceOf(Response::class, $response);
    }

    // ========== EDIT ACTION TESTS ==========

    /**
     * Test edit action renders form with control ID readonly
     */
    public function testEditRendersFormWithReadonlyControlId(): void
    {
        $control = $this->createControl(1, '5.1', 'Organizational');
        $form = $this->createMock(FormInterface::class);
        $formView = $this->createMock(\Symfony\Component\Form\FormView::class);

        $this->formFactory->expects($this->once())
            ->method('create')
            ->with(
                ControlType::class,
                $control,
                ['allow_control_id_edit' => false]
            )
            ->willReturn($form);

        $form->method('handleRequest')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(false);
        $form->method('createView')->willReturn($formView);

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'soa/edit.html.twig',
                $this->callback(function ($params) use ($control) {
                    return $params['control'] === $control
                        && isset($params['form']);
                })
            )
            ->willReturn('rendered');

        $request = new Request();
        $response = $this->controller->edit($request, $control);

        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test edit action updates control successfully
     */
    public function testEditUpdatesControlSuccessfully(): void
    {
        $control = $this->createControl(1, '5.1', 'Organizational');
        $form = $this->createMock(FormInterface::class);

        $this->formFactory->method('create')->willReturn($form);
        $form->method('handleRequest')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $this->entityManager->expects($this->once())->method('flush');

        $this->translator->expects($this->once())
            ->method('trans')
            ->with('control.success.updated', [], 'control')
            ->willReturn('Control updated successfully');

        $request = $this->createRequestWithSession();
        $response = $this->controller->edit($request, $control);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(302, $response->getStatusCode());
    }

    /**
     * Test edit action updates control's updatedAt timestamp
     */
    public function testEditUpdatesControlTimestamp(): void
    {
        $control = new Control();
        $control->setControlId('5.1');
        $control->setName('Test Control');
        $control->setDescription('Test Description');
        $control->setCategory('organizational');
        $control->setApplicable(true);
        $control->setImplementationStatus('not_started');

        $form = $this->createMock(FormInterface::class);

        $this->formFactory->method('create')->willReturn($form);
        $form->method('handleRequest')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $this->entityManager->expects($this->once())->method('flush');
        $this->translator->method('trans')->willReturn('Updated');

        $request = $this->createRequestWithSession();
        $this->controller->edit($request, $control);

        $this->assertInstanceOf(DateTimeImmutable::class, $control->getUpdatedAt());
    }

    /**
     * Test edit action redirects to show page after successful update
     */
    public function testEditRedirectsToShowPageAfterUpdate(): void
    {
        $control = $this->createControl(1, '5.1', 'Organizational');
        $form = $this->createMock(FormInterface::class);

        $this->formFactory->method('create')->willReturn($form);
        $form->method('handleRequest')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $this->entityManager->method('flush');
        $this->translator->method('trans')->willReturn('Updated');

        $this->urlGenerator->expects($this->once())
            ->method('generate')
            ->with('app_soa_show', ['id' => 1])
            ->willReturn('/soa/1');

        $request = $this->createRequestWithSession();
        $response = $this->controller->edit($request, $control);

        $this->assertEquals(302, $response->getStatusCode());
    }

    /**
     * Test edit action re-renders form on validation error
     */
    public function testEditReRendersFormOnValidationError(): void
    {
        $control = $this->createControl(1, '5.1', 'Organizational');
        $form = $this->createMock(FormInterface::class);
        $formView = $this->createMock(\Symfony\Component\Form\FormView::class);

        $this->formFactory->method('create')->willReturn($form);
        $form->method('handleRequest')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(false);
        $form->method('createView')->willReturn($formView);

        $this->entityManager->expects($this->never())->method('flush');

        $this->twig->expects($this->once())
            ->method('render')
            ->with('soa/edit.html.twig')
            ->willReturn('rendered');

        $request = new Request();
        $response = $this->controller->edit($request, $control);

        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test edit action adds flash message on success
     */
    public function testEditAddsFlashMessageOnSuccess(): void
    {
        $control = $this->createControl(1, '5.1', 'Organizational');
        $form = $this->createMock(FormInterface::class);

        $this->formFactory->method('create')->willReturn($form);
        $form->method('handleRequest')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $this->entityManager->method('flush');
        $this->translator->method('trans')->willReturn('Control updated successfully');

        $request = $this->createRequestWithSession();
        $this->controller->edit($request, $control);

        $flashBag = $this->session->getFlashBag();
        $successMessages = $flashBag->get('success');

        $this->assertCount(1, $successMessages);
        $this->assertEquals('Control updated successfully', $successMessages[0]);
    }

    // ========== HELPER METHODS ==========

    /**
     * Create a Request with session support
     */
    private function createRequestWithSession(array $query = [], array $request = []): Request
    {
        $req = new Request($query, $request);
        $req->setSession($this->session);

        // Push the request onto the request stack so the controller can access it
        $this->requestStack->push($req);

        return $req;
    }

    /**
     * Create a mock Tenant
     */
    private function createTenant(int $id): Tenant|MockObject
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getParent')->willReturn(null);
        $tenant->method('getSubsidiaries')->willReturn(new ArrayCollection());
        $tenant->method('getAllAncestors')->willReturn([]);
        $tenant->method('getAllSubsidiaries')->willReturn([]);
        return $tenant;
    }

    /**
     * Create a mock User
     */
    private function createUser(int $id, Tenant $tenant): User|MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getTenant')->willReturn($tenant);
        $user->method('getFirstName')->willReturn('Test');
        $user->method('getLastName')->willReturn('User');
        $user->method('getEmail')->willReturn('test@example.com');
        return $user;
    }

    /**
     * Create a mock Control
     */
    private function createControl(int $id, string $isoRef, string $category): Control
    {
        // Use a real Control object instead of mock to support all method calls
        $control = new Control();
        $control->setControlId($isoRef);
        $control->setName("Control {$isoRef}");
        $control->setDescription("Description for {$isoRef}");
        $control->setCategory(strtolower($category));
        $control->setApplicable(true);
        $control->setImplementationStatus('not_started');
        $control->setImplementationPercentage(0);

        // Use reflection to set the ID (normally set by Doctrine)
        // In PHP 8.5+, properties are accessible by default in reflection
        $reflection = new \ReflectionClass($control);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($control, $id);

        return $control;
    }
}
