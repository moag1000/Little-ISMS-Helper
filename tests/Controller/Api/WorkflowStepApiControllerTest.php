<?php

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use App\Controller\Api\WorkflowStepApiController;
use App\Repository\WorkflowRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class WorkflowStepApiControllerTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $workflowRepository;
    private MockObject $csrfTokenManager;
    private WorkflowStepApiController $controller;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->workflowRepository = $this->createMock(WorkflowRepository::class);
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);

        $this->controller = new WorkflowStepApiController(
            $this->entityManager,
            $this->workflowRepository,
            $this->csrfTokenManager
        );
    }

    public function testListStepsReturnsEmptyArray(): void
    {
        $workflow = $this->createMock(Workflow::class);
        $workflow->method('getSteps')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([]));

        $response = $this->controller->listSteps($workflow);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['steps']);
        $this->assertEmpty($data['steps']);
    }

    public function testListStepsReturnsSteps(): void
    {
        $step = $this->createConfiguredMock(WorkflowStep::class, [
            'getId' => 1,
            'getName' => 'Test Step',
            'getDescription' => 'Test Description',
            'getStepOrder' => 1,
            'getStepType' => 'approval',
            'getApproverRole' => 'ROLE_MANAGER',
            'getApproverUsers' => [1, 2],
            'isRequired' => true,
            'getDaysToComplete' => 5
        ]);

        $workflow = $this->createMock(Workflow::class);
        $workflow->method('getSteps')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([$step]));

        $response = $this->controller->listSteps($workflow);
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertCount(1, $data['steps']);
        $this->assertEquals('Test Step', $data['steps'][0]['name']);
        $this->assertEquals('approval', $data['steps'][0]['stepType']);
        $this->assertEquals([1, 2], $data['steps'][0]['approverUsers']);
    }

    public function testAddStepRejectsMissingCsrfToken(): void
    {
        $request = new Request();
        $workflow = $this->createMock(Workflow::class);

        $this->csrfTokenManager->method('isTokenValid')->willReturn(false);

        $response = $this->controller->addStep($request, $workflow);

        $this->assertEquals(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('CSRF', $data['error']);
    }

    public function testAddStepRejectsInvalidJson(): void
    {
        $request = new Request([], [], [], [], [], [], 'invalid json {');
        $request->headers->set('X-CSRF-Token', 'valid_token');

        $workflow = $this->createMock(Workflow::class);

        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);

        $response = $this->controller->addStep($request, $workflow);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testAddStepRejectsEmptyBody(): void
    {
        $request = new Request([], [], [], [], [], [], '{}');
        $request->headers->set('X-CSRF-Token', 'valid_token');

        $workflow = $this->createMock(Workflow::class);

        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);

        $response = $this->controller->addStep($request, $workflow);

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testAddStepValidationErrors(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'name' => '', // Empty name should fail validation
            'stepType' => 'invalid_type'
        ]));
        $request->headers->set('X-CSRF-Token', 'valid_token');

        $workflow = $this->createMock(Workflow::class);
        $workflow->method('getSteps')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([]));

        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);

        $response = $this->controller->addStep($request, $workflow);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('errors', $data);
    }

    public function testUpdateStepRejectsMissingCsrfToken(): void
    {
        $request = new Request();
        $step = $this->createMock(WorkflowStep::class);

        $this->csrfTokenManager->method('isTokenValid')->willReturn(false);

        $response = $this->controller->updateStep($request, $step);

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testDeleteStepRejectsMissingCsrfToken(): void
    {
        $request = new Request();
        $step = $this->createMock(WorkflowStep::class);

        $this->csrfTokenManager->method('isTokenValid')->willReturn(false);

        $response = $this->controller->deleteStep($request, $step);

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testDeleteStepHandlesNullWorkflow(): void
    {
        $request = new Request();
        $request->headers->set('X-CSRF-Token', 'valid_token');

        $step = $this->createMock(WorkflowStep::class);
        $step->method('getWorkflow')->willReturn(null);

        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);

        $response = $this->controller->deleteStep($request, $step);

        $this->assertEquals(404, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('not found', $data['error']);
    }

    public function testReorderStepsRejectsMissingCsrfToken(): void
    {
        $request = new Request();
        $workflow = $this->createMock(Workflow::class);

        $this->csrfTokenManager->method('isTokenValid')->willReturn(false);

        $response = $this->controller->reorderSteps($request, $workflow);

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testReorderStepsRejectsMissingStepIds(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['invalid' => 'data']));
        $request->headers->set('X-CSRF-Token', 'valid_token');

        $workflow = $this->createMock(Workflow::class);

        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);

        $response = $this->controller->reorderSteps($request, $workflow);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('stepIds', $data['error']);
    }

    public function testReorderStepsValidatesStepIds(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['stepIds' => [999]]));
        $request->headers->set('X-CSRF-Token', 'valid_token');

        $workflow = $this->createMock(Workflow::class);
        $workflow->method('getSteps')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([]));

        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);

        $response = $this->controller->reorderSteps($request, $workflow);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Invalid step ID', $data['error']);
    }

    public function testDuplicateStepRejectsMissingCsrfToken(): void
    {
        $request = new Request();
        $step = $this->createMock(WorkflowStep::class);

        $this->csrfTokenManager->method('isTokenValid')->willReturn(false);

        $response = $this->controller->duplicateStep($request, $step);

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testDuplicateStepHandlesNullWorkflow(): void
    {
        $request = new Request();
        $request->headers->set('X-CSRF-Token', 'valid_token');

        $step = $this->createMock(WorkflowStep::class);
        $step->method('getWorkflow')->willReturn(null);

        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);

        $response = $this->controller->duplicateStep($request, $step);

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testApplyTemplateRejectsMissingCsrfToken(): void
    {
        $request = new Request();
        $workflow = $this->createMock(Workflow::class);

        $this->csrfTokenManager->method('isTokenValid')->willReturn(false);

        $response = $this->controller->applyTemplate($request, $workflow);

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testApplyTemplateRejectsMissingTemplateKey(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['invalid' => 'data']));
        $request->headers->set('X-CSRF-Token', 'valid_token');

        $workflow = $this->createMock(Workflow::class);

        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);

        $response = $this->controller->applyTemplate($request, $workflow);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('templateKey', $data['error']);
    }

    public function testApplyTemplateRejectsInvalidTemplateKey(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['templateKey' => 'nonexistent']));
        $request->headers->set('X-CSRF-Token', 'valid_token');

        $workflow = $this->createMock(Workflow::class);

        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);

        $response = $this->controller->applyTemplate($request, $workflow);

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testGetTemplatesReturnsAllTemplates(): void
    {
        $response = $this->controller->getTemplates();

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('templates', $data);
        $this->assertArrayHasKey('risk_assessment', $data['templates']);
        $this->assertArrayHasKey('control_implementation', $data['templates']);
        $this->assertArrayHasKey('incident_response', $data['templates']);
        $this->assertArrayHasKey('document_review', $data['templates']);
        $this->assertArrayHasKey('change_request', $data['templates']);
    }

    public function testGetTemplatesHasCorrectStructure(): void
    {
        $response = $this->controller->getTemplates();
        $data = json_decode($response->getContent(), true);

        $template = $data['templates']['risk_assessment'];

        $this->assertArrayHasKey('name', $template);
        $this->assertArrayHasKey('description', $template);
        $this->assertArrayHasKey('entityType', $template);
        $this->assertArrayHasKey('steps', $template);
        $this->assertIsArray($template['steps']);
        $this->assertNotEmpty($template['steps']);

        $step = $template['steps'][0];
        $this->assertArrayHasKey('name', $step);
        $this->assertArrayHasKey('stepType', $step);
        $this->assertArrayHasKey('approverRole', $step);
        $this->assertArrayHasKey('daysToComplete', $step);
    }

    public function testSerializeStepHandlesNullApproverUsers(): void
    {
        $step = $this->createConfiguredMock(WorkflowStep::class, [
            'getId' => 1,
            'getName' => 'Test',
            'getDescription' => null,
            'getStepOrder' => 1,
            'getStepType' => 'approval',
            'getApproverRole' => null,
            'getApproverUsers' => null,
            'isRequired' => true,
            'getDaysToComplete' => null
        ]);

        $workflow = $this->createMock(Workflow::class);
        $workflow->method('getSteps')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([$step]));

        $response = $this->controller->listSteps($workflow);
        $data = json_decode($response->getContent(), true);

        // Should return empty array instead of null
        $this->assertIsArray($data['steps'][0]['approverUsers']);
        $this->assertEmpty($data['steps'][0]['approverUsers']);
    }

    public function testStepValidationRejectsInvalidStepType(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'name' => 'Valid Name',
            'stepType' => 'invalid_type'
        ]));
        $request->headers->set('X-CSRF-Token', 'valid_token');

        $workflow = $this->createMock(Workflow::class);
        $workflow->method('getSteps')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([]));

        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);

        $response = $this->controller->addStep($request, $workflow);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertContains('Invalid step type', $data['errors']);
    }

    public function testStepValidationRejectsNegativeDays(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'name' => 'Valid Name',
            'stepType' => 'approval',
            'daysToComplete' => -5
        ]));
        $request->headers->set('X-CSRF-Token', 'valid_token');

        $workflow = $this->createMock(Workflow::class);
        $workflow->method('getSteps')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([]));

        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);

        $response = $this->controller->addStep($request, $workflow);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertContains('Days to complete must be positive', $data['errors']);
    }

    public function testStepValidationRejectsLongName(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'name' => str_repeat('x', 256), // More than 255 chars
            'stepType' => 'approval'
        ]));
        $request->headers->set('X-CSRF-Token', 'valid_token');

        $workflow = $this->createMock(Workflow::class);
        $workflow->method('getSteps')->willReturn(new \Doctrine\Common\Collections\ArrayCollection([]));

        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);

        $response = $this->controller->addStep($request, $workflow);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertContains('Step name must be 255 characters or less', $data['errors']);
    }
}
