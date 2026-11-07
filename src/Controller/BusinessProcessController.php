<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use App\Controller\Trait\InPageFormTrait;
use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\BusinessProcess;
use App\Form\BusinessProcessType;
use App\Repository\BusinessProcessRepository;
use App\Controller\Trait\LocalizedFlashTrait;
use App\Service\ModuleConfigurationService;
use App\Util\IsmsScopeFilter;
use App\Util\ListPage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class BusinessProcessController extends AbstractController
{
    use LocalizedFlashTrait;
    use ModuleGatedControllerTrait;
    use InPageFormTrait;

    public function __construct(
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ModuleConfigurationService $moduleService,
    ) {}

    protected function getFlashDomain(): string
    {
        return 'business_process';
    }

    protected function getTranslator(): TranslatorInterface
    {
        return $this->translator;
    }
    #[Route('/bcm/business-process', name: 'app_business_process_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get view filter parameter
        $view = $request->query->get('view', 'own'); // Default: inherited

        // Get business processes based on view filter
        if ($tenant) {
            $processes = match ($view) {
                'own' => $this->businessProcessRepository->findByTenant($tenant),
                'subsidiaries' => $this->businessProcessRepository->findByTenantIncludingSubsidiaries($tenant),
                default => $this->businessProcessRepository->findByTenantIncludingParent($tenant),
            };
            $inheritanceInfo = [
                'hasParent' => $tenant->getParent() !== null,
                'hasSubsidiaries' => $tenant->getSubsidiaries()->count() > 0,
                'currentView' => $view
            ];
        } else {
            $processes = $this->businessProcessRepository->findAll();
            $inheritanceInfo = [
                'hasParent' => false,
                'hasSubsidiaries' => false,
                'currentView' => 'own'
            ];
        }

        // Criticality filter (was a separate /bcm list before; one list now).
        $criticality = (string) $request->query->get('criticality', '');
        if ($criticality !== '') {
            $processes = array_values(array_filter($processes, static fn(BusinessProcess $p): bool => $p->getCriticality() === $criticality));
        }

        $processes = IsmsScopeFilter::apply($processes, $request);

        // Summary chips count the list as shown (view applied).
        $criticalCount = count(array_filter($processes, static fn(BusinessProcess $p): bool => $p->getCriticality() === 'critical'));
        $withRisksCount = count(array_filter($processes, static fn(BusinessProcess $p): bool => $p->getActiveRiskCount() > 0));

        return $this->render('business_process/index.html.twig', [
            'page' => ListPage::fromRequest(
                $processes,
                $request,
                static fn(BusinessProcess $p): array => [$p->getName(), $p->getDescription(), (string) $p->getEffectiveProcessOwner()],
            ),
            'critical_count' => $criticalCount,
            'with_risks_count' => $withRisksCount,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
            'current_criticality' => $criticality,
        ]);
    }
    #[Route('/bcm/business-process/new', name: 'app_business_process_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        $businessProcess = new BusinessProcess();

        // Set tenant from current user
        $user = $this->security->getUser();
        if ($user instanceof UserInterface && $user->getTenant()) {
            $businessProcess->setTenant($user->getTenant());
        }
        // UX-P1 T4.11 — pre-fill owner with the current user so the typical
        // single-tenant single-admin flow doesn't force a re-pick.
        if ($user instanceof \App\Entity\User && $businessProcess->getProcessOwnerUser() === null) {
            $businessProcess->setProcessOwnerUser($user);
        }

        $form = $this->createForm(BusinessProcessType::class, $businessProcess);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($businessProcess);
            $this->entityManager->flush();

            $this->flashSuccess('business_process.success.created');

            if ($this->isTurboFrameRequest($request)) {
                return $this->businessProcessStreamSave($businessProcess, isNew: true);
            }
            return $this->redirectToRoute('app_business_process_index', [], Response::HTTP_SEE_OTHER);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('business_process/_form_modal.html.twig', [
                'business_process' => $businessProcess,
                'form' => $form,
            ], new Response(status: $status));
        }

        return $this->render('business_process/new.html.twig', [
            'business_process' => $businessProcess,
            'form' => $form,
        ], new Response(status: $status));
    }
    #[Route('/bcm/business-process/{id}', name: 'app_business_process_show', methods: ['GET'])]
    public function show(Request $request, BusinessProcess $businessProcess): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        // In-modal → condensed read-only detail; direct URL → full page (fallback).
        if ($this->isTurboFrameRequest($request)) {
            return $this->render('business_process/_detail_modal.html.twig', [
                'business_process' => $businessProcess,
            ]);
        }

        // Calculate additional metrics for display
        $metrics = [
            'business_impact_score' => $businessProcess->getBusinessImpactScore(),
            'suggested_availability' => $businessProcess->getSuggestedAvailabilityValue(),
            'process_risk_level' => $businessProcess->getProcessRiskLevel(),
            'criticality_aligned' => $businessProcess->isCriticalityAligned(),
            'active_risks' => $businessProcess->getActiveRiskCount(),
            'unmitigated_high_risks' => $businessProcess->hasUnmitigatedHighRisks(),
            'suggested_rto' => $businessProcess->getSuggestedRTO(),
        ];

        return $this->render('business_process/show.html.twig', [
            'business_process' => $businessProcess,
            'metrics' => $metrics,
        ]);
    }
    #[Route('/bcm/business-process/{id}/edit', name: 'app_business_process_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function edit(Request $request, BusinessProcess $businessProcess, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        $form = $this->createForm(BusinessProcessType::class, $businessProcess);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->flashSuccess('business_process.success.updated');

            if ($this->isTurboFrameRequest($request)) {
                return $this->businessProcessStreamSave($businessProcess, isNew: false);
            }
            return $this->redirectToRoute('app_business_process_index', [], Response::HTTP_SEE_OTHER);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('business_process/_form_modal.html.twig', [
                'business_process' => $businessProcess,
                'form' => $form,
            ], new Response(status: $status));
        }

        return $this->render('business_process/edit.html.twig', [
            'business_process' => $businessProcess,
            'form' => $form,
        ], new Response(status: $status));
    }
    #[Route('/bcm/business-process/{id}', name: 'app_business_process_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, BusinessProcess $businessProcess, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        if ($this->isCsrfTokenValid('delete'.$businessProcess->getId(), $request->request->get('_token'))) {
            $entityManager->remove($businessProcess);
            $entityManager->flush();

            $this->flashSuccess('business_process.success.deleted');
        }

        return $this->redirectToRoute('app_business_process_index', [], Response::HTTP_SEE_OTHER);
    }
    #[Route('/bcm/business-process/{id}/bia', name: 'app_business_process_bia', methods: ['GET'])]
    public function bia(BusinessProcess $businessProcess): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        // Business Impact Analysis view
        $biaData = [
            'rto' => $businessProcess->getRto(),
            'rpo' => $businessProcess->getRpo(),
            'mtpd' => $businessProcess->getMtpd(),
            'financial_impact_hour' => $businessProcess->getFinancialImpactPerHour(),
            'financial_impact_day' => $businessProcess->getFinancialImpactPerDay(),
            'reputational_impact' => $businessProcess->getReputationalImpact(),
            'regulatory_impact' => $businessProcess->getRegulatoryImpact(),
            'operational_impact' => $businessProcess->getOperationalImpact(),
            'business_impact_score' => $businessProcess->getBusinessImpactScore(),
            'suggested_availability' => $businessProcess->getSuggestedAvailabilityValue(),
        ];

        return $this->render('business_process/bia.html.twig', [
            'business_process' => $businessProcess,
            'bia_data' => $biaData,
        ]);
    }
    /** Turbo Stream after a successful in-modal BusinessProcess save (row replace/append). */
    private function businessProcessStreamSave(BusinessProcess $businessProcess, bool $isNew): Response
    {
        return $this->render('business_process/_stream_save.html.twig', [
            'business_process' => $businessProcess,
            'is_new' => $isNew,
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }
}
