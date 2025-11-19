<?php

namespace App\Controller;

use App\Entity\ComplianceRequirement;
use App\Form\ComplianceRequirementType;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\ComplianceRequirementFulfillmentService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/compliance/requirement')]
#[IsGranted('ROLE_USER')]
class ComplianceRequirementController extends AbstractController
{
    public function __construct(
        private ComplianceRequirementRepository $requirementRepository,
        private ComplianceFrameworkRepository $frameworkRepository,
        private EntityManagerInterface $entityManager,
        private ComplianceRequirementFulfillmentService $fulfillmentService,
        private TenantContext $tenantContext
    ) {}

    #[Route('/', name: 'app_compliance_requirement_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $frameworkId = $request->query->get('framework');

        if ($frameworkId) {
            $framework = $this->frameworkRepository->find($frameworkId);
            $requirements = $framework
                ? $this->requirementRepository->findByFramework($framework)
                : [];
        } else {
            $requirements = $this->requirementRepository->findAll();
        }

        $frameworks = $this->frameworkRepository->findAll();
        $tenant = $this->tenantContext->getCurrentTenant();

        // Load tenant-specific fulfillments for all requirements (batch)
        $fulfillments = [];
        foreach ($requirements as $requirement) {
            $fulfillment = $this->fulfillmentService->getOrCreateFulfillment($tenant, $requirement);
            $fulfillments[$requirement->getId()] = $fulfillment;
        }

        return $this->render('compliance/requirement/index.html.twig', [
            'requirements' => $requirements,
            'fulfillments' => $fulfillments,
            'frameworks' => $frameworks,
            'selected_framework' => $frameworkId,
        ]);
    }

    #[Route('/new', name: 'app_compliance_requirement_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $requirement = new ComplianceRequirement();

        // Pre-select framework if provided in query
        $frameworkId = $request->query->get('framework');
        if ($frameworkId) {
            $framework = $this->frameworkRepository->find($frameworkId);
            if ($framework) {
                $requirement->setFramework($framework);
            }
        }

        $form = $this->createForm(ComplianceRequirementType::class, $requirement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($requirement);
            $this->entityManager->flush();

            $this->addFlash('success', 'Compliance requirement created successfully.');

            return $this->redirectToRoute('app_compliance_requirement_show', [
                'id' => $requirement->getId()
            ]);
        }

        return $this->render('compliance/requirement/new.html.twig', [
            'requirement' => $requirement,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_compliance_requirement_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ComplianceRequirement $requirement): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        // Get or create tenant-specific fulfillment
        $fulfillment = $this->fulfillmentService->getOrCreateFulfillment($tenant, $requirement);

        // Calculate fulfillment from controls (legacy method for comparison)
        $calculatedFulfillment = $requirement->calculateFulfillmentFromControls();

        // Check if this is inherited from parent
        $isInherited = $this->fulfillmentService->isInheritedFulfillment($fulfillment, $tenant);
        $canEdit = $this->fulfillmentService->canEditFulfillment($fulfillment, $tenant);

        return $this->render('compliance/requirement/show.html.twig', [
            'requirement' => $requirement,
            'fulfillment' => $fulfillment,
            'calculated_fulfillment' => $calculatedFulfillment,
            'is_inherited' => $isInherited,
            'can_edit' => $canEdit,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_compliance_requirement_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, ComplianceRequirement $requirement): Response
    {
        $form = $this->createForm(ComplianceRequirementType::class, $requirement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $requirement->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', 'Compliance requirement updated successfully.');

            return $this->redirectToRoute('app_compliance_requirement_show', [
                'id' => $requirement->getId()
            ]);
        }

        return $this->render('compliance/requirement/edit.html.twig', [
            'requirement' => $requirement,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_compliance_requirement_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, ComplianceRequirement $requirement): Response
    {
        if ($this->isCsrfTokenValid('delete'.$requirement->getId(), $request->request->get('_token'))) {
            $frameworkId = $requirement->getFramework()?->getId();

            $this->entityManager->remove($requirement);
            $this->entityManager->flush();

            $this->addFlash('success', 'Compliance requirement deleted successfully.');

            if ($frameworkId) {
                return $this->redirectToRoute('app_compliance_framework', ['id' => $frameworkId]);
            }

            return $this->redirectToRoute('app_compliance_requirement_index');
        }

        return $this->redirectToRoute('app_compliance_requirement_index');
    }

    #[Route('/{id}/quick-update', name: 'app_compliance_requirement_quick_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function quickUpdate(Request $request, ComplianceRequirement $requirement): Response
    {
        if (!$this->isCsrfTokenValid('quick-update'.$requirement->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_compliance_requirement_show', ['id' => $requirement->getId()]);
        }

        $tenant = $this->tenantContext->getCurrentTenant();

        // Get or create tenant-specific fulfillment
        $fulfillment = $this->fulfillmentService->getOrCreateFulfillment($tenant, $requirement);

        // Check if user can edit (not inherited)
        if (!$this->fulfillmentService->canEditFulfillment($fulfillment, $tenant)) {
            $this->addFlash('error', 'Cannot edit inherited fulfillment from parent tenant.');
            return $this->redirectToRoute('app_compliance_requirement_show', ['id' => $requirement->getId()]);
        }

        // Update fulfillment fields
        $fulfillmentPercentage = $request->request->get('fulfillmentPercentage');
        $applicable = $request->request->get('applicable') === '1';

        if ($fulfillmentPercentage !== null) {
            $fulfillment->setFulfillmentPercentage((int) $fulfillmentPercentage);

            // Auto-update status based on percentage
            if ($fulfillmentPercentage >= 100) {
                $fulfillment->setStatus('implemented');
            } elseif ($fulfillmentPercentage > 0) {
                $fulfillment->setStatus('in_progress');
            } else {
                $fulfillment->setStatus('not_started');
            }
        }

        $fulfillment->setApplicable($applicable);
        $fulfillment->setUpdatedAt(new \DateTimeImmutable());
        $fulfillment->setLastUpdatedBy($this->getUser());

        // Persist if new
        if (!$fulfillment->getId()) {
            $this->entityManager->persist($fulfillment);
        }

        $this->entityManager->flush();

        $this->addFlash('success', 'Requirement fulfillment updated successfully for your tenant.');

        return $this->redirectToRoute('app_compliance_requirement_show', ['id' => $requirement->getId()]);
    }
}
