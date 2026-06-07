<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\CurrentUserTrait;
use App\Controller\Trait\InPageFormTrait;
use App\Controller\Trait\LocalizedFlashTrait;
use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\ProcessingActivity;
use App\Entity\Risk;
use App\Entity\TransferImpactAssessment;
use App\Enum\RiskStatus;
use App\Form\TransferImpactAssessmentType;
use App\Repository\TransferImpactAssessmentRepository;
use App\Service\AuditLogger;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

// @em-write-allowed: Lightweight TIA controller — no independent service yet; mirrors DPIAController pattern. Extract to TransferImpactAssessmentService when bulk/export ops are added.

/**
 * CRUD controller for Transfer Impact Assessments (TIA).
 *
 * Routes are nested under a ProcessingActivity for context:
 *   GET  /{locale}/processing-activity/{paId}/tia/         — list TIAs for this PA
 *   GET  /{locale}/processing-activity/{paId}/tia/new      — new TIA form
 *   POST /{locale}/processing-activity/{paId}/tia/new      — create TIA
 *   GET  /{locale}/processing-activity/{paId}/tia/{id}     — show TIA
 *   GET  /{locale}/processing-activity/{paId}/tia/{id}/edit — edit TIA form
 *   POST /{locale}/processing-activity/{paId}/tia/{id}/edit — update TIA
 *   POST /{locale}/processing-activity/{paId}/tia/{id}/assess — mark as assessed
 *   POST /{locale}/processing-activity/{paId}/tia/{id}/delete — delete TIA
 *
 * Module gate: privacy (all actions).
 * Minimum role: ROLE_USER (read/create); ROLE_MANAGER (delete).
 */
#[IsGranted('ROLE_USER')]
#[Route('/processing-activity/{paId}/tia', name: 'app_tia_', requirements: ['paId' => '\d+'])]
class TransferImpactAssessmentController extends AbstractController
{
    use CurrentUserTrait;
    use InPageFormTrait;
    use LocalizedFlashTrait;
    use ModuleGatedControllerTrait;

    protected function getFlashDomain(): string
    {
        return 'tia';
    }

    protected function getTranslator(): TranslatorInterface
    {
        return $this->translator;
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TransferImpactAssessmentRepository $tiaRepository,
        private readonly ?AuditLogger $auditLogger = null,
    ) {}

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Load and tenant-guard the parent ProcessingActivity.
     */
    private function getProcessingActivity(int $paId): ProcessingActivity
    {
        $pa = $this->entityManager->getRepository(ProcessingActivity::class)->find($paId);

        if ($pa === null) {
            throw $this->createNotFoundException('Processing activity not found.');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $pa->getTenant() !== $tenant) {
            throw $this->createAccessDeniedException('Access denied to this processing activity.');
        }

        return $pa;
    }

    /**
     * Load and tenant-guard a TIA belonging to the given PA.
     */
    private function getTia(int $id, ProcessingActivity $pa): TransferImpactAssessment
    {
        $tia = $this->tiaRepository->find($id);

        if ($tia === null) {
            throw $this->createNotFoundException('TIA not found.');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $tia->getTenant() !== $tenant) {
            throw $this->createAccessDeniedException('Access denied to this TIA.');
        }

        // Verify the TIA belongs to the PA in the URL (prevents cross-PA access)
        if ($tia->getProcessingActivity() !== $pa) {
            throw $this->createAccessDeniedException('TIA does not belong to this processing activity.');
        }

        return $tia;
    }

    // ── Actions ──────────────────────────────────────────────────────────────

    /**
     * List all TIAs for a processing activity.
     * (Used as a standalone TIA index page; the PA show page embeds the list inline.)
     */
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(int $paId): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) {
            return $redirect;
        }

        $pa = $this->getProcessingActivity($paId);
        $tias = $this->tiaRepository->findByProcessingActivity($pa);

        return $this->render('tia/index.html.twig', [
            'processing_activity' => $pa,
            'tias' => $tias,
        ]);
    }

    /**
     * Create a new TIA for a processing activity.
     */
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, int $paId): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) {
            return $redirect;
        }

        $pa = $this->getProcessingActivity($paId);

        $tia = new TransferImpactAssessment();
        $tia->setTenant($this->tenantContext->getCurrentTenant());
        $tia->setProcessingActivity($pa);
        $tia->setCreatedBy($this->currentUser());

        // Pre-fill destination country + mechanism from PA if already set
        if ($pa->getThirdCountries() !== null && count($pa->getThirdCountries()) > 0) {
            $tia->setDestinationCountry($pa->getThirdCountries()[0]);
        }
        if ($pa->getTransferSafeguards() !== null) {
            // Map PA safeguard key to TIA mechanism key (best-effort)
            $mechanismMap = [
                'standard_contractual_clauses' => 'scc',
                'binding_corporate_rules'       => 'bcr',
                'adequacy_decision'             => 'adequacy',
                'certification'                 => 'certification',
                'explicit_consent'              => 'derogation',
                'contract_necessity'            => 'derogation',
                'public_interest'               => 'derogation',
                'legal_claims'                  => 'derogation',
                'vital_interests'               => 'derogation',
            ];
            $mapped = $mechanismMap[$pa->getTransferSafeguards()] ?? null;
            if ($mapped !== null) {
                $tia->setTransferMechanism($mapped);
            }
        }

        $form = $this->createForm(TransferImpactAssessmentType::class, $tia);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($tia);
            $this->entityManager->flush();

            $this->auditLogger?->logCustom(
                'tia.created',
                TransferImpactAssessment::class,
                $tia->getId(),
                newValues: ['destinationCountry' => $tia->getDestinationCountry(), 'residualRiskRating' => $tia->getResidualRiskRating()],
            );

            $this->flashSuccess('tia.success.created');

            if ($this->isTurboFrameRequest($request)) {
                return $this->tiaStreamSave($tia, isNew: true);
            }
            return $this->redirectToRoute(
                'app_tia_show',
                ['paId' => $paId, 'id' => $tia->getId()],
                Response::HTTP_SEE_OTHER
            );
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('tia/_form_modal.html.twig', [
                'tia'                 => $tia,
                'form'                => $form,
                'processing_activity' => $pa,
            ], new Response(status: $status));
        }

        return $this->render('tia/new.html.twig', [
            'form' => $form,
            'tia'  => $tia,
            'processing_activity' => $pa,
        ], new Response(status: $status));
    }

    /**
     * Show a single TIA (read-only detail view).
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, int $paId, int $id): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) {
            return $redirect;
        }

        $pa  = $this->getProcessingActivity($paId);
        $tia = $this->getTia($id, $pa);

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('tia/_detail_modal.html.twig', [
                'tia'                 => $tia,
                'processing_activity' => $pa,
            ]);
        }

        return $this->render('tia/show.html.twig', [
            'tia'                => $tia,
            'processing_activity' => $pa,
        ]);
    }

    /**
     * Edit a TIA (only allowed in draft status).
     */
    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $paId, int $id): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) {
            return $redirect;
        }

        $pa  = $this->getProcessingActivity($paId);
        $tia = $this->getTia($id, $pa);

        if ($tia->getStatus() !== 'draft') {
            $this->flashWarning('tia.warning.cannot_edit_assessed');
            return $this->redirectToRoute('app_tia_show', ['paId' => $paId, 'id' => $id]);
        }

        $tia->setUpdatedBy($this->currentUser());

        $form = $this->createForm(TransferImpactAssessmentType::class, $tia);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->auditLogger?->logCustom(
                'tia.updated',
                TransferImpactAssessment::class,
                $tia->getId(),
                newValues: ['residualRiskRating' => $tia->getResidualRiskRating()],
            );

            $this->flashSuccess('tia.success.updated');

            if ($this->isTurboFrameRequest($request)) {
                return $this->tiaStreamSave($tia, isNew: false);
            }
            return $this->redirectToRoute(
                'app_tia_show',
                ['paId' => $paId, 'id' => $id],
                Response::HTTP_SEE_OTHER
            );
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('tia/_form_modal.html.twig', [
                'tia'                 => $tia,
                'form'                => $form,
                'processing_activity' => $pa,
            ], new Response(status: $status));
        }

        return $this->render('tia/edit.html.twig', [
            'form' => $form,
            'tia'  => $tia,
            'processing_activity' => $pa,
        ], new Response(status: $status));
    }

    /** Turbo Stream after a successful in-modal TIA save (row replace/append). */
    private function tiaStreamSave(TransferImpactAssessment $tia, bool $isNew): Response
    {
        return $this->render('tia/_stream_save.html.twig', [
            'tia'    => $tia,
            'is_new' => $isNew,
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    /**
     * Mark TIA as assessed (draft → assessed).
     *
     * Optionally creates a Risk row when residual risk = high (mirrors DPIA behaviour).
     */
    #[Route('/{id}/assess', name: 'assess', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function assess(Request $request, int $paId, int $id): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) {
            return $redirect;
        }

        $pa  = $this->getProcessingActivity($paId);
        $tia = $this->getTia($id, $pa);

        if (!$this->isCsrfTokenValid('assess' . $tia->getId(), $request->request->get('_token'))) {
            $this->flashError('tia.error.invalid_csrf');
            return $this->redirectToRoute('app_tia_show', ['paId' => $paId, 'id' => $id]);
        }

        if ($tia->getStatus() !== 'draft') {
            $this->flashWarning('tia.warning.already_assessed');
            return $this->redirectToRoute('app_tia_show', ['paId' => $paId, 'id' => $id]);
        }

        $currentUser = $this->currentUser();
        $tia->setStatus('assessed');
        $tia->setAssessedAt(new DateTime());
        $tia->setAssessedBy($currentUser);

        // Create a Risk row when residual risk = high (accountability trail, GDPR Art. 32)
        if ($tia->getResidualRiskRating() === 'high') {
            $risk = new Risk();
            $risk->setTenant($this->tenantContext->getCurrentTenant());
            $risk->setTitle(sprintf(
                'TIA High Risk: %s (%s)',
                $tia->getRecipientName() ?? '—',
                $tia->getDestinationCountry() ?? '—'
            ));
            $risk->setDescription(sprintf(
                "Automatically generated from TIA ID %d.\nSchrems-II assessment found unacceptable residual risk for transfer to %s via %s.\n\n%s",
                (int) $tia->getId(),
                (string) $tia->getDestinationCountry(),
                (string) $tia->getTransferMechanism(),
                (string) ($tia->getConclusion() ?? '')
            ));
            $risk->setCategory('compliance');
            $risk->setProbability(4);
            $risk->setImpact(4);
            // Risk defaults to RiskStatus::Identified on construction — no explicit
            // setStatus() needed (and avoids the lifecycle-managed setStatus rule).
            $this->entityManager->persist($risk);
        }

        $this->entityManager->flush();

        $this->auditLogger?->logCustom(
            'tia.assessed',
            TransferImpactAssessment::class,
            $tia->getId(),
            newValues: ['residualRiskRating' => $tia->getResidualRiskRating()],
        );

        $this->flashSuccess('tia.success.assessed');
        return $this->redirectToRoute(
            'app_tia_show',
            ['paId' => $paId, 'id' => $id],
            Response::HTTP_SEE_OTHER
        );
    }

    /**
     * Delete a TIA.
     */
    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function delete(Request $request, int $paId, int $id): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) {
            return $redirect;
        }

        $pa  = $this->getProcessingActivity($paId);
        $tia = $this->getTia($id, $pa);

        if ($this->isCsrfTokenValid('delete' . $tia->getId(), $request->request->get('_token'))) {
            $this->auditLogger?->logCustom(
                'tia.deleted',
                TransferImpactAssessment::class,
                $tia->getId(),
                oldValues: ['destinationCountry' => $tia->getDestinationCountry()],
            );

            $this->entityManager->remove($tia);
            $this->entityManager->flush();

            $this->flashSuccess('tia.success.deleted');
        }

        return $this->redirectToRoute(
            'app_processing_activity_show',
            ['id' => $paId],
            Response::HTTP_SEE_OTHER
        );
    }
}
