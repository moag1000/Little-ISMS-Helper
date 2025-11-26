<?php

namespace App\Controller;

use App\Entity\RiskTreatmentPlan;
use App\Form\RiskTreatmentPlanType;
use App\Repository\AuditLogRepository;
use App\Repository\RiskTreatmentPlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/risk-treatment-plan')]
class RiskTreatmentPlanController extends AbstractController
{
    public function __construct(
        private RiskTreatmentPlanRepository $treatmentPlanRepository,
        private AuditLogRepository $auditLogRepository,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator
    ) {}

    #[Route('/', name: 'app_risk_treatment_plan_index')]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        // Get filter parameters
        $status = $request->query->get('status');
        $priority = $request->query->get('priority');
        $responsiblePerson = $request->query->get('responsible_person');
        $overdueOnly = $request->query->get('overdue_only');

        // Get all treatment plans
        $plans = $this->treatmentPlanRepository->findAll();

        // Apply filters
        if ($status) {
            $plans = array_filter($plans, fn($plan) => $plan->getStatus() === $status);
        }

        if ($priority) {
            $plans = array_filter($plans, fn($plan) => $plan->getPriority() === $priority);
        }

        if ($responsiblePerson) {
            $plans = array_filter($plans, fn($plan) =>
                $plan->getResponsiblePerson() &&
                stripos($plan->getResponsiblePerson()->getFullName(), $responsiblePerson) !== false
            );
        }

        if ($overdueOnly === '1') {
            $plans = array_filter($plans, fn($plan) => $plan->isOverdue());
        }

        // Re-index array after filtering to avoid gaps in keys
        $plans = array_values($plans);

        // Get statistics
        $stats = $this->treatmentPlanRepository->getStatisticsForTenant(null);
        $overduePlans = $this->treatmentPlanRepository->findOverdueForTenant(null);
        $criticalPlans = $this->treatmentPlanRepository->findCriticalPlans(null);

        return $this->render('risk_treatment_plan/index.html.twig', [
            'plans' => $plans,
            'stats' => $stats,
            'overduePlans' => $overduePlans,
            'criticalPlans' => $criticalPlans,
        ]);
    }

    #[Route('/new', name: 'app_risk_treatment_plan_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $plan = new RiskTreatmentPlan();
        $form = $this->createForm(RiskTreatmentPlanType::class, $plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($plan);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk_treatment_plan.success.created'));
            return $this->redirectToRoute('app_risk_treatment_plan_show', ['id' => $plan->getId()]);
        }

        return $this->render('risk_treatment_plan/new.html.twig', [
            'plan' => $plan,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_risk_treatment_plan_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(RiskTreatmentPlan $plan): Response
    {
        // Get audit log history for this plan (last 10 entries)
        $auditLogs = $this->auditLogRepository->findByEntity('RiskTreatmentPlan', $plan->getId());
        $recentAuditLogs = array_slice($auditLogs, 0, 10);

        return $this->render('risk_treatment_plan/show.html.twig', [
            'plan' => $plan,
            'auditLogs' => $recentAuditLogs,
            'totalAuditLogs' => count($auditLogs),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_risk_treatment_plan_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, RiskTreatmentPlan $plan): Response
    {
        $form = $this->createForm(RiskTreatmentPlanType::class, $plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plan->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk_treatment_plan.success.updated'));
            return $this->redirectToRoute('app_risk_treatment_plan_show', ['id' => $plan->getId()]);
        }

        return $this->render('risk_treatment_plan/edit.html.twig', [
            'plan' => $plan,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_risk_treatment_plan_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, RiskTreatmentPlan $plan): Response
    {
        if ($this->isCsrfTokenValid('delete'.$plan->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($plan);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk_treatment_plan.success.deleted'));
        }

        return $this->redirectToRoute('app_risk_treatment_plan_index');
    }
}
