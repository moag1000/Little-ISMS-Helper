<?php

namespace App\Controller;

use App\Entity\DataBreach;
use App\Form\DataBreachType;
use App\Service\DataBreachService;
use App\Service\PdfExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/{_locale}/data-breach', name: 'app_data_breach_', requirements: ['_locale' => 'de|en'])]
#[IsGranted('ROLE_USER')]
class DataBreachController extends AbstractController
{
    public function __construct(
        private DataBreachService $service,
        private PdfExportService $pdfService,
    ) {
    }

    /**
     * List all data breaches with filters
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = $request->query->get('filter', 'all');

        $breaches = match ($filter) {
            'draft' => $this->service->findByStatus('draft'),
            'under_assessment' => $this->service->findByStatus('under_assessment'),
            'authority_notified' => $this->service->findByStatus('authority_notified'),
            'subjects_notified' => $this->service->findByStatus('subjects_notified'),
            'closed' => $this->service->findByStatus('closed'),
            'high_risk' => $this->service->findHighRisk(),
            'critical_risk' => $this->service->findByRiskLevel('critical'),
            'pending_authority' => $this->service->findRequiringAuthorityNotification(),
            'overdue' => $this->service->findAuthorityNotificationOverdue(),
            'pending_subjects' => $this->service->findRequiringSubjectNotification(),
            'special_categories' => $this->service->findWithSpecialCategories(),
            'incomplete' => $this->service->findIncomplete(),
            default => $this->service->findAll(),
        };

        return $this->render('data_breach/index.html.twig', [
            'breaches' => $breaches,
            'current_filter' => $filter,
            'statistics' => $this->service->getDashboardStatistics(),
            'compliance_score' => $this->service->calculateComplianceScore(),
        ]);
    }

    /**
     * Dashboard with action items and compliance overview
     */
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $statistics = $this->service->getDashboardStatistics();
        $complianceScore = $this->service->calculateComplianceScore();
        $actionItems = $this->service->getActionItems();

        return $this->render('data_breach/dashboard.html.twig', [
            'statistics' => $statistics,
            'compliance_score' => $complianceScore,
            'action_items' => $actionItems,
            'overdue_breaches' => $this->service->findAuthorityNotificationOverdue(),
            'pending_authority' => $this->service->findRequiringAuthorityNotification(),
            'pending_subjects' => $this->service->findRequiringSubjectNotification(),
            'recent_breaches' => $this->service->findRecent(30),
        ]);
    }

    /**
     * Show data breach details
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(DataBreach $breach): Response
    {
        return $this->render('data_breach/show.html.twig', [
            'breach' => $breach,
        ]);
    }

    /**
     * Create new data breach
     */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function new(Request $request): Response
    {
        $form = $this->createForm(DataBreachType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var DataBreach $breach */
            $breach = $form->getData();
            $incident = $breach->getIncident();
            $processingActivity = $breach->getProcessingActivity();

            $breach = $this->service->createFromIncident(
                $incident,
                $this->getUser(),
                $processingActivity
            );

            // Update additional fields from form
            $breach->setTitle($form->get('title')->getData());
            $breach->setAffectedDataSubjects($form->get('affectedDataSubjects')->getData());
            $breach->setDataCategories($form->get('dataCategories')->getData());
            $breach->setDataSubjectCategories($form->get('dataSubjectCategories')->getData());
            $breach->setBreachNature($form->get('breachNature')->getData());
            $breach->setLikelyConsequences($form->get('likelyConsequences')->getData());
            $breach->setMeasuresTaken($form->get('measuresTaken')->getData());
            $breach->setMitigationMeasures($form->get('mitigationMeasures')->getData());
            $breach->setRiskLevel($form->get('riskLevel')->getData());
            $breach->setRiskAssessment($form->get('riskAssessment')->getData());
            $breach->setSpecialCategoriesAffected($form->get('specialCategoriesAffected')->getData());
            $breach->setCriminalDataAffected($form->get('criminalDataAffected')->getData());
            $breach->setRequiresAuthorityNotification($form->get('requiresAuthorityNotification')->getData());
            $breach->setRequiresSubjectNotification($form->get('requiresSubjectNotification')->getData());

            if ($form->has('noSubjectNotificationReason')) {
                $breach->setNoSubjectNotificationReason($form->get('noSubjectNotificationReason')->getData());
            }
            if ($form->has('dataProtectionOfficer')) {
                $breach->setDataProtectionOfficer($form->get('dataProtectionOfficer')->getData());
            }

            $this->service->update($breach, $this->getUser());

            $this->addFlash('success', sprintf(
                'Data breach %s created successfully.',
                $breach->getReferenceNumber()
            ));

            return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
        }

        return $this->render('data_breach/new.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Edit data breach
     */
    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function edit(Request $request, DataBreach $breach): Response
    {
        if (!in_array($breach->getStatus(), ['draft', 'under_assessment'])) {
            $this->addFlash('error', 'Cannot edit data breach in current status.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
        }

        $form = $this->createForm(DataBreachType::class, $breach);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->service->update($breach, $this->getUser());

            $this->addFlash('success', 'Data breach updated successfully.');

            return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
        }

        return $this->render('data_breach/edit.html.twig', [
            'breach' => $breach,
            'form' => $form,
        ]);
    }

    /**
     * Delete data breach
     */
    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function delete(Request $request, DataBreach $breach): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete' . $breach->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_breach_index');
        }

        $this->service->delete($breach);

        $this->addFlash('success', 'Data breach deleted successfully.');

        return $this->redirectToRoute('app_data_breach_index');
    }

    // =========================================================================
    // WORKFLOW ACTIONS
    // =========================================================================

    /**
     * Submit data breach for assessment
     */
    #[Route('/{id}/submit-for-assessment', name: 'submit_for_assessment', methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function submitForAssessment(Request $request, DataBreach $breach): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('submit' . $breach->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
        }

        try {
            $this->service->submitForAssessment($breach, $this->getUser());
            $this->addFlash('success', 'Data breach submitted for assessment.');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
    }

    /**
     * Notify supervisory authority (Art. 33 GDPR)
     */
    #[Route('/{id}/notify-authority', name: 'notify_authority', methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function notifyAuthority(Request $request, DataBreach $breach): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('notify_authority' . $breach->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
        }

        $authorityName = $request->request->get('authority_name');
        $notificationMethod = $request->request->get('notification_method');
        $authorityReference = $request->request->get('authority_reference');
        $delayReason = $request->request->get('delay_reason');

        if (!$authorityName || !$notificationMethod) {
            $this->addFlash('error', 'Authority name and notification method are required.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
        }

        try {
            $this->service->notifySupervisoryAuthority(
                $breach,
                $authorityName,
                $notificationMethod,
                $authorityReference,
                []
            );

            // Record delay reason if overdue
            if ($delayReason && $breach->isAuthorityNotificationOverdue()) {
                $this->service->recordNotificationDelay($breach, $delayReason);
            }

            $this->addFlash('success', 'Supervisory authority notification recorded.');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
    }

    /**
     * Notify data subjects (Art. 34 GDPR)
     */
    #[Route('/{id}/notify-subjects', name: 'notify_subjects', methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function notifySubjects(Request $request, DataBreach $breach): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('notify_subjects' . $breach->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
        }

        $notificationMethod = $request->request->get('notification_method');
        $subjectsNotified = (int) $request->request->get('subjects_notified');

        if (!$notificationMethod || $subjectsNotified <= 0) {
            $this->addFlash('error', 'Notification method and number of subjects are required.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
        }

        try {
            $this->service->notifyDataSubjects($breach, $notificationMethod, $subjectsNotified, []);
            $this->addFlash('success', 'Data subject notification recorded.');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
    }

    /**
     * Record exemption from data subject notification (Art. 34(3) GDPR)
     */
    #[Route('/{id}/subject-notification-exemption', name: 'subject_notification_exemption', methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function subjectNotificationExemption(Request $request, DataBreach $breach): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('exemption' . $breach->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
        }

        $exemptionReason = $request->request->get('exemption_reason');

        if (!$exemptionReason) {
            $this->addFlash('error', 'Exemption reason is required.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
        }

        try {
            $this->service->recordSubjectNotificationExemption($breach, $exemptionReason);
            $this->addFlash('success', 'Subject notification exemption recorded.');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
    }

    /**
     * Close data breach investigation
     */
    #[Route('/{id}/close', name: 'close', methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function close(Request $request, DataBreach $breach): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('close' . $breach->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
        }

        try {
            $this->service->close($breach, $this->getUser());
            $this->addFlash('success', 'Data breach closed successfully.');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
    }

    /**
     * Reopen closed data breach
     */
    #[Route('/{id}/reopen', name: 'reopen', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function reopen(Request $request, DataBreach $breach): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('reopen' . $breach->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
        }

        $reopenReason = $request->request->get('reopen_reason');

        if (!$reopenReason) {
            $this->addFlash('error', 'Reopen reason is required.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
        }

        try {
            $this->service->reopen($breach, $this->getUser(), $reopenReason);
            $this->addFlash('success', 'Data breach reopened successfully.');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_data_breach_show', ['id' => $breach->getId()]);
    }

    /**
     * Export data breach as PDF
     */
    #[Route('/{id}/export/pdf', name: 'export_pdf', methods: ['GET'])]
    public function exportPdf(DataBreach $breach): Response
    {
        $pdf = $this->pdfService->generatePdf('data_breach/data_breach_pdf.html.twig', [
            'breach' => $breach,
        ]);

        $filename = sprintf(
            'data_breach_%s_%s.pdf',
            $breach->getReferenceNumber(),
            (new \DateTime())->format('Y-m-d')
        );

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }
}
