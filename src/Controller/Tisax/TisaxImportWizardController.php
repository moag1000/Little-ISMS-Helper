<?php

declare(strict_types=1);

namespace App\Controller\Tisax;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Form\Tisax\TisaxLegalConfirmationType;
use App\Form\Tisax\VdaIsaUploadType;
use App\Service\AuditLogger;
use App\Service\FileUploadSecurityService;
use App\Service\Import\Mapper\TisaxRequirementMapper;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use App\Service\Tisax\EnxScheduleExporter;
use App\Service\Tisax\TisaxConfirmationService;
use App\Service\Tisax\TisaxImportSupportService;
use App\Service\Tisax\RequirementLevelMetadataLoader;
use App\Service\Tisax\TisaxMaturityAssessmentService;
use App\Service\Tisax\VdaIsaWorkbookParser;
use App\Service\Tisax\VdaIsaWorkbookValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * TISAX BYO VDA-ISA Import Wizard
 *
 * 6-step wizard (Step 0–5) allowing tenants to upload their own
 * ENX-licensed VDA-ISA workbook and assess Reifegrad 0-5.
 *
 * All steps are gated behind ROLE_MANAGER.
 *
 * Step flow:
 *   0 — Legal disclaimer (TisaxLegalConfirmationType)
 *   1 — Upload workbook (VdaIsaUploadType + FileUploadSecurityService)
 *   2 — Validate   (VdaIsaWorkbookValidator — header + row sanity)
 *   3 — Preview    (TisaxRequirementMapper::computeDelta)
 *   4 — Commit     (TisaxRequirementMapper::mapRows → flush)
 *   5 — Assess     (TisaxMaturityAssessmentService — Reifegrad 0-5)
 */
#[IsGranted('ROLE_MANAGER')]
#[Route('/tisax-import', name: 'app_tisax_import_')]
final class TisaxImportWizardController extends AbstractController
{
    use ModuleGatedControllerTrait;

    /** Session key for the parsed workbook temp-file path */
    private const SESSION_WORKBOOK_PATH = 'tisax_import.workbook_path';

    /** Session key for parsed controls (serialised JSON) */
    private const SESSION_PARSED_CONTROLS = 'tisax_import.parsed_controls';

    /** Session key for validation result */
    private const SESSION_VALIDATION = 'tisax_import.validation';

    /** Session key for the organisation name read from the workbook cover sheet */
    private const SESSION_WORKBOOK_COMPANY = 'tisax_import.workbook_company';

    /** Temp upload subdirectory */
    private const UPLOAD_SUBDIR = 'tisax_workbooks';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly VdaIsaWorkbookParser $parser,
        private readonly VdaIsaWorkbookValidator $validator,
        private readonly TisaxRequirementMapper $mapper,
        private readonly TisaxMaturityAssessmentService $maturityService,
        private readonly TisaxImportSupportService $importSupport,
        private readonly EnxScheduleExporter $enxExporter,
        private readonly FileUploadSecurityService $uploadSecurity,
        private readonly AuditLogger $auditLogger,
        private readonly TisaxConfirmationService $confirmationService,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly string $uploadDir,
        private readonly ?RequirementLevelMetadataLoader $metadataLoader = null,
    ) {}

    // ──────────────────────────────────────────────────────────────────────────
    // Step 0 — Legal disclaimer
    // ──────────────────────────────────────────────────────────────────────────

    #[Route('/disclaimer', name: 'disclaimer', methods: ['GET', 'POST'])]
    public function disclaimer(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('tisax')) {
            return $redirect;
        }
        // On fresh entry (GET), wipe any prior import-session state so a
        // second import starts clean — prevents stale workbook/parsed-controls
        // from leaking into the new run.
        if ($request->isMethod('GET')) {
            $this->resetImportSession($request);
        }

        $form = $this->createForm(TisaxLegalConfirmationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $tenant = $this->tenantContext->getCurrentTenant();
            /** @var \App\Entity\User $user */
            $user   = $this->getUser();

            // Persist licence confirmation record via dedicated service (em-write out of controller)
            $confirmation = $this->confirmationService->record(
                tenant: $tenant,
                user: $user,
                sessionId: $request->getSession()->getId(),
                ipAddress: $request->getClientIp() ?? '',
            );

            // Store confirmation ID in session so upload step can verify
            $request->getSession()->set('tisax_import.confirmation_id', $confirmation->getId());

            $this->addFlash('success', 'tisax.import.disclaimer.confirmed');

            return $this->redirectToRoute('app_tisax_import_upload', [
                '_locale' => $request->getLocale(),
            ]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('tisax/import/disclaimer.html.twig', [
            'form'     => $form->createView(),
            'stepIndex' => 0,
        ], new Response(status: $status));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 1 — Upload
    // ──────────────────────────────────────────────────────────────────────────

    #[Route('/upload', name: 'upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('tisax')) {
            return $redirect;
        }
        // Guard: must have a valid disclaimer confirmation
        if (!$this->hasValidConfirmation($request)) {
            $this->addFlash('warning', 'tisax.import.upload.disclaimer_required');
            return $this->redirectToRoute('app_tisax_import_disclaimer', ['_locale' => $request->getLocale()]);
        }

        $form = $this->createForm(VdaIsaUploadType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->get('workbook')->getData();

            // Security: MIME + magic-byte validation
            try {
                $this->uploadSecurity->validateUpload($file);
            } catch (\Exception $e) {
                $this->addFlash('danger', 'tisax.import.upload.security_rejected');
                $status = ($form->isSubmitted() && !$form->isValid())
                    ? Response::HTTP_UNPROCESSABLE_ENTITY
                    : Response::HTTP_OK;

                return $this->render('tisax/import/upload.html.twig', [
                    'form'      => $form->createView(),
                    'stepIndex' => 1,
                ], new Response(status: $status));
            }

            // Store workbook to temp directory
            $uploadPath = $this->getUploadDir();
            $newFilename = sprintf('vda_isa_%s_%s.xlsx', uniqid('', true), date('Ymd'));
            $file->move($uploadPath, $newFilename);

            $fullPath = $uploadPath . '/' . $newFilename;
            $request->getSession()->set(self::SESSION_WORKBOOK_PATH, $fullPath);

            // Update confirmation record with actual filename
            $this->updateConfirmationFilename($request, $file->getClientOriginalName() ?? $newFilename);

            return $this->redirectToRoute('app_tisax_import_validate', [
                '_locale' => $request->getLocale(),
            ]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('tisax/import/upload.html.twig', [
            'form'      => $form->createView(),
            'stepIndex' => 1,
        ], new Response(status: $status));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 2 — Validate
    // ──────────────────────────────────────────────────────────────────────────

    #[Route('/validate', name: 'validate', methods: ['GET', 'POST'])]
    public function validate(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('tisax')) {
            return $redirect;
        }
        $workbookPath = $request->getSession()->get(self::SESSION_WORKBOOK_PATH);
        if ($workbookPath === null || !file_exists($workbookPath)) {
            $this->addFlash('warning', 'tisax.import.validate.no_workbook');
            return $this->redirectToRoute('app_tisax_import_upload', ['_locale' => $request->getLocale()]);
        }

        try {
            $parsed = $this->parser->parse($workbookPath);
        } catch (\ErrorException $e) {
            $this->addFlash('danger', $e->getMessage());
            return $this->redirectToRoute('app_tisax_import_upload', ['_locale' => $request->getLocale()]);
        }

        $validation = $this->validator->validate($parsed);

        // ISA 5.x is a subset of ISA 6 — accepted as a PARTIAL import. Warn the
        // user that the older workbook fills its matching ISA-6 controls and the
        // 6.x-only controls (e.g. the expanded Data-Protection module) remain
        // unassessed.
        $isPartialFive = $parsed->workbookVersion !== null && str_starts_with($parsed->workbookVersion, '5.');
        if ($isPartialFive) {
            $this->addFlash('warning', $this->translator->trans(
                'tisax.import.validate.legacy_five_partial',
                ['%version%' => $parsed->workbookVersion, '%count%' => $parsed->getControlCount()],
                'tisax_isa',
            ));
        }

        // Serialise parsed controls into session for next step
        $serialisedControls = $this->importSupport->serialiseControls($parsed->controls);
        $request->getSession()->set(self::SESSION_PARSED_CONTROLS, $serialisedControls);
        $request->getSession()->set(self::SESSION_VALIDATION, $validation);
        $request->getSession()->set(self::SESSION_WORKBOOK_COMPANY, $parsed->workbookCompany);

        if ($request->isMethod('POST') && $validation['ok']) {
            if (!$this->isCsrfTokenValid('tisax_proceed', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('CSRF token invalid.');
            }
            return $this->redirectToRoute('app_tisax_import_preview', ['_locale' => $request->getLocale()]);
        }

        return $this->render('tisax/import/validate.html.twig', [
            'validation'  => $validation,
            'controlCount' => $parsed->getControlCount(),
            'sheetName'   => $parsed->sheetName,
            'columnMap'   => $parsed->detectedColumnMap,
            'workbookVersion' => $parsed->workbookVersion,
            'isPartialFive'   => $isPartialFive,
            'stepIndex'   => 2,
            'canProceed'  => $validation['ok'],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 3 — Preview
    // ──────────────────────────────────────────────────────────────────────────

    #[Route('/preview', name: 'preview', methods: ['GET', 'POST'])]
    public function preview(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('tisax')) {
            return $redirect;
        }
        $controls = $this->getSessionControls($request);
        if ($controls === null) {
            return $this->redirectToRoute('app_tisax_import_validate', ['_locale' => $request->getLocale()]);
        }

        $tenant    = $this->tenantContext->getCurrentTenant();
        $framework = $this->mapper->findOrCreateFramework();
        $delta     = $this->mapper->computeDelta($controls, $framework, $tenant);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('tisax_proceed', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('CSRF token invalid.');
            }
            return $this->redirectToRoute('app_tisax_import_commit', ['_locale' => $request->getLocale()]);
        }

        $previewControls = array_slice($controls, 0, 20);
        $previewRows = array_map(
            static fn ($ctrl): array => [
                $ctrl->controlId,
                mb_strlen($ctrl->title) > 120 ? mb_substr($ctrl->title, 0, 120) . '…' : $ctrl->title,
                $ctrl->iso27001Ref ?: '—',
            ],
            $previewControls,
        );

        return $this->render('tisax/import/preview.html.twig', [
            'delta'       => $delta,
            'controls'    => $previewControls,
            'previewRows' => $previewRows,
            'totalRows'   => count($controls),
            'framework'   => $framework,
            'stepIndex'   => 3,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 4 — Commit
    // ──────────────────────────────────────────────────────────────────────────

    #[Route('/commit', name: 'commit', methods: ['GET', 'POST'])]
    public function commit(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('tisax')) {
            return $redirect;
        }
        $controls = $this->getSessionControls($request);
        if ($controls === null) {
            return $this->redirectToRoute('app_tisax_import_validate', ['_locale' => $request->getLocale()]);
        }

        $tenant    = $this->tenantContext->getCurrentTenant();
        $framework = $this->mapper->findOrCreateFramework();

        $workbookCompany = $request->getSession()->get(self::SESSION_WORKBOOK_COMPANY);
        $workbookCompany = is_string($workbookCompany) ? $workbookCompany : null;
        $companyMismatch = $this->importSupport->isOrganisationMismatch($workbookCompany, $tenant);

        if ($request->isMethod('POST')) {
            // CSRF protection
            if (!$this->isCsrfTokenValid('tisax_commit', $request->request->get('_token'))) {
                $this->addFlash('danger', 'common.csrf_invalid');
                return $this->redirectToRoute('app_tisax_import_commit', ['_locale' => $request->getLocale()]);
            }

            // Organisation-mismatch guard: when the workbook cover sheet names a
            // different organisation than the current tenant, the user MUST tick
            // the confirmation checkbox before the import is allowed to proceed.
            if ($companyMismatch && !$request->request->getBoolean('company_confirm')) {
                $this->addFlash('warning', 'tisax.import.commit.company_confirm_required');
                return $this->redirectToRoute('app_tisax_import_commit', ['_locale' => $request->getLocale()]);
            }

            $result = $this->mapper->mapRows($controls, $framework, $tenant);

            // Selective Reifegrad-overwrite: apply only the diff rows the user
            // explicitly ticked, overwriting the stored assessment with the
            // workbook value (audited separately below).
            /** @var \App\Entity\User $user */
            $user        = $this->getUser();
            $applyIds    = array_map('strval', (array) $request->request->all('apply_maturity'));
            $overwritten = $this->importSupport->applyMaturityOverwrites($applyIds, $controls, $framework, $tenant, $user);

            $this->auditLogger->logImport(
                'ComplianceRequirement',
                $result['created'] + $result['updated'],
                sprintf(
                    'TISAX BYO import: %d created, %d updated, %d Reifegrad overwritten — framework %s',
                    $result['created'],
                    $result['updated'],
                    $overwritten,
                    $framework->getCode(),
                ),
            );

            // Clean up workbook temp file
            $workbookPath = $request->getSession()->get(self::SESSION_WORKBOOK_PATH);
            if ($workbookPath !== null && file_exists($workbookPath)) {
                @unlink($workbookPath);
            }
            $request->getSession()->remove(self::SESSION_WORKBOOK_PATH);
            $request->getSession()->remove(self::SESSION_PARSED_CONTROLS);
            $request->getSession()->remove(self::SESSION_VALIDATION);

            $this->addFlash('success', 'tisax.import.commit.success');

            return $this->redirectToRoute('app_tisax_import_assess', [
                '_locale'     => $request->getLocale(),
                'frameworkId' => $framework->getId(),
            ]);
        }

        $delta        = $this->mapper->computeDelta($controls, $framework, $tenant);
        $maturityDiff = $this->mapper->computeMaturityDiff($controls, $framework, $tenant);

        return $this->render('tisax/import/commit.html.twig', [
            'delta'           => $delta,
            'maturityDiff'    => $maturityDiff,
            'framework'       => $framework,
            'stepIndex'       => 4,
            'workbookCompany' => $workbookCompany,
            'tenantName'      => $tenant?->getName(),
            'companyMismatch' => $companyMismatch,
            'token'           => $this->csrfTokenManager->getToken('tisax_commit')->getValue(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 5 — Assess (Reifegrad 0-5)
    // ──────────────────────────────────────────────────────────────────────────

    #[Route('/assess/{frameworkId}', name: 'assess', methods: ['GET', 'POST'], requirements: ['frameworkId' => '\d+'])]
    public function assess(Request $request, int $frameworkId): Response
    {
        if ($redirect = $this->checkModuleActive('tisax')) {
            return $redirect;
        }
        $tenant    = $this->tenantContext->getCurrentTenant();
        $framework = $this->em->getRepository(\App\Entity\ComplianceFramework::class)->find($frameworkId);

        if ($framework === null) {
            throw $this->createNotFoundException('Framework not found.');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('tisax_assess', $request->request->get('_token'))) {
                $this->addFlash('danger', 'common.csrf_invalid');
                return $this->redirectToRoute('app_tisax_import_assess', [
                    '_locale'     => $request->getLocale(),
                    'frameworkId' => $frameworkId,
                ]);
            }

            // IS / PP tier: Reifegrad 0-5 integers. Skip empty strings — the
            // "Bitte wählen" placeholder option submits '' for untouched rows,
            // and we MUST NOT overwrite those as 0 (incomplete) silently.
            /** @var array<string, string> $reifegradRaw */
            $reifegradRaw = (array) $request->request->all('reifegrad');
            $reifegradRaw = array_filter($reifegradRaw, static fn ($v): bool => $v !== '' && $v !== null);
            $levelMap     = array_map('intval', $reifegradRaw);

            // DP tier: tristate string values (not_applicable | compliant | non_compliant)
            /** @var array<string, string> $dpRaw */
            $dpRaw = (array) $request->request->all('dp');

            $this->maturityService->bulkSetReifegrad($levelMap, $user, $tenant);
            $this->maturityService->bulkSetDataProtectionCompliance($dpRaw, $user, $tenant);

            $this->addFlash('success', 'tisax.import.assess.saved');

            return $this->redirectToRoute('app_tisax_import_assess', [
                '_locale'     => $request->getLocale(),
                'frameworkId' => $frameworkId,
            ]);
        }

        $aggregate = $this->maturityService->computeAggregate($framework, $tenant);

        $requirements = $this->em->getRepository(\App\Entity\ComplianceRequirement::class)->findBy([
            'framework'         => $framework,
            'uploadTenant'      => $tenant,
            'requirementSource' => 'tenant_upload',
        ]);

        // Build per-requirement level-flag map (Must/Sollte/High/VeryHigh) from YAML fixture.
        // Empty when RequirementLevelMetadataLoader is not wired (graceful degradation).
        $requirementLevelFlags = [];
        if ($this->metadataLoader !== null) {
            foreach ($requirements as $req) {
                $reqId = (string) ($req->getRequirementId() ?? '');
                $meta  = $this->metadataLoader->getMetadataFor($reqId);
                if ($meta !== null) {
                    $requirementLevelFlags[$reqId] = $meta['levels'];
                }
            }
        }

        return $this->render('tisax/import/assess.html.twig', [
            'framework'             => $framework,
            'requirements'          => $requirements,
            'aggregate'             => $aggregate,
            'levels'                => TisaxMaturityAssessmentService::LEVEL_MAP,
            'stepIndex'             => 5,
            'token'                 => $this->csrfTokenManager->getToken('tisax_assess')->getValue(),
            'requirementLevelFlags' => $requirementLevelFlags,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // ENX Schedule export (non-wizard route)
    // ──────────────────────────────────────────────────────────────────────────

    #[Route('/assess/{frameworkId}/export-enx', name: 'export_enx', methods: ['GET'], requirements: ['frameworkId' => '\d+'])]
    public function exportEnx(int $frameworkId): Response
    {
        if ($redirect = $this->checkModuleActive('tisax')) {
            return $redirect;
        }
        $tenant    = $this->tenantContext->getCurrentTenant();
        $framework = $this->em->getRepository(\App\Entity\ComplianceFramework::class)->find($frameworkId);

        if ($framework === null) {
            throw $this->createNotFoundException('Framework not found.');
        }

        return $this->enxExporter->exportAsResponse($framework, $tenant);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function resetImportSession(Request $request): void
    {
        $session = $request->getSession();
        $workbookPath = $session->get(self::SESSION_WORKBOOK_PATH);
        if (is_string($workbookPath) && file_exists($workbookPath)) {
            @unlink($workbookPath);
        }
        $session->remove(self::SESSION_WORKBOOK_PATH);
        $session->remove(self::SESSION_PARSED_CONTROLS);
        $session->remove(self::SESSION_VALIDATION);
        $session->remove(self::SESSION_WORKBOOK_COMPANY);
        $session->remove('tisax_import.confirmation_id');
    }

    private function hasValidConfirmation(Request $request): bool
    {
        $confirmationId = $request->getSession()->get('tisax_import.confirmation_id');
        $user           = $this->getUser();

        return $this->confirmationService->isValidFor(
            $confirmationId !== null ? (int) $confirmationId : null,
            $user instanceof \App\Entity\User ? $user : null,
            $this->tenantContext->getCurrentTenant(),
            $request->getSession()->getId(),
        );
    }

    private function updateConfirmationFilename(Request $request, string $filename): void
    {
        $confirmationId = $request->getSession()->get('tisax_import.confirmation_id');
        if ($confirmationId === null) {
            return;
        }

        $this->confirmationService->updateFilename((int) $confirmationId, $filename);
    }

    /**
     * Deserialise VdaIsaControlRow DTOs from the session.
     *
     * @return list<\App\Service\Tisax\Dto\VdaIsaControlRow>|null
     */
    private function getSessionControls(Request $request): ?array
    {
        return $this->importSupport->deserialiseControls(
            $request->getSession()->get(self::SESSION_PARSED_CONTROLS),
        );
    }

    private function getUploadDir(): string
    {
        $dir = rtrim($this->uploadDir, '/') . '/' . self::UPLOAD_SUBDIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }
}
