<?php

namespace App\Controller;

use App\Entity\Tenant;
use DateTimeImmutable;
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

#[IsGranted('ROLE_USER')]
class ComplianceRequirementController extends AbstractController
{
    public function __construct(
        private readonly ComplianceRequirementRepository $complianceRequirementRepository,
        private readonly ComplianceFrameworkRepository $complianceFrameworkRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceRequirementFulfillmentService $complianceRequirementFulfillmentService,
        private readonly TenantContext $tenantContext
    ) {}

    #[Route('/compliance/requirement/', name: 'app_compliance_requirement_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $frameworkId = $request->query->get('framework');

        if ($frameworkId) {
            $framework = $this->complianceFrameworkRepository->find($frameworkId);
            $requirements = $framework
                ? $this->complianceRequirementRepository->findByFramework($framework)
                : [];
        } else {
            $requirements = $this->complianceRequirementRepository->findAll();
        }

        $frameworks = $this->complianceFrameworkRepository->findAll();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('No tenant assigned to user. Please contact administrator.');
        }

        // Load tenant-specific fulfillments for all requirements (batch)
        // For SUPER_ADMIN without tenant, show empty fulfillments
        $fulfillments = [];
        if ($tenant instanceof Tenant) {
            foreach ($requirements as $requirement) {
                $fulfillment = $this->complianceRequirementFulfillmentService->getOrCreateFulfillment($tenant, $requirement);
                $fulfillments[$requirement->getId()] = $fulfillment;
            }
        }

        return $this->render('compliance/requirement/index.html.twig', [
            'requirements' => $requirements,
            'fulfillments' => $fulfillments,
            'frameworks' => $frameworks,
            'selected_framework' => $frameworkId,
        ]);
    }

    #[Route('/compliance/requirement/new', name: 'app_compliance_requirement_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $complianceRequirement = new ComplianceRequirement();

        // Pre-select framework if provided in query
        $frameworkId = $request->query->get('framework');
        if ($frameworkId) {
            $framework = $this->complianceFrameworkRepository->find($frameworkId);
            if ($framework) {
                $complianceRequirement->setFramework($framework);
            }
        }

        $form = $this->createForm(ComplianceRequirementType::class, $complianceRequirement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($complianceRequirement);
            $this->entityManager->flush();

            $this->addFlash('success', 'Compliance requirement created successfully.');

            return $this->redirectToRoute('app_compliance_requirement_show', [
                'id' => $complianceRequirement->getId()
            ]);
        }

        return $this->render('compliance/requirement/new.html.twig', [
            'requirement' => $complianceRequirement,
            'form' => $form,
        ]);
    }

    #[Route('/compliance/requirement/{id}', name: 'app_compliance_requirement_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ComplianceRequirement $complianceRequirement): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('No tenant assigned to user. Please contact administrator.');
        }

        // Get or create tenant-specific fulfillment (null for SUPER_ADMIN without tenant)
        $fulfillment = $tenant instanceof Tenant ? $this->complianceRequirementFulfillmentService->getOrCreateFulfillment($tenant, $complianceRequirement) : null;

        // Calculate fulfillment from controls (legacy method for comparison)
        $calculatedFulfillment = $complianceRequirement->calculateFulfillmentFromControls();

        // Check if this is inherited from parent
        $isInherited = $this->complianceRequirementFulfillmentService->isInheritedFulfillment($fulfillment, $tenant);
        $canEdit = $this->complianceRequirementFulfillmentService->canEditFulfillment($fulfillment, $tenant);

        // Get fulfillments for sub-requirements (for template access)
        $subRequirementFulfillments = [];
        foreach ($complianceRequirement->getDetailedRequirements() as $detailedRequirement) {
            $subFulfillment = $this->complianceRequirementFulfillmentService->getOrCreateFulfillment($tenant, $detailedRequirement);
            $subRequirementFulfillments[$detailedRequirement->getId()] = $subFulfillment;
        }

        return $this->render('compliance/requirement/show.html.twig', [
            'requirement' => $complianceRequirement,
            'fulfillment' => $fulfillment,
            'calculated_fulfillment' => $calculatedFulfillment,
            'is_inherited' => $isInherited,
            'can_edit' => $canEdit,
            'sub_requirement_fulfillments' => $subRequirementFulfillments,
        ]);
    }

    #[Route('/compliance/requirement/{id}/edit', name: 'app_compliance_requirement_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, ComplianceRequirement $complianceRequirement): Response
    {
        $form = $this->createForm(ComplianceRequirementType::class, $complianceRequirement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $complianceRequirement->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', 'Compliance requirement updated successfully.');

            return $this->redirectToRoute('app_compliance_requirement_show', [
                'id' => $complianceRequirement->getId()
            ]);
        }

        return $this->render('compliance/requirement/edit.html.twig', [
            'requirement' => $complianceRequirement,
            'form' => $form,
        ]);
    }

    #[Route('/compliance/requirement/{id}', name: 'app_compliance_requirement_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, ComplianceRequirement $complianceRequirement): Response
    {
        if ($this->isCsrfTokenValid('delete'.$complianceRequirement->getId(), $request->request->get('_token'))) {
            $frameworkId = $complianceRequirement->getFramework()->id;

            $this->entityManager->remove($complianceRequirement);
            $this->entityManager->flush();

            $this->addFlash('success', 'Compliance requirement deleted successfully.');

            if ($frameworkId) {
                return $this->redirectToRoute('app_compliance_framework', ['id' => $frameworkId]);
            }

            return $this->redirectToRoute('app_compliance_requirement_index');
        }

        return $this->redirectToRoute('app_compliance_requirement_index');
    }

    #[Route('/compliance/requirement/{id}/quick-update', name: 'app_compliance_requirement_quick_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function quickUpdate(Request $request, ComplianceRequirement $complianceRequirement): Response
    {
        if (!$this->isCsrfTokenValid('quick-update'.$complianceRequirement->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_compliance_requirement_show', ['id' => $complianceRequirement->getId()]);
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant && !$this->isGranted('ROLE_ADMIN')) {
            $this->addFlash('error', 'No tenant assigned to user. Please contact administrator.');
            return $this->redirectToRoute('app_compliance_requirement_show', ['id' => $complianceRequirement->getId()]);
        }

        // SUPER_ADMIN without tenant cannot update fulfillment
        if (!$tenant instanceof Tenant) {
            $this->addFlash('error', 'Cannot update fulfillment without tenant assignment.');
            return $this->redirectToRoute('app_compliance_requirement_show', ['id' => $complianceRequirement->getId()]);
        }

        // Get or create tenant-specific fulfillment
        $fulfillment = $this->complianceRequirementFulfillmentService->getOrCreateFulfillment($tenant, $complianceRequirement);

        // Check if user can edit (not inherited)
        if (!$this->complianceRequirementFulfillmentService->canEditFulfillment($fulfillment, $tenant)) {
            $this->addFlash('error', 'Cannot edit inherited fulfillment from parent tenant.');
            return $this->redirectToRoute('app_compliance_requirement_show', ['id' => $complianceRequirement->getId()]);
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
        $fulfillment->setUpdatedAt(new DateTimeImmutable());
        $fulfillment->setLastUpdatedBy($this->getUser());

        // Persist if new
        if (!$fulfillment->getId()) {
            $this->entityManager->persist($fulfillment);
        }

        $this->entityManager->flush();

        $this->addFlash('success', 'Requirement fulfillment updated successfully for your tenant.');

        return $this->redirectToRoute('app_compliance_requirement_show', ['id' => $complianceRequirement->getId()]);
    }
}
