<?php

namespace App\Controller;

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

#[Route('/compliance/mapping')]
#[IsGranted('ROLE_USER')]
class ComplianceMappingController extends AbstractController
{
    public function __construct(
        private ComplianceMappingRepository $mappingRepository,
        private ComplianceRequirementRepository $requirementRepository,
        private ComplianceMappingService $mappingService,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/', name: 'app_compliance_mapping_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $requirementId = $request->query->get('requirement');

        if ($requirementId) {
            $requirement = $this->requirementRepository->find($requirementId);
            $mappings = $requirement
                ? $this->mappingRepository->findBySourceRequirement($requirement)
                : [];
        } else {
            $mappings = $this->mappingRepository->findAll();
        }

        return $this->render('compliance/mapping/index.html.twig', [
            'mappings' => $mappings,
            'selected_requirement' => $requirementId,
        ]);
    }

    #[Route('/new', name: 'app_compliance_mapping_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $mapping = new ComplianceMapping();

        // Pre-select source requirement if provided
        $sourceId = $request->query->get('source');
        if ($sourceId) {
            $source = $this->requirementRepository->find($sourceId);
            if ($source) {
                $mapping->setSourceRequirement($source);
            }
        }

        $form = $this->createForm(ComplianceMappingType::class, $mapping);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($mapping);
            $this->entityManager->flush();

            $this->addFlash('success', 'Compliance mapping created successfully.');

            return $this->redirectToRoute('app_compliance_mapping_show', [
                'id' => $mapping->getId()
            ]);
        }

        return $this->render('compliance/mapping/new.html.twig', [
            'mapping' => $mapping,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_compliance_mapping_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ComplianceMapping $mapping): Response
    {
        // Calculate transitive fulfillment
        $transitiveFulfillment = $mapping->calculateTransitiveFulfillment();

        return $this->render('compliance/mapping/show.html.twig', [
            'mapping' => $mapping,
            'transitive_fulfillment' => $transitiveFulfillment,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_compliance_mapping_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, ComplianceMapping $mapping): Response
    {
        $form = $this->createForm(ComplianceMappingType::class, $mapping);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $mapping->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', 'Compliance mapping updated successfully.');

            return $this->redirectToRoute('app_compliance_mapping_show', [
                'id' => $mapping->getId()
            ]);
        }

        return $this->render('compliance/mapping/edit.html.twig', [
            'mapping' => $mapping,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_compliance_mapping_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, ComplianceMapping $mapping): Response
    {
        if ($this->isCsrfTokenValid('delete'.$mapping->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($mapping);
            $this->entityManager->flush();

            $this->addFlash('success', 'Compliance mapping deleted successfully.');
        }

        return $this->redirectToRoute('app_compliance_mapping_index');
    }

    #[Route('/{id}/analyze', name: 'app_compliance_mapping_analyze', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function analyze(Request $request, ComplianceMapping $mapping): Response
    {
        if (!$this->isCsrfTokenValid('analyze'.$mapping->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_compliance_mapping_show', ['id' => $mapping->getId()]);
        }

        // Analyze mapping quality using the service
        $analysis = $this->mappingService->analyzeMappingQuality($mapping);

        // Update mapping with analysis results
        if (isset($analysis['calculated_percentage'])) {
            $mapping->setCalculatedPercentage($analysis['calculated_percentage']);
        }
        if (isset($analysis['confidence'])) {
            $mapping->setAnalysisConfidence($analysis['confidence']);
        }
        if (isset($analysis['textual_similarity'])) {
            $mapping->setTextualSimilarity($analysis['textual_similarity']);
        }
        if (isset($analysis['keyword_overlap'])) {
            $mapping->setKeywordOverlap($analysis['keyword_overlap']);
        }

        $mapping->setAnalysisAlgorithmVersion('1.0');
        $mapping->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        $this->addFlash('success', 'Mapping analysis completed successfully.');

        return $this->redirectToRoute('app_compliance_mapping_show', ['id' => $mapping->getId()]);
    }
}
