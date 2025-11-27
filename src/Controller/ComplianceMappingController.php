<?php

namespace App\Controller;

use DateTimeImmutable;
use App\Entity\ComplianceMapping;
use App\Form\ComplianceMappingType;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\ComplianceMappingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ComplianceMappingController extends AbstractController
{
    public function __construct(
        private readonly ComplianceMappingRepository $complianceMappingRepository,
        private readonly ComplianceRequirementRepository $complianceRequirementRepository,
        private readonly ComplianceMappingService $complianceMappingService,
        private readonly EntityManagerInterface $entityManager
    ) {}

    #[Route('/compliance/mapping/', name: 'app_compliance_mapping_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $requirementId = $request->query->get('requirement');

        if ($requirementId) {
            $requirement = $this->complianceRequirementRepository->find($requirementId);
            $mappings = $requirement
                ? $this->complianceMappingRepository->findBySourceRequirement($requirement)
                : [];
        } else {
            $mappings = $this->complianceMappingRepository->findAll();
        }

        return $this->render('compliance/mapping/index.html.twig', [
            'mappings' => $mappings,
            'selected_requirement' => $requirementId,
        ]);
    }

    #[Route('/compliance/mapping/new', name: 'app_compliance_mapping_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $complianceMapping = new ComplianceMapping();

        // Pre-select source requirement if provided
        $sourceId = $request->query->get('source');
        if ($sourceId) {
            $source = $this->complianceRequirementRepository->find($sourceId);
            if ($source) {
                $complianceMapping->setSourceRequirement($source);
            }
        }

        $form = $this->createForm(ComplianceMappingType::class, $complianceMapping);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($complianceMapping);
            $this->entityManager->flush();

            $this->addFlash('success', 'Compliance mapping created successfully.');

            return $this->redirectToRoute('app_compliance_mapping_show', [
                'id' => $complianceMapping->getId()
            ]);
        }

        return $this->render('compliance/mapping/new.html.twig', [
            'mapping' => $complianceMapping,
            'form' => $form,
        ]);
    }

    #[Route('/compliance/mapping/{id}', name: 'app_compliance_mapping_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ComplianceMapping $complianceMapping): Response
    {
        // Calculate transitive fulfillment
        $transitiveFulfillment = $complianceMapping->calculateTransitiveFulfillment();

        return $this->render('compliance/mapping/show.html.twig', [
            'mapping' => $complianceMapping,
            'transitive_fulfillment' => $transitiveFulfillment,
        ]);
    }

    #[Route('/compliance/mapping/{id}/edit', name: 'app_compliance_mapping_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, ComplianceMapping $complianceMapping): Response
    {
        $form = $this->createForm(ComplianceMappingType::class, $complianceMapping);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $complianceMapping->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', 'Compliance mapping updated successfully.');

            return $this->redirectToRoute('app_compliance_mapping_show', [
                'id' => $complianceMapping->getId()
            ]);
        }

        return $this->render('compliance/mapping/edit.html.twig', [
            'mapping' => $complianceMapping,
            'form' => $form,
        ]);
    }

    #[Route('/compliance/mapping/{id}', name: 'app_compliance_mapping_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, ComplianceMapping $complianceMapping): Response
    {
        if ($this->isCsrfTokenValid('delete'.$complianceMapping->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($complianceMapping);
            $this->entityManager->flush();

            $this->addFlash('success', 'Compliance mapping deleted successfully.');
        }

        return $this->redirectToRoute('app_compliance_mapping_index');
    }

    #[Route('/compliance/mapping/{id}/analyze', name: 'app_compliance_mapping_analyze', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function analyze(Request $request, ComplianceMapping $complianceMapping): Response
    {
        if (!$this->isCsrfTokenValid('analyze'.$complianceMapping->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_compliance_mapping_show', ['id' => $complianceMapping->getId()]);
        }

        // Analyze mapping quality using the service
        $analysis = $this->complianceMappingService->analyzeMappingQuality($complianceMapping);

        // Update mapping with analysis results
        if (isset($analysis['calculated_percentage'])) {
            $complianceMapping->setCalculatedPercentage($analysis['calculated_percentage']);
        }
        if (isset($analysis['confidence'])) {
            $complianceMapping->setAnalysisConfidence($analysis['confidence']);
        }
        if (isset($analysis['textual_similarity'])) {
            $complianceMapping->setTextualSimilarity($analysis['textual_similarity']);
        }
        if (isset($analysis['keyword_overlap'])) {
            $complianceMapping->setKeywordOverlap($analysis['keyword_overlap']);
        }

        $complianceMapping->setAnalysisAlgorithmVersion('1.0');
        $complianceMapping->setUpdatedAt(new DateTimeImmutable());

        $this->entityManager->flush();

        $this->addFlash('success', 'Mapping analysis completed successfully.');

        return $this->redirectToRoute('app_compliance_mapping_show', ['id' => $complianceMapping->getId()]);
    }
}
