<?php

namespace App\Controller;

use App\Entity\User;
use DateTimeImmutable;
use App\Entity\RiskTreatmentPlan;
use App\Form\RiskTreatmentPlanType;
use App\Repository\AuditLogRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class RiskTreatmentPlanController extends AbstractController
{
    public function __construct(
        private readonly RiskTreatmentPlanRepository $riskTreatmentPlanRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext
    ) {}
    #[Route('/risk-treatment-plan/', name: 'app_risk_treatment_plan_index')]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        // Get filter parameters
        $status = $request->query->get('status');
        $priority = $request->query->get('priority');
        $responsiblePerson = $request->query->get('responsible_person');
        $overdueOnly = $request->query->get('overdue_only');

        // Get all treatment plans
        $plans = $this->riskTreatmentPlanRepository->findAll();

        // Apply filters
        if ($status) {
            $plans = array_filter($plans, fn(RiskTreatmentPlan $plan): bool => $plan->getStatus() === $status);
        }

        if ($priority) {
            $plans = array_filter($plans, fn(RiskTreatmentPlan $plan): bool => $plan->getPriority() === $priority);
        }

        if ($responsiblePerson) {
            $plans = array_filter($plans, fn(RiskTreatmentPlan $plan): bool =>
                $plan->getResponsiblePerson() instanceof User &&
                stripos($plan->getResponsiblePerson()->getFullName(), $responsiblePerson) !== false
            );
        }

        if ($overdueOnly === '1') {
            $plans = array_filter($plans, fn(RiskTreatmentPlan $plan): bool => $plan->isOverdue());
        }

        // Re-index array after filtering to avoid gaps in keys
        $plans = array_values($plans);

        // Get statistics
        $stats = $this->riskTreatmentPlanRepository->getStatisticsForTenant(null);
        $overduePlans = $this->riskTreatmentPlanRepository->findOverdueForTenant(null);
        $criticalPlans = $this->riskTreatmentPlanRepository->findCriticalPlans(null);

        return $this->render('risk_treatment_plan/index.html.twig', [
            'plans' => $plans,
            'stats' => $stats,
            'overduePlans' => $overduePlans,
            'criticalPlans' => $criticalPlans,
        ]);
    }
    #[Route('/risk-treatment-plan/new', name: 'app_risk_treatment_plan_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $riskTreatmentPlan = new RiskTreatmentPlan();
        $riskTreatmentPlan->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(RiskTreatmentPlanType::class, $riskTreatmentPlan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($riskTreatmentPlan);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk_treatment_plan.success.created'));
            return $this->redirectToRoute('app_risk_treatment_plan_show', ['id' => $riskTreatmentPlan->getId()]);
        }

        return $this->render('risk_treatment_plan/new.html.twig', [
            'plan' => $riskTreatmentPlan,
            'form' => $form,
        ]);
    }
    #[Route('/risk-treatment-plan/{id}', name: 'app_risk_treatment_plan_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(RiskTreatmentPlan $riskTreatmentPlan): Response
    {
        // Get audit log history for this plan (last 10 entries)
        $auditLogs = $this->auditLogRepository->findByEntity('RiskTreatmentPlan', $riskTreatmentPlan->getId());
        $recentAuditLogs = array_slice($auditLogs, 0, 10);

        return $this->render('risk_treatment_plan/show.html.twig', [
            'plan' => $riskTreatmentPlan,
            'auditLogs' => $recentAuditLogs,
            'totalAuditLogs' => count($auditLogs),
        ]);
    }
    #[Route('/risk-treatment-plan/{id}/edit', name: 'app_risk_treatment_plan_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, RiskTreatmentPlan $riskTreatmentPlan): Response
    {
        $form = $this->createForm(RiskTreatmentPlanType::class, $riskTreatmentPlan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $riskTreatmentPlan->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk_treatment_plan.success.updated'));
            return $this->redirectToRoute('app_risk_treatment_plan_show', ['id' => $riskTreatmentPlan->getId()]);
        }

        return $this->render('risk_treatment_plan/edit.html.twig', [
            'plan' => $riskTreatmentPlan,
            'form' => $form,
        ]);
    }
    #[Route('/risk-treatment-plan/{id}/delete', name: 'app_risk_treatment_plan_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, RiskTreatmentPlan $riskTreatmentPlan): Response
    {
        if ($this->isCsrfTokenValid('delete'.$riskTreatmentPlan->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($riskTreatmentPlan);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk_treatment_plan.success.deleted'));
        }

        return $this->redirectToRoute('app_risk_treatment_plan_index');
    }
}
