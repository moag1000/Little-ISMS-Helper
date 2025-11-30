<?php

namespace App\Tests\Controller;

use App\Controller\IncidentController;
use App\Entity\Incident;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\ComplianceFramework;
use App\Entity\BusinessProcess;
use App\Form\IncidentType;
use App\Repository\AuditLogRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\IncidentRepository;
use App\Repository\UserRepository;
use App\Service\EmailNotificationService;
use App\Service\GdprBreachAssessmentService;
use App\Service\IncidentBCMImpactService;
use App\Service\IncidentEscalationWorkflowService;
use App\Service\IncidentRiskFeedbackService;
use App\Service\PdfExportService;
use App\Service\TenantContext;
use App\Service\WorkflowAutoProgressionService;
use App\Service\WorkflowService;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
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
 * Unit tests for IncidentController
 *
 * Tests all public action methods with proper access control,
 * service mocking, and edge case handling.
 */
class IncidentControllerTest extends TestCase
{
    private MockObject $incidentRepository;
    private MockObject $auditLogRepository;
    private MockObject $complianceFrameworkRepository;
    private MockObject $entityManager;
    private MockObject $emailNotificationService;
    private MockObject $gdprBreachAssessmentService;
    private MockObject $incidentBCMImpactService;
    private MockObject $pdfExportService;
    private MockObject $userRepository;
    private MockObject $translator;
    private MockObject $security;
    private MockObject $incidentEscalationWorkflowService;
    private MockObject $tenantContext;
    private MockObject $workflowService;
    private MockObject $workflowAutoProgressionService;
    private MockObject $incidentRiskFeedbackService;
    private MockObject $container;
    private MockObject $twig;
    private MockObject $formFactory;
    private MockObject $csrfTokenManager;
    private MockObject $urlGenerator;
    private RequestStack $requestStack;
    private SessionInterface $session;
    private IncidentController $controller;

    protected function setUp(): void
    {
        $this->incidentRepository = $this->createMock(IncidentRepository::class);
        $this->auditLogRepository = $this->createMock(AuditLogRepository::class);
        $this->complianceFrameworkRepository = $this->createMock(ComplianceFrameworkRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->emailNotificationService = $this->createMock(EmailNotificationService::class);
        $this->gdprBreachAssessmentService = $this->createMock(GdprBreachAssessmentService::class);
        $this->incidentBCMImpactService = $this->createMock(IncidentBCMImpactService::class);
        $this->pdfExportService = $this->createMock(PdfExportService::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->security = $this->createMock(Security::class);
        $this->incidentEscalationWorkflowService = $this->createMock(IncidentEscalationWorkflowService::class);
        $this->tenantContext = $this->createMock(TenantContext::class);
        $this->workflowService = $this->createMock(WorkflowService::class);
        $this->workflowAutoProgressionService = $this->createMock(WorkflowAutoProgressionService::class);
        $this->incidentRiskFeedbackService = $this->createMock(IncidentRiskFeedbackService::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->twig = $this->createMock(Environment::class);
        $this->formFactory = $this->createMock(FormFactoryInterface::class);
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        // Create session with flash bag support
        $storage = new MockArraySessionStorage();
        $this->session = new Session($storage, null, new FlashBag());

        // Create request stack with a request that has the session
        $this->requestStack = new RequestStack();

        // Configure CSRF token manager to always return true for testing
        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);

        // Configure URL generator to return dummy URLs
        $this->urlGenerator->method('generate')->willReturn('/dummy-url');

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

        $this->controller = new IncidentController(
            $this->incidentRepository,
            $this->auditLogRepository,
            $this->complianceFrameworkRepository,
            $this->entityManager,
            $this->emailNotificationService,
            $this->gdprBreachAssessmentService,
            $this->incidentBCMImpactService,
            $this->pdfExportService,
            $this->userRepository,
            $this->translator,
            $this->security,
            $this->incidentEscalationWorkflowService,
            $this->tenantContext,
            $this->workflowService,
            $this->workflowAutoProgressionService,
            $this->incidentRiskFeedbackService
        );
        $this->controller->setContainer($this->container);
    }

    /**
     * Test index action renders correctly with no filters
     */
    public function testIndexWithoutFilters(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(1, $tenant);
        $incidents = [$this->createIncident(1, 'high', 'new')];

        $this->security->method('getUser')->willReturn($user);
        $this->incidentRepository->method('findByTenantIncludingParent')
            ->with($tenant)
            ->willReturn($incidents);
        $this->incidentRepository->method('countByCategory')->willReturn([]);
        $this->incidentRepository->method('countBySeverity')->willReturn([]);

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'incident/index.html.twig',
                $this->callback(function ($params) {
                    return isset($params['allIncidents'])
                        && isset($params['openIncidents'])
                        && isset($params['inheritanceInfo']);
                })
            )
            ->willReturn('rendered content');

        $request = new Request();
        $response = $this->controller->index($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test index action with severity filter
     */
    public function testIndexWithSeverityFilter(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(1, $tenant);
        $highIncident = $this->createIncident(1, 'high', 'new');
        $lowIncident = $this->createIncident(2, 'low', 'new');

        $this->security->method('getUser')->willReturn($user);
        $this->incidentRepository->method('findByTenantIncludingParent')
            ->willReturn([$highIncident, $lowIncident]);
        $this->incidentRepository->method('countByCategory')->willReturn([]);
        $this->incidentRepository->method('countBySeverity')->willReturn([]);

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'incident/index.html.twig',
                $this->callback(function ($params) {
                    // Only high severity should be included after filtering
                    return count($params['allIncidents']) === 1
                        && $params['allIncidents'][0]->getSeverity() === 'high';
                })
            )
            ->willReturn('rendered');

        $request = new Request(['severity' => 'high']);
        $response = $this->controller->index($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test index action with data breach filter
     */
    public function testIndexWithDataBreachFilter(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(1, $tenant);
        $breachIncident = $this->createIncident(1, 'high', 'new', true);
        $normalIncident = $this->createIncident(2, 'medium', 'new', false);

        $this->security->method('getUser')->willReturn($user);
        $this->incidentRepository->method('findByTenantIncludingParent')
            ->willReturn([$breachIncident, $normalIncident]);
        $this->incidentRepository->method('countByCategory')->willReturn([]);
        $this->incidentRepository->method('countBySeverity')->willReturn([]);

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'incident/index.html.twig',
                $this->callback(function ($params) {
                    return count($params['allIncidents']) === 1
                        && $params['allIncidents'][0]->isDataBreachOccurred() === true;
                })
            )
            ->willReturn('rendered');

        $request = new Request(['data_breach_only' => '1']);
        $response = $this->controller->index($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test index action with view filter: own
     */
    public function testIndexWithViewFilterOwn(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(1, $tenant);
        $incidents = [$this->createIncident(1, 'high', 'new')];

        $this->security->method('getUser')->willReturn($user);
        $this->incidentRepository->method('findByTenant')
            ->with($tenant)
            ->willReturn($incidents);
        $this->incidentRepository->method('countByCategory')->willReturn([]);
        $this->incidentRepository->method('countBySeverity')->willReturn([]);

        $this->twig->expects($this->once())
            ->method('render')
            ->willReturn('rendered');

        $request = new Request(['view' => 'own']);
        $response = $this->controller->index($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test new action renders form
     */
    public function testNewRendersForm(): void
    {
        $tenant = $this->createTenant(1);
        $form = $this->createMock(FormInterface::class);
        $formView = $this->createMock(\Symfony\Component\Form\FormView::class);

        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->incidentRepository->method('getNextIncidentNumber')
            ->with($tenant)
            ->willReturn('INC-2025-0001');

        $this->formFactory->method('create')
            ->with(IncidentType::class, $this->isInstanceOf(Incident::class))
            ->willReturn($form);

        $form->method('handleRequest')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(false);
        $form->method('createView')->willReturn($formView);

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'incident/new.html.twig',
                $this->callback(function ($params) {
                    return isset($params['incident']) && isset($params['form']);
                })
            )
            ->willReturn('rendered');

        $request = new Request();
        $response = $this->controller->new($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test new action throws exception when no tenant context
     */
    public function testNewThrowsExceptionWithoutTenantContext(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $this->expectExceptionMessage('No tenant context available');

        $request = new Request();
        $this->controller->new($request);
    }

    /**
     * Test new action creates incident successfully
     */
    public function testNewCreatesIncidentSuccessfully(): void
    {
        $tenant = $this->createTenant(1);
        $form = $this->createMock(FormInterface::class);

        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->incidentRepository->method('getNextIncidentNumber')->willReturn('INC-2025-0001');

        $this->formFactory->method('create')->willReturn($form);
        $form->method('handleRequest')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $form->method('getData')->willReturn($this->createIncident(1, 'low', 'new'));

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $this->translator->method('trans')->willReturn('Success');

        $request = $this->createRequestWithSession();
        $response = $this->controller->new($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(302, $response->getStatusCode());
    }

    /**
     * Test new action sends notification for high severity incidents
     */
    public function testNewSendsNotificationForHighSeverityIncidents(): void
    {
        $tenant = $this->createTenant(1);
        $form = $this->createMock(FormInterface::class);
        $admins = [$this->createUser(1, $tenant)];

        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->incidentRepository->method('getNextIncidentNumber')->willReturn('INC-2025-0001');

        // Mock createForm to capture the incident and update its severity
        $this->formFactory->method('create')->willReturnCallback(function ($type, $incident) use ($form) {
            // The controller creates a new Incident and passes it to createForm
            // We need to modify this incident to have critical severity
            $incident->setSeverity('critical');
            $incident->setTitle('Critical Incident');
            $incident->setDescription('Test Description');
            $incident->setCategory('security');
            $incident->setReportedBy('Test User');
            return $form;
        });

        $form->method('handleRequest')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $this->userRepository->method('findByRole')
            ->with('ROLE_ADMIN')
            ->willReturn($admins);

        $this->emailNotificationService->expects($this->once())
            ->method('sendIncidentNotification')
            ->with(
                $this->callback(function ($incident) {
                    return $incident->getSeverity() === 'critical';
                }),
                $admins
            );

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');
        $this->translator->method('trans')->willReturn('Success');

        $request = $this->createRequestWithSession();
        $this->controller->new($request);
    }

    /**
     * Test show action displays incident details
     */
    public function testShowDisplaysIncidentDetails(): void
    {
        $incident = $this->createIncident(1, 'high', 'in_progress');
        $auditLogs = [];

        $this->auditLogRepository->method('findByEntity')
            ->with('Incident', 1)
            ->willReturn($auditLogs);

        $this->incidentEscalationWorkflowService->method('getEscalationStatus')
            ->with($incident)
            ->willReturn(['status' => 'active']);

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'incident/show.html.twig',
                $this->callback(function ($params) {
                    return isset($params['incident'])
                        && isset($params['auditLogs'])
                        && isset($params['workflowStatus']);
                })
            )
            ->willReturn('rendered');

        $response = $this->controller->show($incident);

        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test edit action renders form
     */
    public function testEditRendersForm(): void
    {
        $incident = $this->createIncident(1, 'medium', 'investigating');
        $form = $this->createMock(FormInterface::class);
        $formView = $this->createMock(\Symfony\Component\Form\FormView::class);

        $this->formFactory->method('create')
            ->with(IncidentType::class, $incident)
            ->willReturn($form);

        $form->method('handleRequest')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(false);
        $form->method('createView')->willReturn($formView);

        $this->twig->expects($this->once())
            ->method('render')
            ->with('incident/edit.html.twig')
            ->willReturn('rendered');

        $request = new Request();
        $response = $this->controller->edit($request, $incident);

        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test edit action updates incident successfully
     */
    public function testEditUpdatesIncidentSuccessfully(): void
    {
        $incident = $this->createIncident(1, 'medium', 'investigating');
        $form = $this->createMock(FormInterface::class);

        $this->formFactory->method('create')->willReturn($form);
        $form->method('handleRequest')->willReturnSelf();
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $this->entityManager->expects($this->once())->method('flush');
        $this->translator->method('trans')->willReturn('Updated');

        $request = $this->createRequestWithSession();
        $response = $this->controller->edit($request, $incident);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(302, $response->getStatusCode());
    }

    /**
     * Test edit action sends notification on status change
     */
    public function testEditSendsNotificationOnStatusChange(): void
    {
        $tenant = $this->createTenant(1);

        // Create a real Incident object for this test
        $incident = new Incident();
        $incident->setStatus('investigating'); // Original status
        $incident->setSeverity('high');
        $incident->setTenant($tenant);
        $incident->setTitle('Test Incident');
        $incident->setDescription('Test Description');
        $incident->setCategory('security');
        $incident->setReportedBy('Test User');

        $form = $this->createMock(FormInterface::class);
        $admins = [$this->createUser(1, $tenant)];

        $this->formFactory->method('create')->willReturn($form);
        $form->method('handleRequest')->willReturnCallback(function () use ($incident, $form) {
            // Simulate form changing the status
            $incident->setStatus('resolved');
            return $form;
        });
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);

        $this->userRepository->method('findByRole')
            ->with('ROLE_ADMIN')
            ->willReturn($admins);

        $this->emailNotificationService->expects($this->once())
            ->method('sendIncidentUpdateNotification')
            ->with(
                $incident,
                $admins,
                $this->stringContains('Status changed')
            );

        $this->entityManager->expects($this->once())->method('flush');
        $this->translator->method('trans')->willReturn('Updated');

        $request = $this->createRequestWithSession();
        $this->controller->edit($request, $incident);
    }

    /**
     * Test delete action removes incident
     */
    public function testDeleteRemovesIncident(): void
    {
        $incident = $this->createIncident(1, 'low', 'closed');

        $this->entityManager->expects($this->once())
            ->method('remove')
            ->with($incident);
        $this->entityManager->expects($this->once())->method('flush');

        $this->translator->method('trans')->willReturn('Deleted');

        $request = $this->createRequestWithSession([], ['_token' => 'valid_token']);
        $request->setMethod('POST');

        $response = $this->controller->delete($request, $incident);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(302, $response->getStatusCode());
    }

    /**
     * Test bulk delete with valid IDs
     */
    public function testBulkDeleteWithValidIds(): void
    {
        $tenant = $this->createTenant(1);
        $user = $this->createUser(1, $tenant);

        // Create actual mock incidents with proper tenant relationship
        $incident1 = $this->createMock(Incident::class);
        $incident1->method('getId')->willReturn(1);
        $incident1->method('getTenant')->willReturn($tenant);

        $incident2 = $this->createMock(Incident::class);
        $incident2->method('getId')->willReturn(2);
        $incident2->method('getTenant')->willReturn($tenant);

        $this->security->method('getUser')->willReturn($user);

        $this->incidentRepository->method('find')
            ->willReturnMap([
                [1, $incident1],
                [2, $incident2],
            ]);

        $this->entityManager->expects($this->exactly(2))->method('remove');
        $this->entityManager->expects($this->once())->method('flush');

        $request = $this->createRequestWithSession([], [], json_encode(['ids' => [1, 2]]));
        $request->setMethod('POST');

        $response = $this->controller->bulkDelete($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals(2, $data['deleted']);
    }

    /**
     * Test bulk delete with empty IDs
     */
    public function testBulkDeleteWithEmptyIds(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['ids' => []]));
        $request->setMethod('POST');

        $response = $this->controller->bulkDelete($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Test bulk delete rejects incidents from other tenants
     */
    public function testBulkDeleteRejectsOtherTenantsIncidents(): void
    {
        $userTenant = $this->createTenant(1);
        $otherTenant = $this->createTenant(2);
        $user = $this->createUser(1, $userTenant);
        $incident = $this->createIncident(1, 'low', 'closed');
        $incident->setTenant($otherTenant);

        $this->security->method('getUser')->willReturn($user);
        $this->incidentRepository->method('find')->with(1)->willReturn($incident);

        $this->entityManager->expects($this->never())->method('remove');

        $request = new Request([], [], [], [], [], [], json_encode(['ids' => [1]]));
        $request->setMethod('POST');

        $response = $this->controller->bulkDelete($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
    }

    /**
     * Test GDPR wizard result endpoint
     */
    public function testGdprWizardResultReturnsAssessment(): void
    {
        $assessment = [
            'riskLevel' => 'high',
            'requiresNotification' => true,
            'timeline' => 72,
        ];

        $this->gdprBreachAssessmentService->method('assessBreachRisk')
            ->with(['personal_data'], 'large')
            ->willReturn($assessment);

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [],
            json_encode(['dataTypes' => ['personal_data'], 'scale' => 'large'])
        );
        $request->setMethod('POST');

        $response = $this->controller->gdprWizardResult($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('high', $data['riskLevel']);
        $this->assertTrue($data['requiresNotification']);
    }

    /**
     * Test GDPR wizard result with missing parameters
     */
    public function testGdprWizardResultWithMissingParameters(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['dataTypes' => ['personal_data']]));
        $request->setMethod('POST');

        $response = $this->controller->gdprWizardResult($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());
    }

    /**
     * Test NIS2 report download when framework is active
     */
    public function testDownloadNis2ReportWhenFrameworkActive(): void
    {
        $incident = $this->createIncident(1, 'critical', 'investigating');
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('isActive')->willReturn(true);
        $session = $this->createMock(SessionInterface::class);

        $this->complianceFrameworkRepository->method('findOneBy')
            ->with(['code' => 'NIS2'])
            ->willReturn($framework);

        $this->pdfExportService->method('generatePdf')
            ->willReturn('PDF content');

        $request = new Request();
        $request->setSession($session);
        $session->expects($this->once())->method('save');

        $response = $this->controller->downloadNis2Report($request, $incident);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    /**
     * Test NIS2 report download when framework is inactive
     */
    public function testDownloadNis2ReportWhenFrameworkInactive(): void
    {
        $incident = $this->createIncident(1, 'critical', 'investigating');
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('isActive')->willReturn(false);

        $this->complianceFrameworkRepository->method('findOneBy')
            ->with(['code' => 'NIS2'])
            ->willReturn($framework);

        $this->translator->method('trans')->willReturn('NIS2 not available');

        $request = $this->createRequestWithSession();
        $response = $this->controller->downloadNis2Report($request, $incident);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(302, $response->getStatusCode());
    }

    /**
     * Test BCM impact analysis
     */
    public function testBcmImpact(): void
    {
        $incident = $this->createIncident(1, 'high', 'investigating');
        $analysis = ['totalImpact' => 50000, 'criticalProcesses' => 2];

        $this->incidentBCMImpactService->method('analyzeBusinessImpact')
            ->with($incident)
            ->willReturn($analysis);

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'incident/bcm_impact.html.twig',
                $this->callback(function ($params) use ($incident, $analysis) {
                    return $params['incident'] === $incident
                        && $params['analysis'] === $analysis;
                })
            )
            ->willReturn('rendered');

        $response = $this->controller->bcmImpact($incident);

        $this->assertInstanceOf(Response::class, $response);
    }

    /**
     * Test BCM impact API endpoint
     */
    public function testBcmImpactApi(): void
    {
        $incident = $this->createIncident(1, 'high', 'investigating');
        $analysis = ['totalImpact' => 50000];

        $this->incidentBCMImpactService->method('analyzeBusinessImpact')
            ->with($incident, 24)
            ->willReturn($analysis);

        $request = new Request(['downtime_hours' => '24']);
        $response = $this->controller->bcmImpactApi($incident, $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals(50000, $data['totalImpact']);
    }

    /**
     * Test auto-detect processes
     */
    public function testAutoDetectProcesses(): void
    {
        $incident = $this->createIncident(1, 'high', 'investigating');
        $process1 = $this->createMock(BusinessProcess::class);
        $process2 = $this->createMock(BusinessProcess::class);

        $this->incidentBCMImpactService->method('identifyAffectedProcesses')
            ->with($incident)
            ->willReturn([$process1, $process2]);

        $this->entityManager->expects($this->once())->method('flush');
        $this->translator->method('trans')->willReturn('Added 2 processes');

        $request = $this->createRequestWithSession();
        $response = $this->controller->autoDetectProcesses($incident);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(302, $response->getStatusCode());
    }

    /**
     * Test escalation preview with valid data
     */
    public function testEscalationPreviewWithValidData(): void
    {
        $preview = [
            'will_escalate' => true,
            'escalation_level' => 'high',
            'workflow_name' => 'Critical Incident Workflow',
            'notified_roles' => ['ROLE_ADMIN'],
            'notified_users' => [],
            'sla_hours' => 4,
            'sla_description' => '4 hours',
            'is_gdpr_breach' => false,
            'gdpr_deadline' => null,
            'requires_approval' => false,
            'approval_steps' => [],
            'estimated_completion_time' => '4 hours',
        ];

        $this->incidentEscalationWorkflowService->method('previewEscalation')
            ->willReturn($preview);

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [],
            json_encode(['severity' => 'critical', 'dataBreachOccurred' => false])
        );
        $request->setMethod('POST');

        $response = $this->controller->escalationPreview($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['will_escalate']);
        $this->assertEquals('high', $data['escalation_level']);
    }

    /**
     * Test escalation preview with missing severity
     */
    public function testEscalationPreviewWithMissingSeverity(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['dataBreachOccurred' => false]));
        $request->setMethod('POST');

        $response = $this->controller->escalationPreview($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());
    }

    /**
     * Test escalation preview with invalid severity
     */
    public function testEscalationPreviewWithInvalidSeverity(): void
    {
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [],
            json_encode(['severity' => 'invalid', 'dataBreachOccurred' => false])
        );
        $request->setMethod('POST');

        $response = $this->controller->escalationPreview($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());
    }

    /**
     * Helper method to create a Request with session
     */
    private function createRequestWithSession(array $query = [], array $request = [], string $content = ''): Request
    {
        $req = new Request($query, $request, [], [], [], [], $content);
        $req->setSession($this->session);

        // Push the request onto the request stack so the controller can access it
        $this->requestStack->push($req);

        return $req;
    }

    /**
     * Helper method to create a mock Tenant
     */
    private function createTenant(int $id): Tenant
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
     * Helper method to create a mock User
     */
    private function createUser(int $id, Tenant $tenant): User
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
     * Helper method to create a mock Incident
     */
    private function createIncident(int $id, string $severity, string $status, bool $dataBreach = false): Incident
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getId')->willReturn($id);
        $incident->method('getSeverity')->willReturn($severity);
        $incident->method('getStatus')->willReturn($status);
        $incident->method('isDataBreachOccurred')->willReturn($dataBreach);
        $incident->method('getIncidentNumber')->willReturn("INC-2025-000{$id}");
        $incident->method('getTitle')->willReturn("Test Incident {$id}");
        $incident->method('getAffectedBusinessProcesses')->willReturn(new ArrayCollection());
        $incident->method('getCreatedAt')->willReturn(new DateTimeImmutable());
        $incident->method('getDetectedAt')->willReturn(new DateTimeImmutable());
        $incident->method('requiresNis2Reporting')->willReturn($severity === 'critical');
        return $incident;
    }
}
