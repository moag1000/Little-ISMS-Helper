<?php

namespace App\Controller;

use App\Entity\Tenant;
use DateTimeImmutable;
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

class ComplianceFrameworkController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceFrameworkRepository $complianceFrameworkRepository,
        private readonly ComplianceRequirementRepository $complianceRequirementRepository,
        private readonly ComplianceRequirementFulfillmentService $complianceRequirementFulfillmentService,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator
    ) {}
    #[Route('/compliance/framework/', name: 'app_compliance_framework_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        $filters = [
            'active' => $request->query->get('active'),
            'mandatory' => $request->query->get('mandatory'),
            'industry' => $request->query->get('industry'),
        ];

        $queryBuilder = $this->complianceFrameworkRepository->createQueryBuilder('f')
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

        if (isset($filters['industry']) && ($filters['industry'] !== '' && $filters['industry'] !== '0')) {
            $queryBuilder->andWhere('f.applicableIndustry = :industry')
                ->setParameter('industry', $filters['industry']);
        }

        $frameworks = $queryBuilder->getQuery()->getResult();

        // Get unique industries for filter
        $industries = $this->complianceFrameworkRepository->createQueryBuilder('f')
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
    #[Route('/compliance/framework/new', name: 'app_compliance_framework_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(Request $request): Response
    {
        $complianceFramework = new ComplianceFramework();
        // Note: ComplianceFramework is a global entity (ISO 27001, GDPR, etc.) and not tenant-specific

        $form = $this->createForm(ComplianceFrameworkType::class, $complianceFramework);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($complianceFramework);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('compliance.framework.flash.created'));

            return $this->redirectToRoute('app_compliance_framework_show', ['id' => $complianceFramework->getId()]);
        }

        return $this->render('compliance/framework/new.html.twig', [
            'framework' => $complianceFramework,
            'form' => $form,
        ]);
    }
    #[Route('/compliance/framework/{id}', name: 'app_compliance_framework_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(ComplianceFramework $complianceFramework): Response
    {
        // Calculate statistics using tenant-specific fulfillment data
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('No tenant assigned to user. Please contact administrator.');
        }

        // For SUPER_ADMIN without tenant, show empty statistics
        if ($tenant instanceof Tenant) {
            $stats = $this->complianceRequirementRepository->getFrameworkStatisticsForTenant($complianceFramework, $tenant);
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
        foreach ($complianceFramework->getRequirements() as $requirement) {
            $fulfillment = $this->complianceRequirementFulfillmentService->getOrCreateFulfillment($tenant, $requirement);
            $requirementFulfillments[$requirement->getId()] = $fulfillment;
        }

        // Group requirements by category
        $requirementsByCategory = [];
        foreach ($complianceFramework->getRequirements() as $requirement) {
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
        foreach ($complianceFramework->getRequirements() as $requirement) {
            $priority = $requirement->getPriority();
            if (isset($priorityDistribution[$priority])) {
                $priorityDistribution[$priority]++;
            }
        }

        return $this->render('compliance/framework/show.html.twig', [
            'framework' => $complianceFramework,
            'total_requirements' => $totalRequirements,
            'applicable_requirements' => $applicableRequirements,
            'fulfilled_requirements' => $fulfilledRequirements,
            'compliance_percentage' => $compliancePercentage,
            'requirement_fulfillments' => $requirementFulfillments,
            'requirements_by_category' => $requirementsByCategory,
            'priority_distribution' => $priorityDistribution,
        ]);
    }
    #[Route('/compliance/framework/{id}/edit', name: 'app_compliance_framework_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(Request $request, ComplianceFramework $complianceFramework): Response
    {
        $form = $this->createForm(ComplianceFrameworkType::class, $complianceFramework);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $complianceFramework->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('compliance.framework.flash.updated'));

            return $this->redirectToRoute('app_compliance_framework_show', ['id' => $complianceFramework->getId()]);
        }

        return $this->render('compliance/framework/edit.html.twig', [
            'framework' => $complianceFramework,
            'form' => $form,
        ]);
    }
    #[Route('/compliance/framework/{id}/delete', name: 'app_compliance_framework_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, ComplianceFramework $complianceFramework): Response
    {
        if ($this->isCsrfTokenValid('delete' . $complianceFramework->getId(), $request->request->get('_token'))) {
            $frameworkName = $complianceFramework->getName();

            // Check if framework has requirements
            if ($complianceFramework->getRequirements()->count() > 0) {
                $this->addFlash('warning', $this->translator->trans('compliance.framework.flash.has_requirements', [
                    '%name%' => $frameworkName,
                    '%count%' => $complianceFramework->getRequirements()->count(),
                ]));

                return $this->redirectToRoute('app_compliance_framework_show', ['id' => $complianceFramework->getId()]);
            }

            $this->entityManager->remove($complianceFramework);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('compliance.framework.flash.deleted', [
                '%name%' => $frameworkName,
            ]));
        }

        return $this->redirectToRoute('app_compliance_framework_index');
    }
    #[Route('/compliance/framework/{id}/toggle', name: 'app_compliance_framework_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggle(Request $request, ComplianceFramework $complianceFramework): Response
    {
        if ($this->isCsrfTokenValid('toggle' . $complianceFramework->getId(), $request->request->get('_token'))) {
            $complianceFramework->setActive(!$complianceFramework->isActive());
            $complianceFramework->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $status = $complianceFramework->isActive() ? 'activated' : 'deactivated';
            $this->addFlash('success', $this->translator->trans('compliance.framework.flash.' . $status, [
                '%name%' => $complianceFramework->getName(),
            ]));
        }

        return $this->redirectToRoute('app_compliance_framework_show', ['id' => $complianceFramework->getId()]);
    }
    #[Route('/compliance/framework/{id}/duplicate', name: 'app_compliance_framework_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function duplicate(Request $request, ComplianceFramework $complianceFramework): Response
    {
        if ($this->isCsrfTokenValid('duplicate' . $complianceFramework->getId(), $request->request->get('_token'))) {
            $newFramework = new ComplianceFramework();
            $newFramework->setCode($complianceFramework->getCode() . '_COPY');
            $newFramework->setName($complianceFramework->getName() . ' (Copy)');
            $newFramework->setDescription($complianceFramework->getDescription());
            $newFramework->setVersion($complianceFramework->getVersion());
            $newFramework->setApplicableIndustry($complianceFramework->getApplicableIndustry());
            $newFramework->setRegulatoryBody($complianceFramework->getRegulatoryBody());
            $newFramework->setMandatory($complianceFramework->isMandatory());
            $newFramework->setScopeDescription($complianceFramework->getScopeDescription());
            $newFramework->setActive(false); // Start inactive
            $newFramework->setRequiredModules($complianceFramework->getRequiredModules());

            $this->entityManager->persist($newFramework);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('compliance.framework.flash.duplicated', [
                '%name%' => $complianceFramework->getName(),
            ]));

            return $this->redirectToRoute('app_compliance_framework_edit', ['id' => $newFramework->getId()]);
        }

        return $this->redirectToRoute('app_compliance_framework_show', ['id' => $complianceFramework->getId()]);
    }
}
