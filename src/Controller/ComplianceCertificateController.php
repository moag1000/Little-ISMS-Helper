<?php

declare(strict_types=1);

namespace App\Controller;

// @em-write-allowed: CSRF-guarded single-row writes only — delete() removes one
// tenant-scoped certificate, confirmDraft() flushes user-confirmed OCR fields
// back onto an already-persisted certificate. No business logic to extract.
use App\Entity\ComplianceCertificate;
use App\Entity\User;
use App\Job\ProcessCertificateOcrJob;
use App\Repository\ComplianceCertificateRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Security\Voter\ComplianceCertificateVoter;
use App\Service\Certificate\CertificateBulkFulfillmentService;
use App\Service\Certificate\CertificateCoverageResolver;
use App\Service\Certificate\CertificateUploadService;
use App\Service\Certificate\OcrCapabilityInterface;
use App\Service\Job\AsyncJobDispatcher;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * ComplianceCertificateController
 *
 * @em-write-allowed: trivial CSRF-guarded single-row writes — delete() removes
 *   one tenant-scoped certificate; confirmDraft() flushes user-confirmed OCR
 *   fields onto an already-persisted certificate. No business logic to extract.
 *
 * User-facing layer for the Compliance-Certificate feature. Lets a manager
 * upload an external certificate (ISO 27001, SOC 2, TISAX, …), preview which
 * compliance requirements it would fulfil, and bulk-apply the coverage.
 *
 * Deliberately NOT under /admin/ so the admin-role-scope gate does not apply —
 * ROLE_MANAGER / ROLE_AUDITOR access (via CERT_* voter) is sufficient. Routes
 * are locale-prefixed automatically by the app_routes loader (/{_locale}/…).
 *
 * Every fetch is tenant-scoped: a certificate belonging to another tenant
 * resolves to 404 (never 403, to avoid leaking existence).
 */
#[Route('/compliance/certificates', name: 'app_compliance_certificate_')]
#[IsGranted(ComplianceCertificateVoter::CERT_VIEW)]
class ComplianceCertificateController extends AbstractController
{
    public function __construct(
        private readonly ComplianceCertificateRepository $certificateRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly CertificateUploadService $uploadService,
        private readonly CertificateCoverageResolver $coverageResolver,
        private readonly CertificateBulkFulfillmentService $bulkFulfillmentService,
        private readonly OcrCapabilityInterface $ocrDetector,
        private readonly AsyncJobDispatcher $asyncJobDispatcher,
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    private function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, 'compliance');
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->requireTenant();

        return $this->render('compliance/certificate/index.html.twig', [
            'certificates' => $this->certificateRepository->findByTenant($tenant),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted(ComplianceCertificateVoter::CERT_MANAGE)]
    public function new(Request $request): Response
    {
        $tenant = $this->requireTenant();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('cert_new', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $file = $request->files->get('certificate_file');

            if (!$file instanceof UploadedFile) {
                $this->addFlash('danger', $this->trans('compliance.certificate.flash.file_required'));

                return $this->redirectToRoute('app_compliance_certificate_new', [
                    '_locale' => $request->getLocale(),
                ]);
            }

            try {
                $cert = $this->uploadService->createFromUpload(
                    $file,
                    $this->extractFields($request),
                    $tenant,
                    $this->currentUser(),
                );
            } catch (\Throwable $e) {
                $this->addFlash('danger', $this->trans('compliance.certificate.flash.upload_failed'));

                return $this->redirectToRoute('app_compliance_certificate_new', [
                    '_locale' => $request->getLocale(),
                ]);
            }

            // OCR path — when the pipeline is available, run the heuristic
            // extraction asynchronously and land the user on the confirm-draft
            // form (pre-filled by the job). The shared progress page polls the
            // job, then redirects to `returnUrl` on completion.
            if ($this->ocrDetector->isAvailable()) {
                return $this->asyncJobDispatcher->dispatchWithProgress(
                    request: $request,
                    jobClass: ProcessCertificateOcrJob::class,
                    jobArgs: ['certificateId' => $cert->getId()],
                    jobName: 'cert.ocr_extract',
                    payload: [
                        '_label' => $this->trans('compliance.certificate.ocr.job_label'),
                        '_subtitle' => $this->trans('compliance.certificate.ocr.job_subtitle'),
                    ],
                    returnUrl: $this->generateUrl('app_compliance_certificate_confirm_draft', [
                        '_locale' => $request->getLocale(),
                        'id' => $cert->getId(),
                    ]),
                );
            }

            // Manual path — unchanged: straight to coverage preview.
            $this->addFlash('success', $this->trans('compliance.certificate.flash.created'));

            return $this->redirectToRoute('app_compliance_certificate_preview', [
                '_locale' => $request->getLocale(),
                'id' => $cert->getId(),
            ]);
        }

        return $this->render('compliance/certificate/new.html.twig', [
            'frameworks' => $this->frameworkRepository->findActiveFrameworks(),
            'ocrAvailable' => $this->ocrDetector->isAvailable(),
        ]);
    }

    /**
     * Editable confirm-draft form (OCR path). GET renders the form pre-filled
     * from the certificate's CURRENT fields — which the async OCR job populated
     * — with a confidence indicator and a "please review & correct" notice.
     * POST maps the corrected fields back onto the certificate and continues to
     * the normal coverage preview.
     */
    #[Route('/{id}/confirm-draft', name: 'confirm_draft', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(ComplianceCertificateVoter::CERT_MANAGE)]
    public function confirmDraft(int $id, Request $request): Response
    {
        $cert = $this->requireCertificate($id);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('cert_confirm_draft', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $fields = $this->extractFields($request);

            $cert->setFrameworkCode((string) ($fields['frameworkCode'] ?? ''))
                ->setCertBody((string) ($fields['certBody'] ?? ''))
                ->setCertNumber($fields['certNumber'] !== null ? (string) $fields['certNumber'] : null)
                ->setScopeText($fields['scopeText'] !== null ? (string) $fields['scopeText'] : null)
                ->setScopeTags(is_array($fields['scopeTags']) ? $fields['scopeTags'] : [])
                ->setCertClass($fields['certClass'] !== null ? (string) $fields['certClass'] : null)
                ->setIssueDate($fields['issueDate'])
                ->setValidUntil($fields['validUntil'])
                ->setHolder($fields['holder'] !== null ? (string) $fields['holder'] : null)
                // The user has reviewed & corrected the OCR draft — record the
                // provenance so downstream consumers know it is human-confirmed
                // rather than a raw machine guess.
                ->setExtractionSource('ocr+confirmed');

            $this->em->flush();

            $this->addFlash('success', $this->trans('compliance.certificate.flash.draft_confirmed'));

            return $this->redirectToRoute('app_compliance_certificate_preview', [
                '_locale' => $request->getLocale(),
                'id' => $cert->getId(),
            ]);
        }

        return $this->render('compliance/certificate/confirm_draft.html.twig', [
            'certificate' => $cert,
            'frameworks' => $this->frameworkRepository->findActiveFrameworks(),
        ]);
    }

    #[Route('/{id}/preview', name: 'preview', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function preview(int $id): Response
    {
        $cert = $this->requireCertificate($id);
        $coverage = $this->coverageResolver->resolve($cert);

        return $this->render('compliance/certificate/preview.html.twig', [
            'certificate' => $cert,
            'coverage' => $coverage,
        ]);
    }

    #[Route('/{id}/apply', name: 'apply', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(ComplianceCertificateVoter::CERT_MANAGE)]
    #[IsCsrfTokenValid('cert_apply')]
    public function apply(int $id, Request $request): Response
    {
        $cert = $this->requireCertificate($id);

        $stats = $this->bulkFulfillmentService->apply($cert, $this->currentUser());

        if ($stats['fulfilled'] === 0) {
            // Nothing matched (unknown framework / empty coverage). A success
            // flash here would mislead the user — surface an informational note.
            $this->addFlash('warning', $this->trans('compliance.certificate.flash.nothing_applied'));
        } else {
            $this->addFlash('success', $this->trans('compliance.certificate.flash.applied', ['%count%' => $stats['fulfilled']]));

            if ($stats['isFallback']) {
                $this->addFlash('warning', $this->trans('compliance.certificate.flash.fallback_used'));
            }
        }

        return $this->redirectToRoute('app_compliance_certificate_show', [
            '_locale' => $request->getLocale(),
            'id' => $cert->getId(),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        return $this->render('compliance/certificate/show.html.twig', [
            'certificate' => $this->requireCertificate($id),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(ComplianceCertificateVoter::CERT_DELETE)]
    #[IsCsrfTokenValid('cert_delete')]
    public function delete(int $id, Request $request): Response
    {
        $cert = $this->requireCertificate($id);

        $this->em->remove($cert);
        $this->em->flush();

        $this->addFlash('success', $this->trans('compliance.certificate.flash.deleted'));

        return $this->redirectToRoute('app_compliance_certificate_index', [
            '_locale' => $request->getLocale(),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Fetch a certificate enforcing tenant-scoping (404 on miss / cross-tenant).
     */
    private function requireCertificate(int $id): ComplianceCertificate
    {
        $tenant = $this->requireTenant();
        $cert = $this->certificateRepository->find($id);

        if ($cert === null || $cert->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Certificate not found.');
        }

        return $cert;
    }

    private function requireTenant(): \App\Entity\Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant context.');
        }

        return $tenant;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('No authenticated user.');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFields(Request $request): array
    {
        $data = $request->request;

        return [
            'frameworkCode' => (string) $data->get('frameworkCode', ''),
            'certBody' => (string) $data->get('certBody', ''),
            'certNumber' => $data->get('certNumber'),
            'scopeText' => $data->get('scopeText'),
            'scopeTags' => $this->parseScopeTags((string) $data->get('scopeTags', '')),
            'certClass' => $data->get('certClass'),
            'issueDate' => $this->parseDate((string) $data->get('issueDate', '')),
            'validUntil' => $this->parseDate((string) $data->get('validUntil', '')),
            'holder' => $data->get('holder'),
        ];
    }

    /**
     * @return list<string>
     */
    private function parseScopeTags(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $t): bool => $t !== ''));
    }

    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        if (trim($raw) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
