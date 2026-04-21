<?php

namespace App\Controller;

use DateTimeImmutable;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Form\ComplianceMappingType;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\ComplianceMappingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class ComplianceMappingController extends AbstractController
{
    public function __construct(
        private readonly ComplianceMappingRepository $complianceMappingRepository,
        private readonly ComplianceRequirementRepository $complianceRequirementRepository,
        private readonly ComplianceMappingService $complianceMappingService,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?ComplianceFrameworkRepository $frameworkRepository = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    /**
     * Sprint 5 / M4 — Mapping-Hub (Landing-Page).
     *
     * Junior-Consultant-Walkthrough: Wer zum ersten Mal auf /compliance/mapping
     * landet, sieht eine Tabelle mit tausenden Einträgen — keine Orientierung.
     * Der Hub bietet fünf Einstiege (Wizard, Seeds, Quality, Transitive,
     * Liste) plus KPI-Strip + 9001-Brücke + Last-5.
     */
    #[Route('/compliance/mapping/hub', name: 'app_compliance_mapping_hub', methods: ['GET'])]
    public function hub(): Response
    {
        $stats = $this->complianceMappingRepository->getMappingStatistics();
        $unreviewed = count($this->complianceMappingRepository->findMappingsRequiringReview());
        $frameworkCount = $this->frameworkRepository !== null
            ? count($this->frameworkRepository->findBy(['active' => true]))
            : 0;

        $recent = $this->complianceMappingRepository->findBy(
            [],
            ['updatedAt' => 'DESC', 'id' => 'DESC'],
            5,
        );

        return $this->render('compliance/mapping/hub.html.twig', [
            'stats' => $stats,
            'unreviewed_count' => $unreviewed,
            'framework_count' => $frameworkCount,
            'recent' => $recent,
        ]);
    }

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

    /**
     * Sprint 4 / M1 — Mapping-Wizard (Junior-tauglich).
     *
     * 4-Schritt-Flow, server-side gerendert, JS-client-side filtert:
     *   1. Framework-Paar wählen (aktive Frameworks als Dropdown)
     *   2. Requirement-Paar (Dropdowns filtern auf Schritt-1-Auswahl)
     *   3. Mapping-Type (füllt Prozent + Confidence automatisch vor)
     *   4. Rationale + bidirectional
     *
     * Nicht-Wizard-Flow (`new`) bleibt als Fallback für Power-User.
     */
    #[Route('/compliance/mapping/wizard', name: 'app_compliance_mapping_wizard', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function wizard(Request $request): Response
    {
        if ($this->frameworkRepository === null) {
            return $this->redirectToRoute('app_compliance_mapping_new');
        }

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token');
            if (!$this->isCsrfTokenValid('mapping_wizard', $token)) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $sourceId = (int) $request->request->get('source_requirement_id');
            $targetId = (int) $request->request->get('target_requirement_id');
            $type = (string) $request->request->get('mapping_type');
            $percentage = (int) $request->request->get('mapping_percentage');
            $confidence = (string) $request->request->get('confidence');
            $rationale = trim((string) $request->request->get('rationale', ''));
            $bidirectional = (bool) $request->request->get('bidirectional', false);

            $errors = [];
            $source = $sourceId > 0 ? $this->complianceRequirementRepository->find($sourceId) : null;
            $target = $targetId > 0 ? $this->complianceRequirementRepository->find($targetId) : null;
            if (!$source instanceof ComplianceRequirement) {
                $errors[] = 'mapping.wizard.error.source_required';
            }
            if (!$target instanceof ComplianceRequirement) {
                $errors[] = 'mapping.wizard.error.target_required';
            }
            if ($source instanceof ComplianceRequirement
                && $target instanceof ComplianceRequirement
                && $source->getId() === $target->getId()
            ) {
                $errors[] = 'mapping.wizard.error.same_requirement';
            }
            if (!in_array($type, ['weak', 'partial', 'full', 'exceeds'], true)) {
                $errors[] = 'mapping.wizard.error.type_invalid';
            }
            $percentage = max(0, min(150, $percentage));
            if (!in_array($confidence, ['low', 'medium', 'high'], true)) {
                $confidence = 'medium';
            }

            if ($errors === []
                && $source instanceof ComplianceRequirement
                && $target instanceof ComplianceRequirement
            ) {
                $mapping = new ComplianceMapping();
                $mapping->setSourceRequirement($source);
                $mapping->setTargetRequirement($target);
                $mapping->setMappingType($type);
                $mapping->setMappingPercentage($percentage);
                $mapping->setConfidence($confidence);
                $mapping->setBidirectional($bidirectional);
                if ($rationale !== '') {
                    $mapping->setMappingRationale($rationale);
                }
                $mapping->setVerifiedBy('mapping_wizard');
                $mapping->setVerificationDate(new DateTimeImmutable());

                $this->entityManager->persist($mapping);
                $this->entityManager->flush();

                $this->addFlash('success', $this->translator !== null
                    ? $this->translator->trans('compliance.mapping.wizard.flash.created', [], 'compliance')
                    : 'Mapping created via wizard.');

                return $this->redirectToRoute('app_compliance_mapping_show', ['id' => $mapping->getId()]);
            }

            foreach ($errors as $err) {
                $this->addFlash('danger', $this->translator !== null
                    ? $this->translator->trans('compliance.' . $err, [], 'compliance')
                    : $err);
            }
        }

        $frameworks = $this->frameworkRepository->findBy(['active' => true], ['code' => 'ASC']);
        $frameworksById = [];
        $requirementsByFramework = [];
        foreach ($frameworks as $fw) {
            $id = $fw->getId();
            if ($id === null) {
                continue;
            }
            $frameworksById[$id] = [
                'id' => $id,
                'code' => $fw->getCode(),
                'name' => $fw->getName(),
            ];
            $requirementsByFramework[$id] = [];
        }

        foreach ($this->complianceRequirementRepository->findAll() as $req) {
            $fw = $req->getFramework();
            $fwId = $fw?->getId();
            $rId = $req->getId();
            if ($fwId === null || !isset($requirementsByFramework[$fwId]) || $rId === null) {
                continue;
            }
            $requirementsByFramework[$fwId][] = [
                'id' => $rId,
                'code' => (string) $req->getRequirementId(),
                'title' => (string) $req->getTitle(),
            ];
        }

        return $this->render('compliance/mapping/wizard.html.twig', [
            'frameworks' => array_values($frameworksById),
            'requirements_by_framework' => $requirementsByFramework,
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
