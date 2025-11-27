<?php

namespace App\Controller;

use App\Entity\Incident;
use App\Form\IncidentType;
use App\Repository\AuditLogRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\IncidentRepository;
use App\Service\EmailNotificationService;
use App\Service\GdprBreachAssessmentService;
use App\Service\IncidentBCMImpactService;
use App\Service\IncidentEscalationWorkflowService;
use App\Service\PdfExportService;
use App\Service\TenantContext;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
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
        private ComplianceFrameworkRepository $frameworkRepository,
        private EntityManagerInterface $entityManager,
        private EmailNotificationService $emailService,
        private GdprBreachAssessmentService $gdprAssessmentService,
        private IncidentBCMImpactService $bcmImpactService,
        private PdfExportService $pdfService,
        private UserRepository $userRepository,
        private TranslatorInterface $translator,
        private Security $security,
        private IncidentEscalationWorkflowService $escalationService,
        private TenantContext $tenantContext
    ) {}

    #[Route('/', name: 'app_incident_index')]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get filter parameters
        $severity = $request->query->get('severity');
        $category = $request->query->get('category');
        $status = $request->query->get('status');
        $dataBreachOnly = $request->query->get('data_breach_only');
        $nis2Only = $request->query->get('nis2_only');
        $view = $request->query->get('view', 'inherited'); // Default: inherited

        // Get incidents based on view filter
        if ($tenant) {
            // Determine which incidents to load based on view parameter
            switch ($view) {
                case 'own':
                    // Only own incidents
                    $allIncidents = $this->incidentRepository->findByTenant($tenant);
                    $openIncidents = array_filter($allIncidents, fn($i) => in_array($i->getStatus(), ['new', 'in_progress', 'investigating']));
                    break;
                case 'subsidiaries':
                    // Own + from all subsidiaries (for parent companies)
                    $allIncidents = $this->incidentRepository->findByTenantIncludingSubsidiaries($tenant);
                    $openIncidents = array_filter($allIncidents, fn($i) => in_array($i->getStatus(), ['new', 'in_progress', 'investigating']));
                    break;
                case 'inherited':
                default:
                    // Own + inherited from parents (default behavior)
                    $allIncidents = $this->incidentRepository->findByTenantIncludingParent($tenant);
                    $openIncidents = array_filter($allIncidents, fn($i) => in_array($i->getStatus(), ['new', 'in_progress', 'investigating']));
                    break;
            }

            $inheritanceInfo = [
                'hasParent' => $tenant->getParent() !== null,
                'hasSubsidiaries' => $tenant->getSubsidiaries()->count() > 0,
                'currentView' => $view
            ];
        } else {
            // Fallback for users without tenant (e.g., super admins)
            $allIncidents = $this->incidentRepository->findAll();
            $openIncidents = $this->incidentRepository->findOpenIncidents();
            $inheritanceInfo = [
                'hasParent' => false,
                'hasSubsidiaries' => false,
                'currentView' => 'own'
            ];
        }

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

        // Re-index arrays after filtering to avoid gaps in keys
        $allIncidents = array_values($allIncidents);
        $openIncidents = array_values($openIncidents);

        $categoryStats = $this->incidentRepository->countByCategory();
        $severityStats = $this->incidentRepository->countBySeverity();

        // Calculate detailed statistics based on origin
        if ($tenant) {
            $detailedStats = $this->calculateDetailedStats($allIncidents, $tenant);
        } else {
            $detailedStats = ['own' => count($allIncidents), 'inherited' => 0, 'subsidiaries' => 0, 'total' => count($allIncidents)];
        }

        return $this->render('incident/index.html.twig', [
            'openIncidents' => $openIncidents,
            'allIncidents' => $allIncidents,
            'categoryStats' => $categoryStats,
            'severityStats' => $severityStats,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
            'detailedStats' => $detailedStats,
        ]);
    }

    #[Route('/new', name: 'app_incident_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            throw $this->createAccessDeniedException('No tenant context available');
        }

        $incident = new Incident();
        $incident->setTenant($tenant);
        $incident->setIncidentNumber($this->incidentRepository->getNextIncidentNumber($tenant));

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

    /**
     * GDPR Breach Wizard - Calculate risk assessment
     *
     * JSON API endpoint for GDPR wizard to calculate breach risk
     * based on data types and affected count.
     */
    #[Route('/gdpr-wizard-result', name: 'app_incident_gdpr_wizard_result', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function gdprWizardResult(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['dataTypes']) || !isset($data['scale'])) {
            return $this->json(['error' => 'Missing required parameters'], 400);
        }

        $assessment = $this->gdprAssessmentService->assessBreachRisk(
            $data['dataTypes'],
            $data['scale']
        );

        return $this->json($assessment);
    }

    #[Route('/bulk-delete', name: 'app_incident_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function bulkDelete(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $incident = $this->incidentRepository->find($id);

                if (!$incident) {
                    $errors[] = "Incident ID $id not found";
                    continue;
                }

                // Security check: only allow deletion of own tenant's incidents
                if ($tenant && $incident->getTenant() !== $tenant) {
                    $errors[] = "Incident ID $id does not belong to your organization";
                    continue;
                }

                $this->entityManager->remove($incident);
                $deleted++;
            } catch (\Exception $e) {
                $errors[] = "Error deleting incident ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        if (!empty($errors)) {
            return $this->json([
                'success' => $deleted > 0,
                'deleted' => $deleted,
                'errors' => $errors
            ], $deleted > 0 ? 200 : 400);
        }

        return $this->json([
            'success' => true,
            'deleted' => $deleted,
            'message' => "$deleted incidents deleted successfully"
        ]);
    }

    #[Route('/{id}', name: 'app_incident_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(Incident $incident): Response
    {
        // Get audit log history for this incident (last 10 entries)
        $auditLogs = $this->auditLogRepository->findByEntity('Incident', $incident->getId());
        $recentAuditLogs = array_slice($auditLogs, 0, 10);

        // Get workflow status
        $workflowStatus = $this->escalationService->getEscalationStatus($incident);

        return $this->render('incident/show.html.twig', [
            'incident' => $incident,
            'auditLogs' => $recentAuditLogs,
            'totalAuditLogs' => count($auditLogs),
            'workflowStatus' => $workflowStatus,
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
     *
     * Note: Only available when NIS2 framework is installed and active.
     */
    #[Route('/{id}/nis2-report.pdf', name: 'app_incident_nis2_report', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function downloadNis2Report(Request $request, Incident $incident): Response
    {
        // Check if NIS2 framework exists and is active
        $nis2Framework = $this->frameworkRepository->findOneBy(['code' => 'NIS2']);

        if (!$nis2Framework || !$nis2Framework->isActive()) {
            $this->addFlash('warning', $this->translator->trans(
                'nis2.report_not_available',
                [],
                'messages'
            ) ?: 'NIS2 reporting is not available. The NIS2 framework must be installed and active.');
            return $this->redirectToRoute('app_incident_show', ['id' => $incident->getId()]);
        }

        // Verify that the incident requires NIS2 reporting
        if (!$incident->requiresNis2Reporting()) {
            $this->addFlash('warning', $this->translator->trans(
                'nis2.incident_not_reportable',
                [],
                'messages'
            ) ?: 'This incident does not require NIS2 reporting (severity must be high/critical or have cross-border impact).');
            return $this->redirectToRoute('app_incident_show', ['id' => $incident->getId()]);
        }

        // Close session to prevent blocking other requests during PDF generation
        $request->getSession()->save();

        // Generate filename with incident number and timestamp
        $filename = sprintf(
            'NIS2-Report-%s-%s.pdf',
            $incident->getIncidentNumber() ?? $incident->getId(),
            date('Ymd-His')
        );

        // Generate version from last update date (Format: Year.Month.Day)
        $lastUpdate = $incident->getUpdatedAt() ?? $incident->getCreatedAt() ?? new \DateTime();
        $version = $lastUpdate->format('Y.m.d');

        // Generate PDF
        $pdf = $this->pdfService->generatePdf(
            'incident/nis2_report_pdf.html.twig',
            [
                'incident' => $incident,
                'version' => $version,
            ],
            ['orientation' => 'portrait', 'paper' => 'A4']
        );

        // Return PDF as download
        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Content-Length' => strlen($pdf),
        ]);
    }

    /**
     * Calculate detailed statistics showing breakdown by origin
     */
    private function calculateDetailedStats(array $items, $currentTenant): array
    {
        $ownCount = 0;
        $inheritedCount = 0;
        $subsidiariesCount = 0;

        // Get ancestors and subsidiaries for comparison
        $ancestors = $currentTenant->getAllAncestors();
        $ancestorIds = array_map(fn($t) => $t->getId(), $ancestors);

        $subsidiaries = $currentTenant->getAllSubsidiaries();
        $subsidiaryIds = array_map(fn($t) => $t->getId(), $subsidiaries);

        foreach ($items as $item) {
            $itemTenant = $item->getTenant();
            if (!$itemTenant) {
                continue;
            }

            $itemTenantId = $itemTenant->getId();
            $currentTenantId = $currentTenant->getId();

            if ($itemTenantId === $currentTenantId) {
                $ownCount++;
            } elseif (in_array($itemTenantId, $ancestorIds)) {
                $inheritedCount++;
            } elseif (in_array($itemTenantId, $subsidiaryIds)) {
                $subsidiariesCount++;
            }
        }

        return [
            'own' => $ownCount,
            'inherited' => $inheritedCount,
            'subsidiaries' => $subsidiariesCount,
            'total' => $ownCount + $inheritedCount + $subsidiariesCount
        ];
    }

    // CRITICAL-05: BCM Integration Actions

    /**
     * Display BCM impact analysis for an incident
     */
    #[Route('/{id}/bcm-impact', name: 'app_incident_bcm_impact', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function bcmImpact(Incident $incident): Response
    {
        $analysis = $this->bcmImpactService->analyzeBusinessImpact($incident);

        return $this->render('incident/bcm_impact.html.twig', [
            'incident' => $incident,
            'analysis' => $analysis,
        ]);
    }

    /**
     * JSON API endpoint for BCM impact analysis
     */
    #[Route('/{id}/bcm-impact/api', name: 'app_incident_bcm_impact_api', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function bcmImpactApi(Incident $incident, Request $request): Response
    {
        $downtimeHours = $request->query->get('downtime_hours');

        $analysis = $this->bcmImpactService->analyzeBusinessImpact(
            $incident,
            $downtimeHours ? (int) $downtimeHours : null
        );

        return $this->json($analysis);
    }

    /**
     * Auto-detect affected business processes via assets
     */
    #[Route('/{id}/auto-detect-processes', name: 'app_incident_auto_detect_processes', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function autoDetectProcesses(Incident $incident): Response
    {
        $detectedProcesses = $this->bcmImpactService->identifyAffectedProcesses($incident);

        // Link detected processes to incident
        $added = 0;
        foreach ($detectedProcesses as $process) {
            if (!$incident->getAffectedBusinessProcesses()->contains($process)) {
                $incident->addAffectedBusinessProcess($process);
                $added++;
            }
        }

        if ($added > 0) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans(
                'incident.bcm.auto_detect_success',
                ['%count%' => $added],
                'messages'
            ));
        } else {
            $this->addFlash('info', $this->translator->trans(
                'incident.bcm.no_processes_detected',
                [],
                'messages'
            ));
        }

        return $this->redirectToRoute('app_incident_show', ['id' => $incident->getId()]);
    }

    /**
     * Generate BCM impact report (PDF)
     */
    #[Route('/{id}/bcm-impact/report', name: 'app_incident_bcm_impact_report', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function bcmImpactReport(Incident $incident): Response
    {
        $reportData = $this->bcmImpactService->generateImpactReport($incident);

        $pdf = $this->pdfService->generatePdf('incident/bcm_impact_report_pdf.html.twig', $reportData);

        $filename = sprintf('BCM_Impact_Analysis_%s_%s.pdf',
            $incident->getIncidentNumber(),
            date('Y-m-d')
        );

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Content-Length' => strlen($pdf),
        ]);
    }

    /**
     * AJAX Endpoint for Escalation Preview
     *
     * Shows users what will happen BEFORE they create/update an incident.
     * Returns preview information without triggering actual workflows.
     */
    #[Route('/escalation-preview', name: 'app_incident_escalation_preview', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function escalationPreview(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);

        // Validate input
        if (!isset($data['severity'])) {
            return $this->json(['error' => 'Missing severity parameter'], 400);
        }

        $severity = $data['severity'];
        $dataBreachOccurred = $data['dataBreachOccurred'] ?? false;

        // Validate severity value
        $validSeverities = ['low', 'medium', 'high', 'critical'];
        if (!in_array($severity, $validSeverities)) {
            return $this->json(['error' => 'Invalid severity value'], 400);
        }

        // Create temporary incident object for preview
        $incident = new Incident();
        $incident->setSeverity($severity);
        $incident->setDataBreachOccurred((bool) $dataBreachOccurred);

        // Get preview from escalation service
        $preview = $this->escalationService->previewEscalation($incident);

        // Format response for JSON
        $response = [
            'will_escalate' => $preview['will_escalate'],
            'escalation_level' => $preview['escalation_level'],
            'workflow_name' => $preview['workflow_name'],
            'notified_roles' => $preview['notified_roles'],
            'notified_users' => array_map(function($user) {
                return [
                    'id' => $user->getId(),
                    'name' => $user->getFirstName() . ' ' . $user->getLastName(),
                    'email' => $user->getEmail(),
                ];
            }, $preview['notified_users']),
            'sla_hours' => $preview['sla_hours'],
            'sla_description' => $preview['sla_description'],
            'is_gdpr_breach' => $preview['is_gdpr_breach'],
            'gdpr_deadline' => $preview['gdpr_deadline'] ? $preview['gdpr_deadline']->format('Y-m-d H:i:s') : null,
            'requires_approval' => $preview['requires_approval'],
            'approval_steps' => $preview['approval_steps'],
            'estimated_completion_time' => $preview['estimated_completion_time'],
        ];

        return $this->json($response);
    }
}
