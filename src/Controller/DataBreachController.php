<?php

namespace App\Controller;

use RuntimeException;
use DateTime;
use App\Entity\DataBreach;
use App\Form\DataBreachType;
use App\Service\DataBreachService;
use App\Service\PdfExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/data-breach', name: 'app_data_breach_')]
#[IsGranted('ROLE_USER')]
class DataBreachController extends AbstractController
{
    public function __construct(
        private readonly DataBreachService $dataBreachService,
        private readonly PdfExportService $pdfExportService,
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
            'draft' => $this->dataBreachService->findByStatus('draft'),
            'under_assessment' => $this->dataBreachService->findByStatus('under_assessment'),
            'authority_notified' => $this->dataBreachService->findByStatus('authority_notified'),
            'subjects_notified' => $this->dataBreachService->findByStatus('subjects_notified'),
            'closed' => $this->dataBreachService->findByStatus('closed'),
            'high_risk' => $this->dataBreachService->findHighRisk(),
            'critical_risk' => $this->dataBreachService->findByRiskLevel('critical'),
            'pending_authority' => $this->dataBreachService->findRequiringAuthorityNotification(),
            'overdue' => $this->dataBreachService->findAuthorityNotificationOverdue(),
            'pending_subjects' => $this->dataBreachService->findRequiringSubjectNotification(),
            'special_categories' => $this->dataBreachService->findWithSpecialCategories(),
            'incomplete' => $this->dataBreachService->findIncomplete(),
            default => $this->dataBreachService->findAll(),
        };

        return $this->render('data_breach/index.html.twig', [
            'breaches' => $breaches,
            'current_filter' => $filter,
            'statistics' => $this->dataBreachService->getDashboardStatistics(),
            'compliance_score' => $this->dataBreachService->calculateComplianceScore(),
        ]);
    }

    /**
     * Dashboard with action items and compliance overview
     */
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $statistics = $this->dataBreachService->getDashboardStatistics();
        $complianceScore = $this->dataBreachService->calculateComplianceScore();
        $actionItems = $this->dataBreachService->getActionItems();

        return $this->render('data_breach/dashboard.html.twig', [
            'statistics' => $statistics,
            'compliance_score' => $complianceScore,
            'action_items' => $actionItems,
            'overdue_breaches' => $this->dataBreachService->findAuthorityNotificationOverdue(),
            'pending_authority' => $this->dataBreachService->findRequiringAuthorityNotification(),
            'pending_subjects' => $this->dataBreachService->findRequiringSubjectNotification(),
            'recent_breaches' => $this->dataBreachService->findRecent(30),
        ]);
    }

    /**
     * Create new data breach
     * Supports both standalone breaches and incident-linked breaches
     */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function new(Request $request): Response
    {
        // Create a new breach with tenant and reference number pre-set
        $breach = $this->dataBreachService->prepareNewBreach();

        $form = $this->createForm(DataBreachType::class, $breach);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Form data is already bound to $breach via handleRequest
            // Just save it
            $this->dataBreachService->update($breach, $this->getUser());

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
     * Show data breach details
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(DataBreach $dataBreach): Response
    {
        return $this->render('data_breach/show.html.twig', [
            'breach' => $dataBreach,
        ]);
    }

    /**
     * Edit data breach
     */
    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function edit(Request $request, DataBreach $dataBreach): Response
    {
        if (!in_array($dataBreach->getStatus(), ['draft', 'under_assessment'])) {
            $this->addFlash('error', 'Cannot edit data breach in current status.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
        }

        $form = $this->createForm(DataBreachType::class, $dataBreach);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->dataBreachService->update($dataBreach, $this->getUser());

            $this->addFlash('success', 'Data breach updated successfully.');

            return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
        }

        return $this->render('data_breach/edit.html.twig', [
            'breach' => $dataBreach,
            'form' => $form,
        ]);
    }

    /**
     * Delete data breach
     */
    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function delete(Request $request, DataBreach $dataBreach): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete' . $dataBreach->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_breach_index');
        }

        $this->dataBreachService->delete($dataBreach);

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
    public function submitForAssessment(Request $request, DataBreach $dataBreach): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('submit' . $dataBreach->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
        }

        try {
            $this->dataBreachService->submitForAssessment($dataBreach, $this->getUser());
            $this->addFlash('success', 'Data breach submitted for assessment.');
        } catch (RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
    }

    /**
     * Notify supervisory authority (Art. 33 GDPR)
     */
    #[Route('/{id}/notify-authority', name: 'notify_authority', methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function notifyAuthority(Request $request, DataBreach $dataBreach): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('notify_authority' . $dataBreach->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
        }

        $authorityName = $request->request->get('authority_name');
        $notificationMethod = $request->request->get('notification_method');
        $authorityReference = $request->request->get('authority_reference');
        $delayReason = $request->request->get('delay_reason');

        if (!$authorityName || !$notificationMethod) {
            $this->addFlash('error', 'Authority name and notification method are required.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
        }

        try {
            $this->dataBreachService->notifySupervisoryAuthority(
                $dataBreach,
                $authorityName,
                $notificationMethod,
                $authorityReference,
                []
            );

            // Record delay reason if overdue
            if ($delayReason && $dataBreach->isAuthorityNotificationOverdue()) {
                $this->dataBreachService->recordNotificationDelay($dataBreach, $delayReason);
            }

            $this->addFlash('success', 'Supervisory authority notification recorded.');
        } catch (RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
    }

    /**
     * Notify data subjects (Art. 34 GDPR)
     */
    #[Route('/{id}/notify-subjects', name: 'notify_subjects', methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function notifySubjects(Request $request, DataBreach $dataBreach): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('notify_subjects' . $dataBreach->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
        }

        $notificationMethod = $request->request->get('notification_method');
        $subjectsNotified = (int) $request->request->get('subjects_notified');

        if (!$notificationMethod || $subjectsNotified <= 0) {
            $this->addFlash('error', 'Notification method and number of subjects are required.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
        }

        try {
            $this->dataBreachService->notifyDataSubjects($dataBreach, $notificationMethod, $subjectsNotified, []);
            $this->addFlash('success', 'Data subject notification recorded.');
        } catch (RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
    }

    /**
     * Record exemption from data subject notification (Art. 34(3) GDPR)
     */
    #[Route('/{id}/subject-notification-exemption', name: 'subject_notification_exemption', methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function subjectNotificationExemption(Request $request, DataBreach $dataBreach): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('exemption' . $dataBreach->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
        }

        $exemptionReason = $request->request->get('exemption_reason');

        if (!$exemptionReason) {
            $this->addFlash('error', 'Exemption reason is required.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
        }

        try {
            $this->dataBreachService->recordSubjectNotificationExemption($dataBreach, $exemptionReason);
            $this->addFlash('success', 'Subject notification exemption recorded.');
        } catch (RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
    }

    /**
     * Close data breach investigation
     */
    #[Route('/{id}/close', name: 'close', methods: ['POST'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function close(Request $request, DataBreach $dataBreach): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('close' . $dataBreach->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
        }

        try {
            $this->dataBreachService->close($dataBreach, $this->getUser());
            $this->addFlash('success', 'Data breach closed successfully.');
        } catch (RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
    }

    /**
     * Reopen closed data breach
     */
    #[Route('/{id}/reopen', name: 'reopen', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function reopen(Request $request, DataBreach $dataBreach): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('reopen' . $dataBreach->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
        }

        $reopenReason = $request->request->get('reopen_reason');

        if (!$reopenReason) {
            $this->addFlash('error', 'Reopen reason is required.');
            return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
        }

        try {
            $this->dataBreachService->reopen($dataBreach, $this->getUser(), $reopenReason);
            $this->addFlash('success', 'Data breach reopened successfully.');
        } catch (RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_data_breach_show', ['id' => $dataBreach->getId()]);
    }

    /**
     * Export data breach as PDF
     */
    #[Route('/{id}/export/pdf', name: 'export_pdf', methods: ['GET'])]
    public function exportPdf(DataBreach $dataBreach): Response
    {
        // Generate version from last update date (Format: Year.Month.Day)
        $lastUpdate = $dataBreach->getUpdatedAt() ?? $dataBreach->getCreatedAt() ?? new DateTime();
        $version = $lastUpdate->format('Y.m.d');

        $pdf = $this->pdfExportService->generatePdf('data_breach/data_breach_pdf.html.twig', [
            'breach' => $dataBreach,
            'version' => $version,
        ]);

        $filename = sprintf(
            'data_breach_%s_%s.pdf',
            $dataBreach->getReferenceNumber(),
            new DateTime()->format('Y-m-d')
        );

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }
}
