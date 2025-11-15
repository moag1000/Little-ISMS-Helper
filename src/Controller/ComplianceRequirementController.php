<?php

namespace App\Controller;

use App\Entity\ComplianceRequirement;
use App\Form\ComplianceRequirementType;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ComplianceFrameworkRepository;
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
        private EntityManagerInterface $entityManager
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

        return $this->render('compliance/requirement/index.html.twig', [
            'requirements' => $requirements,
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
        // Calculate fulfillment from controls
        $calculatedFulfillment = $requirement->calculateFulfillmentFromControls();

        return $this->render('compliance/requirement/show.html.twig', [
            'requirement' => $requirement,
            'calculated_fulfillment' => $calculatedFulfillment,
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

        $fulfillmentPercentage = $request->request->get('fulfillmentPercentage');
        $applicable = $request->request->get('applicable') === '1';

        if ($fulfillmentPercentage !== null) {
            $requirement->setFulfillmentPercentage((int) $fulfillmentPercentage);
        }

        $requirement->setApplicable($applicable);
        $requirement->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        $this->addFlash('success', 'Requirement updated successfully.');

        return $this->redirectToRoute('app_compliance_requirement_show', ['id' => $requirement->getId()]);
    }
}
