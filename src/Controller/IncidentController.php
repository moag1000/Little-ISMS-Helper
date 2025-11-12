<?php

namespace App\Controller;

use App\Entity\Incident;
use App\Form\IncidentType;
use App\Repository\AuditLogRepository;
use App\Repository\IncidentRepository;
use App\Service\EmailNotificationService;
use App\Service\PdfExportService;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/incident')]
class IncidentController extends AbstractController
{
    public function __construct(
        private IncidentRepository $incidentRepository,
        private AuditLogRepository $auditLogRepository,
        private EntityManagerInterface $entityManager,
        private EmailNotificationService $emailService,
        private PdfExportService $pdfService,
        private UserRepository $userRepository,
        private TranslatorInterface $translator
    ) {}

    #[Route('/', name: 'app_incident_index')]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        // Get filter parameters
        $severity = $request->query->get('severity');
        $category = $request->query->get('category');
        $status = $request->query->get('status');
        $dataBreachOnly = $request->query->get('data_breach_only');
        $nis2Only = $request->query->get('nis2_only');

        // Get all incidents
        $allIncidents = $this->incidentRepository->findAll();

        // Apply filters
        if ($severity) {
            $allIncidents = array_filter($allIncidents, fn($incident) => $incident->getSeverity() === $severity);
        }

        if ($category) {
            $allIncidents = array_filter($allIncidents, fn($incident) => $incident->getCategory() === $category);
        }

        if ($status) {
            $allIncidents = array_filter($allIncidents, fn($incident) => $incident->getStatus() === $status);
        }

        if ($dataBreachOnly === '1') {
            $allIncidents = array_filter($allIncidents, fn($incident) => $incident->isDataBreachOccurred());
        }

        if ($nis2Only === '1') {
            $allIncidents = array_filter($allIncidents, fn($incident) => $incident->requiresNis2Reporting());
        }

        // Re-index array after filtering to avoid gaps in keys
        $allIncidents = array_values($allIncidents);

        $openIncidents = $this->incidentRepository->findOpenIncidents();
        $categoryStats = $this->incidentRepository->countByCategory();
        $severityStats = $this->incidentRepository->countBySeverity();

        return $this->render('incident/index.html.twig', [
            'openIncidents' => $openIncidents,
            'allIncidents' => $allIncidents,
            'categoryStats' => $categoryStats,
            'severityStats' => $severityStats,
        ]);
    }

    #[Route('/new', name: 'app_incident_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $incident = new Incident();
        $form = $this->createForm(IncidentType::class, $incident);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($incident);
            $this->entityManager->flush();

            // Send notification for high/critical severity incidents
            if (in_array($incident->getSeverity(), ['high', 'critical'])) {
                $admins = $this->userRepository->findByRole('ROLE_ADMIN');
                $this->emailService->sendIncidentNotification($incident, $admins);
            }

            $this->addFlash('success', $this->translator->trans('incident.success.reported'));
            return $this->redirectToRoute('app_incident_show', ['id' => $incident->getId()]);
        }

        return $this->render('incident/new.html.twig', [
            'incident' => $incident,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_incident_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(Incident $incident): Response
    {
        // Get audit log history for this incident (last 10 entries)
        $auditLogs = $this->auditLogRepository->findByEntity('Incident', $incident->getId());
        $recentAuditLogs = array_slice($auditLogs, 0, 10);

        return $this->render('incident/show.html.twig', [
            'incident' => $incident,
            'auditLogs' => $recentAuditLogs,
            'totalAuditLogs' => count($auditLogs),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_incident_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Incident $incident): Response
    {
        $originalStatus = $incident->getStatus();
        $form = $this->createForm(IncidentType::class, $incident);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            // Send notification if status changed
            if ($originalStatus !== $incident->getStatus()) {
                $admins = $this->userRepository->findByRole('ROLE_ADMIN');
                $changeDescription = "Status changed from {$originalStatus} to {$incident->getStatus()}";
                $this->emailService->sendIncidentUpdateNotification($incident, $admins, $changeDescription);
            }

            $this->addFlash('success', $this->translator->trans('incident.success.updated'));
            return $this->redirectToRoute('app_incident_show', ['id' => $incident->getId()]);
        }

        return $this->render('incident/edit.html.twig', [
            'incident' => $incident,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_incident_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Incident $incident): Response
    {
        if ($this->isCsrfTokenValid('delete'.$incident->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($incident);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('incident.success.deleted'));
        }

        return $this->redirectToRoute('app_incident_index');
    }

    /**
     * Download NIS2 Incident Report as PDF
     *
     * Generates a NIS2-compliant incident report according to Article 23
     * of Directive (EU) 2022/2555 for submission to competent authorities.
     */
    #[Route('/{id}/nis2-report.pdf', name: 'app_incident_nis2_report', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function downloadNis2Report(Incident $incident): Response
    {
        // Verify that the incident requires NIS2 reporting
        if (!$incident->requiresNis2Reporting()) {
            $this->addFlash('warning', 'This incident does not require NIS2 reporting.');
            return $this->redirectToRoute('app_incident_show', ['id' => $incident->getId()]);
        }

        // Generate filename with incident number and timestamp
        $filename = sprintf(
            'NIS2-Report-%s-%s.pdf',
            $incident->getIncidentNumber() ?? $incident->getId(),
            date('Ymd-His')
        );

        // Generate PDF
        $pdf = $this->pdfService->generatePdf(
            'incident/nis2_report_pdf.html.twig',
            ['incident' => $incident],
            ['orientation' => 'portrait', 'paper' => 'A4']
        );

        // Return PDF as download
        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Content-Length' => strlen($pdf),
        ]);
    }
}
