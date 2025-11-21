<?php

namespace App\Controller;

use App\Entity\ComplianceFramework;
use App\Form\ComplianceFrameworkType;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\ComplianceRequirementFulfillmentService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/compliance/framework')]
class ComplianceFrameworkController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ComplianceFrameworkRepository $frameworkRepository,
        private ComplianceRequirementRepository $requirementRepository,
        private ComplianceRequirementFulfillmentService $fulfillmentService,
        private TenantContext $tenantContext,
        private TranslatorInterface $translator
    ) {}

    #[Route('/', name: 'app_compliance_framework_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        $filters = [
            'active' => $request->query->get('active'),
            'mandatory' => $request->query->get('mandatory'),
            'industry' => $request->query->get('industry'),
        ];

        $queryBuilder = $this->frameworkRepository->createQueryBuilder('f')
            ->orderBy('f.name', 'ASC');

        // Apply filters
        if ($filters['active'] !== null && $filters['active'] !== '') {
            $queryBuilder->andWhere('f.active = :active')
                ->setParameter('active', $filters['active'] === '1');
        }

        if ($filters['mandatory'] !== null && $filters['mandatory'] !== '') {
            $queryBuilder->andWhere('f.mandatory = :mandatory')
                ->setParameter('mandatory', $filters['mandatory'] === '1');
        }

        if (!empty($filters['industry'])) {
            $queryBuilder->andWhere('f.applicableIndustry = :industry')
                ->setParameter('industry', $filters['industry']);
        }

        $frameworks = $queryBuilder->getQuery()->getResult();

        // Get unique industries for filter
        $industries = $this->frameworkRepository->createQueryBuilder('f')
            ->select('DISTINCT f.applicableIndustry')
            ->orderBy('f.applicableIndustry', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return $this->render('compliance/framework/index.html.twig', [
            'frameworks' => $frameworks,
            'filters' => $filters,
            'industries' => array_column($industries, 'applicableIndustry'),
        ]);
    }

    #[Route('/new', name: 'app_compliance_framework_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $framework = new ComplianceFramework();
        $form = $this->createForm(ComplianceFrameworkType::class, $framework);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($framework);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('compliance.framework.flash.created'));

            return $this->redirectToRoute('app_compliance_framework_show', ['id' => $framework->getId()]);
        }

        return $this->render('compliance/framework/new.html.twig', [
            'framework' => $framework,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_compliance_framework_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(ComplianceFramework $framework): Response
    {
        // Calculate statistics using tenant-specific fulfillment data
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('No tenant assigned to user. Please contact administrator.');
        }

        // For SUPER_ADMIN without tenant, show empty statistics
        if ($tenant) {
            $stats = $this->requirementRepository->getFrameworkStatisticsForTenant($framework, $tenant);
        } else {
            $stats = ['total' => 0, 'applicable' => 0, 'fulfilled' => 0];
        }

        $totalRequirements = $stats['total'];
        $applicableRequirements = $stats['applicable'];
        $fulfilledRequirements = $stats['fulfilled'];
        $compliancePercentage = $applicableRequirements > 0
            ? round(($fulfilledRequirements / $applicableRequirements) * 100, 2)
            : 0;

        // Get fulfillments for all requirements (for template access)
        $requirementFulfillments = [];
        foreach ($framework->getRequirements() as $requirement) {
            $fulfillment = $this->fulfillmentService->getOrCreateFulfillment($tenant, $requirement);
            $requirementFulfillments[$requirement->getId()] = $fulfillment;
        }

        // Group requirements by category
        $requirementsByCategory = [];
        foreach ($framework->getRequirements() as $requirement) {
            $category = $requirement->getCategory() ?: 'Uncategorized';
            if (!isset($requirementsByCategory[$category])) {
                $requirementsByCategory[$category] = [];
            }
            $requirementsByCategory[$category][] = $requirement;
        }
        ksort($requirementsByCategory);

        // Priority distribution
        $priorityDistribution = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];
        foreach ($framework->getRequirements() as $requirement) {
            $priority = $requirement->getPriority();
            if (isset($priorityDistribution[$priority])) {
                $priorityDistribution[$priority]++;
            }
        }

        return $this->render('compliance/framework/show.html.twig', [
            'framework' => $framework,
            'total_requirements' => $totalRequirements,
            'applicable_requirements' => $applicableRequirements,
            'fulfilled_requirements' => $fulfilledRequirements,
            'compliance_percentage' => $compliancePercentage,
            'requirement_fulfillments' => $requirementFulfillments,
            'requirements_by_category' => $requirementsByCategory,
            'priority_distribution' => $priorityDistribution,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_compliance_framework_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, ComplianceFramework $framework): Response
    {
        $form = $this->createForm(ComplianceFrameworkType::class, $framework);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $framework->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('compliance.framework.flash.updated'));

            return $this->redirectToRoute('app_compliance_framework_show', ['id' => $framework->getId()]);
        }

        return $this->render('compliance/framework/edit.html.twig', [
            'framework' => $framework,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_compliance_framework_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, ComplianceFramework $framework): Response
    {
        if ($this->isCsrfTokenValid('delete' . $framework->getId(), $request->request->get('_token'))) {
            $frameworkName = $framework->getName();

            // Check if framework has requirements
            if ($framework->getRequirements()->count() > 0) {
                $this->addFlash('warning', $this->translator->trans('compliance.framework.flash.has_requirements', [
                    '%name%' => $frameworkName,
                    '%count%' => $framework->getRequirements()->count(),
                ]));

                return $this->redirectToRoute('app_compliance_framework_show', ['id' => $framework->getId()]);
            }

            $this->entityManager->remove($framework);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('compliance.framework.flash.deleted', [
                '%name%' => $frameworkName,
            ]));
        }

        return $this->redirectToRoute('app_compliance_framework_index');
    }

    #[Route('/{id}/toggle', name: 'app_compliance_framework_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggle(Request $request, ComplianceFramework $framework): Response
    {
        if ($this->isCsrfTokenValid('toggle' . $framework->getId(), $request->request->get('_token'))) {
            $framework->setActive(!$framework->isActive());
            $framework->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $status = $framework->isActive() ? 'activated' : 'deactivated';
            $this->addFlash('success', $this->translator->trans('compliance.framework.flash.' . $status, [
                '%name%' => $framework->getName(),
            ]));
        }

        return $this->redirectToRoute('app_compliance_framework_show', ['id' => $framework->getId()]);
    }

    #[Route('/{id}/duplicate', name: 'app_compliance_framework_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function duplicate(Request $request, ComplianceFramework $framework): Response
    {
        if ($this->isCsrfTokenValid('duplicate' . $framework->getId(), $request->request->get('_token'))) {
            $newFramework = new ComplianceFramework();
            $newFramework->setCode($framework->getCode() . '_COPY');
            $newFramework->setName($framework->getName() . ' (Copy)');
            $newFramework->setDescription($framework->getDescription());
            $newFramework->setVersion($framework->getVersion());
            $newFramework->setApplicableIndustry($framework->getApplicableIndustry());
            $newFramework->setRegulatoryBody($framework->getRegulatoryBody());
            $newFramework->setMandatory($framework->isMandatory());
            $newFramework->setScopeDescription($framework->getScopeDescription());
            $newFramework->setActive(false); // Start inactive
            $newFramework->setRequiredModules($framework->getRequiredModules());

            $this->entityManager->persist($newFramework);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('compliance.framework.flash.duplicated', [
                '%name%' => $framework->getName(),
            ]));

            return $this->redirectToRoute('app_compliance_framework_edit', ['id' => $newFramework->getId()]);
        }

        return $this->redirectToRoute('app_compliance_framework_show', ['id' => $framework->getId()]);
    }
}
