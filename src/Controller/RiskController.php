<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\Voter\AsyncJobVoter;
use App\Service\BulkDeleteService;
use App\Entity\User;
use App\Enum\TreatmentStrategy;
use Symfony\Component\Security\Core\User\UserInterface;
use Traversable;
use DomainException;
use App\Controller\Trait\InPageFormTrait;
use App\Controller\Trait\LocalizedFlashTrait;
use App\Controller\Trait\TenantOriginStatsTrait;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\Vulnerability;
use App\Form\RiskQuickType;
use App\Form\RiskType;
use App\Repository\AuditLogRepository;
use App\Repository\CommentRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\VulnerabilityRepository;
use App\Service\InverseCoverageService;
use App\Service\RiskMatrixService;
use App\Service\RiskService;
use App\Service\RiskAcceptanceWorkflowService;
use App\Service\Risk\RiskRegisterExporter;
use App\Service\RoleDashboardService;
use App\Service\TagFilterService;
use App\Service\Risk\RiskIncidentLinkService;
use App\Repository\RiskIncidentLinkRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\Clone\RiskCloner;
use App\Controller\Trait\BulkActionTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class RiskController extends AbstractController
{
    use LocalizedFlashTrait;
    use BulkActionTrait;
    use InPageFormTrait;
    use TenantOriginStatsTrait;

    public function __construct(
        private readonly RiskRepository $riskRepository,
        private readonly RiskService $riskService,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RiskMatrixService $riskMatrixService,
        private readonly RiskAcceptanceWorkflowService $riskAcceptanceWorkflowService,
        private readonly TranslatorInterface $translator,
        private readonly RiskRegisterExporter $riskRegisterExporter,
        private readonly Security $security,
        private readonly TagFilterService $tagFilterService,
        private readonly VulnerabilityRepository $vulnerabilityRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly RiskTreatmentPlanRepository $riskTreatmentPlanRepository,
        private readonly \App\Service\Job\JobTenantScope $jobTenantScope,
        private readonly ?InverseCoverageService $inverseCoverageService = null,
        private readonly ?CommentRepository $commentRepository = null,
        private readonly ?RiskIncidentLinkService $riskIncidentLinkService = null,
        private readonly ?RiskIncidentLinkRepository $riskIncidentLinkRepository = null,
        private readonly ?RoleDashboardService $roleDashboardService = null,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?UserRepository $userRepository = null,
        private readonly ?RiskCloner $riskCloner = null,
    ) {}

    /**
     * @return Risk[] the caller's organisation's risks; every risk only for a SUPER_ADMIN without one
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException for anyone else without one
     */
    private function exportableRisks(): array
    {
        $tenant = $this->jobTenantScope->currentUserScope();

        return $tenant ? $this->riskService->getRisksForTenant($tenant) : $this->riskRepository->findAll();
    }

    protected function getFlashDomain(): string
    {
        return 'risk';
    }

    protected function getTranslator(): TranslatorInterface
    {
        return $this->translator;
    }
    #[Route('/risk', name: 'app_risk_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get filter parameters
        $q = trim((string) $request->query->get('q', ''));
        $level = $request->query->get('level'); // critical, high, medium, low
        $status = $request->query->get('status');
        $treatment = $request->query->get('treatment');
        $owner = $request->query->get('owner');
        $view = $request->query->get('view', 'own'); // Default: own tenant's risks

        // Cross-tenant + orphan views are admin-only — silently coerce to
        // 'own' for non-admins so a hand-crafted ?view=all URL doesn't leak
        // foreign-tenant data.
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        if (in_array($view, ['orphaned', 'all'], true) && !$isAdmin) {
            $view = 'own';
        }

        // Get risks based on view filter
        if ($tenant) {
            // Determine which risks to load based on view parameter
            $risks = match ($view) {
                // Only own risks
                'own' => $this->riskRepository->findByTenant($tenant),
                // Own + from all subsidiaries (for parent companies)
                'subsidiaries' => $this->riskRepository->findByTenantIncludingSubsidiaries($tenant),
                // Tenant-less (orphan) risks — admin only
                'orphaned' => $this->riskRepository->findOrphaned(),
                // Cross-tenant overview — admin only
                'all' => $this->riskRepository->findAllAcrossTenants(),
                // Own + inherited from parents (default behavior)
                default => $this->riskService->getRisksForTenant($tenant),
            };
            // Filter high risks from the selected risk set
            $highRisks = array_filter($risks, fn(Risk $risk): bool => $risk->getRiskScore() >= 12);
            // Calculate detailed statistics based on origin
            $detailedStats = $this->calculateDetailedStats($risks, $tenant);
            $inheritanceInfo = $this->riskService->getRiskInheritanceInfo($tenant);
            $inheritanceInfo['hasSubsidiaries'] = $tenant->getSubsidiaries()->count() > 0;
            $inheritanceInfo['currentView'] = $view;
            $inheritanceInfo['isAdmin'] = $isAdmin;
        } else {
            // No organisation: only a SUPER_ADMIN gets the instance-wide register
            $risks = $this->isGranted('ROLE_SUPER_ADMIN') ? $this->riskRepository->findAll() : [];
            $highRisks = [];
            $detailedStats = ['own' => count($risks), 'inherited' => 0, 'subsidiaries' => 0, 'total' => count($risks)];
            $inheritanceInfo = [
                'hasParent' => false,
                'canInherit' => false,
                'governanceModel' => null,
                'hasSubsidiaries' => false,
                'currentView' => 'own',
                'isAdmin' => $isAdmin,
            ];
        }

        $risks = $this->riskRegisterExporter->filter($risks, $level, $status, $treatment, $owner);

        // Filter to overdue reviews only (review date in the past or null)
        $reviewOverdue = $request->query->get('review_overdue');
        if ($reviewOverdue === '1') {
            $now = new \DateTime();
            $risks = array_filter($risks, function (Risk $risk) use ($now): bool {
                $reviewDate = $risk->getReviewDate();
                return $reviewDate === null || $reviewDate < $now;
            });
        }

        // Free-text search across title, description, threat (q=...) — URL-persisted (UXC-11)
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $risks = array_filter($risks, function (Risk $risk) use ($needle): bool {
                $haystack = mb_strtolower(
                    ($risk->getTitle() ?? '')
                    . ' ' . ($risk->getDescription() ?? '')
                    . ' ' . ($risk->getThreat() ?? '')
                    . ' ' . (string) $risk->getId()
                );
                return str_contains($haystack, $needle);
            });
        }

        // Alva-hint deep-link: pre-filter to EXACTLY the set the matching hint
        // counts (shared repository finders).
        $focus = $request->query->get('focus');
        if ($focus === 'no_owner') {
            $risks = array_filter($risks, static fn(Risk $risk): bool => $risk->getRiskOwner() === null);
        } elseif ($focus === 'incident_linked' && $tenant && $this->riskIncidentLinkRepository !== null) {
            $linkedRiskIds = [];
            foreach ($this->riskIncidentLinkRepository->findStaleLinksToOpenIncidents($tenant, 30) as $link) {
                $rid = $link->getRisk()?->getId();
                if ($rid !== null) {
                    $linkedRiskIds[$rid] = $rid;
                }
            }
            $risks = array_filter($risks, static fn(Risk $risk): bool => isset($linkedRiskIds[$risk->getId()]));
        }
        $risks = array_values($risks);

        // WS-5: framework-tag filter via ?tag=NIS2
        $tagFilter = $request->query->get('tag');
        if (is_string($tagFilter) && $tagFilter !== '') {
            $risks = $this->tagFilterService->filterByTagName($risks, Risk::class, $tagFilter);
        }

        $treatmentStats = $tenant ? $this->riskRepository->countByTreatmentStrategy($tenant) : [];

        return $this->render('risk/index.html.twig', [
            'risks' => $risks,
            'highRisks' => $highRisks,
            'treatmentStats' => $treatmentStats,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
            'detailedStats' => $detailedStats,
            'focus' => $focus,
        ]);
    }
    #[Route('/risk/export', name: 'app_risk_export', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function export(Request $request): Response
    {
        $csvContent = $this->riskRegisterExporter->buildCsv($this->filteredRegister($request));

        $response = new Response($csvContent);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="' . sprintf('risk_export_%s.csv', date('Y-m-d_His')) . '"',
        );

        return $response;
    }

    /**
     * Register filtered by the list-page query filters (level / status /
     * treatment / owner) for the sync exports. Releases the session lock so
     * file generation does not block the user's other requests.
     *
     * @return list<Risk>
     */
    private function filteredRegister(Request $request): array
    {
        $risks = $this->riskRegisterExporter->filter(
            $this->exportableRisks(),
            $request->query->get('level'),
            $request->query->get('status'),
            $request->query->get('treatment'),
            $request->query->get('owner'),
        );
        $request->getSession()->save();

        return $risks;
    }

    /**
     * Async wrapper around {@see self::export()}: dispatches an
     * {@see \App\Job\ExportRisksJob} that writes the filtered register to
     * var/exports/<jobId>.csv and renders a polling progress page with a
     * Download CTA once the worker reports succeeded.
     *
     * The legacy sync GET route is kept for browser bookmarks and any
     * integration that already targets it; new UI traffic should use this
     * dispatch endpoint to avoid PHP-FPM timeout on large registers.
     *
     * Phase 2.5 of the async admin-jobs rollout.
     */
    #[Route('/risk/export/dispatch', name: 'app_risk_export_dispatch', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function exportDispatch(
        Request $request,
        \App\Service\Job\JobStatusService $jobStatusService,
        \App\Service\Job\JobDispatcher $jobDispatcher,
    ): Response {
        if (!$this->isCsrfTokenValid('risk_export_dispatch', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));
            return $this->redirectToRoute('app_risk_index');
        }

        // Tenant scope is captured here, while the request still exists; the
        // job scopes its query explicitly and fails without a scope.
        $args = $this->jobTenantScope->argsForCurrentUser() + [
            'level' => $request->request->get('level') ?? $request->query->get('level'),
            'status' => $request->request->get('status') ?? $request->query->get('status'),
            'treatment' => $request->request->get('treatment') ?? $request->query->get('treatment'),
            'owner' => $request->request->get('owner') ?? $request->query->get('owner'),
        ];

        $jobId = $jobStatusService->create('risk.export', $args + [
            '_label' => $this->translator->trans('risk.export.progress_title', [], 'risk'),
            '_subtitle' => $this->translator->trans('risk.export.progress_subtitle', [], 'risk'),
            '_download_label' => $this->translator->trans('risk.export.download_button', [], 'risk'),
        ]);
        // Patch download URL after we know the UUID (JobStatusService::create
        // mints the UUID inside the method, so we can't reference it before).
        $jobStatusService->updatePayload($jobId, [
            '_download_url' => $this->generateUrl('app_risk_export_download', ['id' => $jobId]),
        ]);

        // PRG: 303 redirect to the shared progress page so Turbo can follow
        // the redirect instead of erroring on a 200+HTML form response.
        // JobDispatcher flushes it then runs the export in this same
        // PHP-FPM worker (or hands it to a Messenger daemon when
        // `app.async_job.runner=messenger`).
        $response = $this->redirectToRoute('admin_job_progress_page', [
            'id'     => $jobId,
            'return' => $this->generateUrl('app_risk_index'),
        ], Response::HTTP_SEE_OTHER);

        return $jobDispatcher->dispatch(
            \App\Job\ExportRisksJob::class,
            $args,
            $jobId,
            $response,
            $request->getSession(),
        );
    }

    /**
     * Streams the file produced by {@see \App\Job\ExportRisksJob} and removes
     * it from disk afterwards. The job ID UUID-v4 is the canonical filename
     * stem so we can derive the path without any user-controlled string.
     */
    #[Route('/risk/export/download/{id}', name: 'app_risk_export_download', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    #[IsGranted(AsyncJobVoter::ACCESS, subject: 'id')]
    public function exportDownload(
        string $id,
        \App\Service\Job\JobStatusService $jobStatusService,
        \Symfony\Component\HttpKernel\KernelInterface $kernel,
    ): Response {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
            throw $this->createNotFoundException('Invalid export ID.');
        }
        if (!$jobStatusService->exists($id)) {
            throw $this->createNotFoundException(
                $this->translator->trans('risk.export.file_not_found', [], 'risk'),
            );
        }
        $record = $jobStatusService->read($id);
        if (($record['status'] ?? '') !== 'succeeded') {
            throw $this->createNotFoundException(
                $this->translator->trans('risk.export.file_not_found', [], 'risk'),
            );
        }

        $path = $kernel->getProjectDir() . '/var/exports/' . $id . '.csv';
        if (!is_file($path)) {
            throw $this->createNotFoundException(
                $this->translator->trans('risk.export.file_not_found', [], 'risk'),
            );
        }

        $filename = sprintf('risk_export_%s.csv', date('Y-m-d_His'));

        $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->setContentDisposition(
            \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename,
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }

    #[Route('/risk/export/excel', name: 'app_risk_export_excel', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function exportExcel(Request $request): Response
    {
        $content = $this->riskRegisterExporter->buildExcel($this->filteredRegister($request));
        $filename = sprintf('risk_management_report_%s.xlsx', date('Y-m-d_His'));

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Content-Length', (string) strlen($content));

        return $response;
    }
    #[Route('/risk/export/pdf', name: 'app_risk_export_pdf', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function exportPdf(Request $request): Response
    {
        $filterParts = [];
        foreach (['level' => 'Level', 'status' => 'Status', 'treatment' => 'Behandlung', 'owner' => 'Owner'] as $param => $label) {
            $value = $request->query->get($param);
            if ($value) {
                $filterParts[] = $label . ': ' . $value;
            }
        }
        $pdfContent = $this->riskRegisterExporter->buildPdf(
            $this->filteredRegister($request),
            $filterParts === [] ? null : implode(', ', $filterParts),
        );
        $filename = sprintf('risk_management_report_%s.pdf', date('Y-m-d_His'));

        $response = new Response($pdfContent);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Content-Length', (string) strlen($pdfContent));

        return $response;
    }
    #[Route('/risk/new', name: 'app_risk_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $risk = new Risk();

        // Set tenant from current user
        $user = $this->security->getUser();
        $tenant = null;
        if ($user instanceof UserInterface && $user->getTenant()) {
            $tenant = $user->getTenant();
            $risk->setTenant($tenant);
        }

        // Pre-fill from Vulnerability (Junior-Finding #8 / Data-Reuse: one-click derivation)
        $fromVulnerabilityId = $request->query->get('fromVulnerability');
        if ($fromVulnerabilityId !== null && $fromVulnerabilityId !== '') {
            $vulnerability = $this->vulnerabilityRepository->find($fromVulnerabilityId);
            // Multi-tenancy: only allow prefill within the same tenant
            if ($vulnerability instanceof Vulnerability
                && $tenant !== null
                && $vulnerability->getTenant() === $tenant
            ) {
                $risk->setTitle($this->translator->trans(
                    'risk.prefill.title_from_vulnerability',
                    ['%title%' => (string) $vulnerability->getTitle()],
                    'risk'
                ));
                $risk->setDescription(
                    (string) $vulnerability->getDescription()
                    . "\n\n"
                    . $this->translator->trans('risk.prefill.note_from_vulnerability',
                        ['%id%' => (string) $vulnerability->getId()],
                        'risk'
                    )
                );
                $risk->setThreat($this->translator->trans(
                    'risk.prefill.threat_from_vulnerability',
                    ['%title%' => (string) $vulnerability->getTitle()],
                    'risk'
                ));
                $risk->setCategory('security');
                $risk->setLinkedVulnerability($vulnerability);
            }
        }

        // Pre-fill from Incident (Junior-Finding #8 / Data-Reuse)
        $fromIncidentId = $request->query->get('fromIncident');
        if ($fromIncidentId !== null && $fromIncidentId !== '') {
            $incident = $this->incidentRepository->find($fromIncidentId);
            if ($incident instanceof Incident
                && $tenant !== null
                && $incident->getTenant() === $tenant
            ) {
                $risk->setTitle($this->translator->trans(
                    'risk.prefill.title_from_incident',
                    ['%title%' => (string) $incident->getTitle()],
                    'risk'
                ));
                $risk->setDescription(
                    (string) $incident->getDescription()
                    . "\n\n"
                    . $this->translator->trans('risk.prefill.note_from_incident',
                        ['%id%' => (string) $incident->getId()],
                        'risk'
                    )
                );
                $risk->setCategory('operational');
            }
        }

        $form = $this->createForm(RiskType::class, $risk);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($risk);
            $this->entityManager->flush();

            // Auto-progression fires via FieldCompletionAutoTransition Doctrine listener
            // (postUpdate event) — no explicit service call required (canonical since Y.1).

            $this->flashSuccess('risk.success.created');

            if ($this->isTurboFrameRequest($request)) {
                return $this->riskStreamSave($risk, isNew: true);
            }
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('risk/_form_modal.html.twig', [
                'risk' => $risk,
                'form' => $form,
            ], new Response(status: $status));
        }

        return $this->render('risk/new.html.twig', [
            'risk' => $risk,
            'form' => $form,
        ], new Response(status: $status));
    }
    #[Route('/risk/new/quick', name: 'app_risk_new_quick', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function newQuick(Request $request): Response
    {
        $risk = new Risk();

        // Set tenant from current user
        $user = $this->security->getUser();
        if ($user instanceof UserInterface && $user->getTenant()) {
            $risk->setTenant($user->getTenant());
        }

        $form = $this->createForm(RiskQuickType::class, $risk);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($risk);
            $this->entityManager->flush();

            // Auto-progression fires via FieldCompletionAutoTransition Doctrine listener
            // (postUpdate event) — no explicit service call required (canonical since Y.1).

            $this->flashSuccess('risk.success.created');
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('risk/new_quick.html.twig', [
            'risk' => $risk,
            'form' => $form,
        ], new Response(status: $status));
    }
    #[Route('/risk/matrix', name: 'app_risk_matrix', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function matrix(): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // C-01 fix: tenant-scoped query only. Previously this route fell back
        // to $riskRepository->findAll() when $tenant was null, leaking
        // cross-tenant data. With #[IsGranted('ROLE_USER')] above the user is
        // always authenticated; if they have no tenant assigned, return an
        // empty matrix rather than every tenant's risks.
        $risks = $tenant ? $this->riskService->getRisksForTenant($tenant) : [];

        $matrixData = $this->riskMatrixService->generateMatrix();
        $statistics = $this->riskMatrixService->getRiskStatistics();
        $risksByLevel = $this->riskMatrixService->getRisksByLevel();

        // Serialize risks for JavaScript consumption
        $serializedRisks = array_map(fn(Risk $risk): array => [
            'id' => $risk->getId(),
            'title' => $risk->getTitle(),
            'probability' => $risk->getProbability() ?? 1,
            'impact' => $risk->getImpact() ?? 1,
        ], $risks instanceof Traversable ? iterator_to_array($risks) : $risks);

        return $this->render('risk/matrix.html.twig', [
            'risks' => $serializedRisks,
            'matrixData' => $matrixData,
            'statistics' => $statistics,
            'risksByLevel' => $risksByLevel,
        ]);
    }
    /**
     * Dependency-check endpoint for the Aurora bulk-delete-confirmation modal.
     * Warns if a Risk has linked Treatment Plans or Incidents before deletion.
     */
    // @csrf-exempt: read-only dependency check for the bulk-delete confirmation modal
    #[Route('/risk/bulk-delete-check', name: 'app_risk_bulk_delete_check', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDeleteCheck(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $ids = array_filter((array) ($data['ids'] ?? []), 'is_int');
        if ($ids === []) {
            return new JsonResponse(['dependencies' => [], 'checked_count' => 0]);
        }

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();
        $risks = $this->riskRepository->findBy(['id' => $ids, 'tenant' => $tenant]);

        return $this->checkBulkDependencies($risks, 'getTitle', [
            fn (Risk $risk): ?array => count($this->riskTreatmentPlanRepository->findByRisk($risk)) > 0
                ? ['message' => $this->translator->trans('bulk_delete.dependency.treatment_plans', ['%count%' => count($this->riskTreatmentPlanRepository->findByRisk($risk))], 'bulk_delete'), 'icon' => 'nav-file-check']
                : null,
            fn (Risk $risk): ?array => ($c = $risk->getIncidents()->count()) > 0
                ? ['message' => $this->translator->trans('bulk_delete.dependency.incidents', ['%count%' => $c], 'bulk_delete'), 'icon' => 'exclamation-triangle']
                : null,
            fn (Risk $risk): ?array => ($c = $risk->getControls()->count()) > 0
                ? ['message' => $this->translator->trans('bulk_delete.dependency.controls', ['%count%' => $c], 'bulk_delete'), 'icon' => 'control']
                : null,
        ]);
    }

    #[Route('/risk/bulk-delete', name: 'app_risk_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function bulkDelete(Request $request, BulkDeleteService $bulkDeleteService): JsonResponse
    {
        // Tenant equality (enforced by BulkDeleteService) already excludes
        // risks inherited from a parent organisation.
        return $this->handleBulkDelete(
            $request,
            $bulkDeleteService,
            'Risk',
            $this->security->getUser(),
            fn (int $id): ?object => $this->riskRepository->find($id),
            voterAttribute: 'delete',
        );
    }

    #[Route('/risk/{id}', name: 'app_risk_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(Request $request, Risk $risk): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // In-modal → condensed read-only detail; direct URL → full page (fallback).
        // canEdit gating mirrors the full page: inherited risks are not editable.
        if ($this->isTurboFrameRequest($request)) {
            $canEdit = $tenant ? $this->riskService->canEditRisk($risk, $tenant) : true;

            return $this->render('risk/_detail_modal.html.twig', [
                'risk' => $risk,
                'canEdit' => $canEdit,
            ]);
        }

        // Get audit log history for this risk (last 10 entries)
        $auditLogs = $this->auditLogRepository->findByEntity('Risk', $risk->getId());
        $recentAuditLogs = array_slice($auditLogs, 0, 10);

        // Check if risk is inherited (only if user has tenant)
        if ($tenant) {
            $isInherited = $this->riskService->isInheritedRisk($risk, $tenant);
            $canEdit = $this->riskService->canEditRisk($risk, $tenant);
        } else {
            // Users without tenant (e.g., super admins) can edit everything
            $isInherited = false;
            $canEdit = true;
        }

        // Data-Reuse: Build link matrix data
        // Risks have a single linkedVulnerability (ManyToOne). Wrap it in an
        // array so the matrix component can render a uniform list.
        $linkedVulnerabilities = [];
        if ($risk->getLinkedVulnerability() !== null) {
            $linkedVulnerabilities[] = $risk->getLinkedVulnerability();
        }

        // Risk Treatment Plans linked to this risk (ISO 27001 Cl.6.1.3)
        $treatmentPlans = $this->riskTreatmentPlanRepository->findByRisk($risk);

        // V3 B6 / EF-4: Inverse-Coverage Impact-Analyse
        $impactCoverage = $this->inverseCoverageService?->forRisk($risk) ?? ['total' => 0, 'frameworks' => []];

        // V3 W2-H3: Comment-Thread (C7) — load thread for this Risk.
        $comments = [];
        if ($this->commentRepository !== null && $tenant !== null && $risk->getId() !== null) {
            $comments = $this->commentRepository->findThread($tenant, 'Risk', $risk->getId());
        }

        // F16: structured incident cross-links
        $riskIncidentLinks = $this->riskIncidentLinkRepository?->findByRisk($risk) ?? [];

        // Z.0 — Workflow transparency: pre-compute pending banner for this entity
        $workflowInfo = $this->roleDashboardService?->getWorkflowInfoForEntity('Risk', $risk->getId()) ?? [];

        return $this->render('risk/show.html.twig', [
            'risk' => $risk,
            'auditLogs' => $recentAuditLogs,
            'totalAuditLogs' => count($auditLogs),
            'isInherited' => $isInherited,
            'canEdit' => $canEdit,
            'currentTenant' => $tenant,
            // Data-Reuse: one-click link matrix
            'linkedVulnerabilities' => $linkedVulnerabilities,
            'linkedIncidents' => $risk->getIncidents(),
            // V3 B3: Treatment plans collection on Risk show
            'treatmentPlans' => $treatmentPlans,
            // V3 B6: Impact analysis (which frameworks break if this risk changes?)
            'impact_coverage' => $impactCoverage,
            // V3 W2-H3: Comments thread + form action
            'comments' => $comments,
            // F16: structured incident cross-links
            'riskIncidentLinks' => $riskIncidentLinks,
            // Z.0: lifecycle pending banner data (no N+1)
            'workflow_info' => $workflowInfo,
        ]);
    }
    /**
     * F16: Link an Incident to this Risk.
     */
    #[Route('/risk/{id}/link-incident', name: 'app_risk_link_incident', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function linkIncident(Request $request, Risk $risk): Response
    {
        if (!$this->isCsrfTokenValid('link_incident_' . $risk->getId(), $request->request->get('_token'))) {
            $this->flashError('risk.link_incident.csrf_invalid');
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        $incidentId = (int) $request->request->get('incident_id');
        $linkType   = (string) $request->request->get('link_type', 'related');
        $notes      = $request->request->get('notes');

        $incident = $this->incidentRepository->find($incidentId);
        if ($incident === null) {
            $this->flashError('risk.link_incident.incident_not_found');
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        /** @var \App\Entity\User|null $currentUser */
        $currentUser = $this->security->getUser();
        $this->riskIncidentLinkService?->link(
            $risk,
            $incident,
            $linkType,
            $currentUser instanceof \App\Entity\User ? $currentUser : null,
            is_string($notes) ? $notes : null,
        );

        $this->flashSuccess('risk.link_incident.linked');
        return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
    }

    /**
     * F16: Unlink an Incident from this Risk by link ID.
     */
    #[Route('/risk/{id}/unlink-incident/{linkId}', name: 'app_risk_unlink_incident', requirements: ['id' => '\d+', 'linkId' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function unlinkIncident(Request $request, Risk $risk, int $linkId): Response
    {
        if (!$this->isCsrfTokenValid('unlink_incident_' . $linkId, $request->request->get('_token'))) {
            $this->flashError('risk.link_incident.csrf_invalid');
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        $link = $this->riskIncidentLinkRepository?->find($linkId);
        if ($link === null || $link->getRisk()?->getId() !== $risk->getId()) {
            $this->flashError('risk.link_incident.link_not_found');
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        $incident = $link->getIncident();
        if ($incident !== null) {
            $this->riskIncidentLinkService?->unlink($risk, $incident);
        }

        $this->flashSuccess('risk.link_incident.unlinked');
        return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
    }

    /**
     * Clone a Risk (C4-C1 — Klon-Funktionen). Open to any ROLE_USER so
     * every ISMS-user can template their own risks. The clone preserves
     * the assessment scaffolding and resets lifecycle state.
     */
    #[Route('/risk/{id}/clone', name: 'app_risk_clone', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function clone(Request $request, Risk $risk): Response
    {
        if (!$this->isCsrfTokenValid('clone_risk_' . $risk->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if ($this->riskCloner === null) {
            throw $this->createNotFoundException('Risk clone service is not available.');
        }

        $clone = $this->riskCloner->clone(
            $risk,
            null,
            trim((string) $request->request->get('title_override', '')) ?: null,
        );
        $this->entityManager->flush();

        $this->auditLogger?->logCreate(
            entityType: 'Risk',
            entityId: $clone->getId(),
            newValues: ['cloned_from_id' => $risk->getId(), 'title' => $clone->getTitle()],
            description: 'Cloned from Risk #' . $risk->getId(),
        );

        $this->addFlash('success', $this->translator->trans('risk.clone.success', [], 'risk'));
        return $this->redirectToRoute('app_risk_edit', ['id' => $clone->getId()]);
    }

    #[Route('/risk/{id}/edit', name: 'app_risk_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Risk $risk): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Check if risk can be edited (not inherited) - only if user has tenant
        if ($tenant && !$this->riskService->canEditRisk($risk, $tenant)) {
            $this->addFlash('error', $this->translator->trans('corporate.inheritance.cannot_edit_inherited', [], 'messages'));
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        $form = $this->createForm(RiskType::class, $risk);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            // Auto-progression fires via FieldCompletionAutoTransition Doctrine listener
            // (postUpdate event) — no explicit service call required (canonical since Y.1).

            $this->flashSuccess('risk.success.updated');

            if ($this->isTurboFrameRequest($request)) {
                return $this->riskStreamSave($risk, isNew: false);
            }
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('risk/_form_modal.html.twig', [
                'risk' => $risk,
                'form' => $form,
            ], new Response(status: $status));
        }

        return $this->render('risk/edit.html.twig', [
            'risk' => $risk,
            'form' => $form,
        ], new Response(status: $status));
    }

    /** Turbo Stream after a successful in-modal Risk save (row replace/append). */
    private function riskStreamSave(Risk $risk, bool $isNew): Response
    {
        return $this->render('risk/_stream_save.html.twig', [
            'risk' => $risk,
            'is_new' => $isNew,
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    #[Route('/risk/{id}/delete', name: 'app_risk_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Risk $risk): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Check if risk can be deleted (not inherited) - only if user has tenant
        if ($tenant && !$this->riskService->canEditRisk($risk, $tenant)) {
            $this->addFlash('error', $this->translator->trans('corporate.inheritance.cannot_edit_inherited', [], 'messages'));
            return $this->redirectToRoute('app_risk_index');
        }

        if ($this->isCsrfTokenValid('delete'.$risk->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($risk);
            $this->entityManager->flush();

            $this->flashSuccess('risk.success.deleted');
        }

        return $this->redirectToRoute('app_risk_index');
    }
    /**
     * Request formal risk acceptance (Priority 2.1 - Risk Acceptance Workflow)
     * ISO 27005:2022 Section 8.4.4
     */
    #[Route('/risk/{id}/request-acceptance', name: 'app_risk_request_acceptance', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function requestAcceptance(Request $request, Risk $risk): Response
    {
        $user = $this->security->getUser();

        // Check if risk has "accept" treatment strategy
        if ($risk->getTreatmentStrategy() !== TreatmentStrategy::Accept) {
            $this->flashError('risk.acceptance.error.wrong_strategy');
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        // Check if already formally accepted
        if ($risk->isFormallyAccepted()) {
            $this->flashWarning('risk.acceptance.error.already_accepted');
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        // Handle form submission
        if ($request->isMethod('POST')) {
            // CSRF token validation
            if (!$this->isCsrfTokenValid('request-acceptance'.$risk->getId(), $request->request->get('_token'))) {
                $this->addFlash('error', $this->translator->trans('security.csrf_token_invalid', [], 'messages'));
                return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
            }

            $justification = $request->request->get('justification');

            if (empty($justification)) {
                $this->flashError('risk.acceptance.error.justification_required');
            } else {
                try {
                    $result = $this->riskAcceptanceWorkflowService->requestAcceptance(
                        $risk,
                        $user,
                        $justification
                    );

                    if ($result['status'] === 'accepted') {
                        // Automatic acceptance
                        $this->flashSuccess('risk.acceptance.success.auto_accepted');
                    } else {
                        // Pending approval
                        $this->flashSuccess(
                            'risk.acceptance.success.approval_requested',
                            ['%approver%' => $result['approver'], '%level%' => $result['approval_level']]
                        );
                    }

                    return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
                } catch (DomainException $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }
        }

        // Get approval thresholds for display
        $thresholds = $this->riskAcceptanceWorkflowService->getApprovalThresholds($risk);
        $requiredLevel = $this->riskAcceptanceWorkflowService->determineApprovalLevel($risk);

        return $this->render('risk/request_acceptance.html.twig', [
            'risk' => $risk,
            'thresholds' => $thresholds,
            'required_level' => $requiredLevel,
        ]);
    }
    /**
     * Approve risk acceptance (Priority 2.1 - Risk Acceptance Workflow)
     */
    #[Route('/risk/{id}/approve-acceptance', name: 'app_risk_approve_acceptance', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function approveAcceptance(Request $request, Risk $risk): Response
    {
        $user = $this->security->getUser();

        if (!$this->isCsrfTokenValid('approve-acceptance'.$risk->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('security.csrf_token_invalid', [], 'messages'));
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        $comments = $request->request->get('comments', '');

        try {
            $result = $this->riskAcceptanceWorkflowService->approveAcceptance($risk, $user, $comments);
            $this->flashSuccess('risk.acceptance.success.approved');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
    }
    /**
     * Reject risk acceptance (Priority 2.1 - Risk Acceptance Workflow)
     */
    #[Route('/risk/{id}/reject-acceptance', name: 'app_risk_reject_acceptance', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function rejectAcceptance(Request $request, Risk $risk): Response
    {
        $user = $this->security->getUser();

        if (!$this->isCsrfTokenValid('reject-acceptance'.$risk->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('security.csrf_token_invalid', [], 'messages'));
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        $reason = $request->request->get('reason');

        if (empty($reason)) {
            $this->flashError('risk.acceptance.error.reason_required');
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        try {
            $result = $this->riskAcceptanceWorkflowService->rejectAcceptance($risk, $user, $reason);
            $this->flashWarning('risk.acceptance.success.rejected');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
    }
    /**
     * Bulk CSV export of selected risks.
     * ISO 27001 Cl. 7.5.3 — audit-logged via BulkActionTrait.
     */
    #[Route('/risk/bulk-export', name: 'app_risk_bulk_export', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function bulkExport(Request $request): StreamedResponse|Response
    {
        $data = json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('bulk_action', (string) ($data['_token'] ?? ''))) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }
        $ids  = $data['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $user   = $this->security->getUser();
        $tenant = $user instanceof User ? $user->getTenant() : null;

        $risks = [];
        foreach ($ids as $rawId) {
            $risk = $this->riskRepository->find((int) $rawId);
            if ($risk === null) {
                continue;
            }
            if ($tenant !== null && $risk->getTenant() !== $tenant) {
                continue;
            }
            $risks[] = $risk;
        }

        if ($risks === []) {
            return $this->json(['error' => 'No exportable risks'], 404);
        }

        $headers = ['ID', 'Title', 'Category', 'Status', 'Probability', 'Impact', 'Treatment Strategy', 'Owner'];

        return $this->streamCsvExport(
            $risks,
            $headers,
            static function (Risk $r): array {
                return [
                    (string) $r->getId(),
                    (string) $r->getTitle(),
                    (string) $r->getCategory(),
                    (string) ($r->getStatus() !== null ? $r->getStatus()->value : ''),
                    (string) $r->getProbability(),
                    (string) $r->getImpact(),
                    (string) ($r->getTreatmentStrategy() !== null ? $r->getTreatmentStrategy()->value : ''),
                    (string) ($r->getRiskOwnerName() ?? ''),
                ];
            },
            'risks-export',
            'Risk',
            $this->auditLogger,
        );
    }

    /**
     * Bulk assign selected risks to a user (sets riskOwner).
     * ISO 27001 Cl. 7.5.3 — audit-logged via BulkActionTrait.
     */
    #[Route('/risk/bulk-assign', name: 'app_risk_bulk_assign', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkAssign(Request $request): Response
    {
        $data     = json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('bulk_action', (string) ($data['_token'] ?? ''))) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }
        $ids      = $data['ids'] ?? [];
        $assignId = (int) ($data['assignee_id'] ?? 0);

        if (!is_array($ids) || $ids === [] || $assignId === 0) {
            return $this->json(['error' => 'No items selected or no assignee'], 400);
        }

        $user   = $this->security->getUser();
        $tenant = $user instanceof User ? $user->getTenant() : null;

        $assignee = $this->userRepository?->find($assignId);
        if (!$assignee instanceof User) {
            return $this->json(['error' => 'Assignee not found'], 404);
        }
        if ($tenant !== null && $assignee->getTenant() !== $tenant) {
            return $this->json(['error' => 'Assignee tenant mismatch'], 403);
        }

        $risks = [];
        foreach ($ids as $rawId) {
            $risk = $this->riskRepository->find((int) $rawId);
            if ($risk === null) {
                continue;
            }
            if ($tenant !== null && $risk->getTenant() !== $tenant) {
                continue;
            }
            $risks[] = $risk;
        }

        $result = $this->applyBulkAssign(
            $risks,
            static function (Risk $r, User $u): void { $r->setRiskOwner($u); },
            $assignee,
            'Risk',
            $this->auditLogger,
        );

        if ($result['changed'] > 0) {
            $this->entityManager->flush();
        }

        return $this->json($result);
    }
}
