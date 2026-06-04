<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\CurrentUserTrait;
use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\Document;
use App\Entity\ProcessingActivity;
use App\Entity\Supplier;
use App\Repository\DocumentRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\SupplierRepository;
use App\Service\AuditLogger;
use App\Service\Privacy\DpaGeneratorService;
use App\Service\PolicyWizard\Export\PolicyPdfExporter;
use App\Service\TenantContext;
use App\Service\ModuleConfigurationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * F32 DPA-Generator — Art. 28 GDPR AVV generation endpoints.
 *
 * Two actions:
 *  - GET  `/privacy/dpa/generate/{supplierId}/{paId}`  — confirm page
 *  - POST `/privacy/dpa/generate/{supplierId}/{paId}`  — generate + redirect
 *  - GET  `/privacy/dpa/{id}/pdf`                      — download PDF
 *
 * Module-gated behind 'privacy'. Tenant-isolated.
 */
#[Route('/privacy/dpa', name: 'app_dpa_')]
#[IsGranted('ROLE_USER')]
final class DpaController extends AbstractController
{
    use CurrentUserTrait;
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly DpaGeneratorService $dpaGenerator,
        private readonly SupplierRepository $supplierRepository,
        private readonly ProcessingActivityRepository $paRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly PolicyPdfExporter $pdfExporter,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Confirm page: shows summary of Supplier + ProcessingActivity before generation.
     */
    #[Route(
        '/generate/{supplierId}/{paId}',
        name: 'generate_confirm',
        requirements: ['supplierId' => '\d+', 'paId' => '\d+'],
        methods: ['GET'],
    )]
    public function generateConfirm(int $supplierId, int $paId): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) {
            return $redirect;
        }

        [$supplier, $pa] = $this->resolveAndGuard($supplierId, $paId);

        return $this->render('privacy/dpa/generate_confirm.html.twig', [
            'supplier' => $supplier,
            'pa'       => $pa,
        ]);
    }

    /**
     * POST: generate the AVV Document and redirect to its show page.
     *
     * CSRF token id: `dpa_generate_{supplierId}_{paId}` — must match the
     * hidden `_token` input rendered in `generate_confirm.html.twig`.
     */
    #[Route(
        '/generate/{supplierId}/{paId}',
        name: 'generate',
        requirements: ['supplierId' => '\d+', 'paId' => '\d+'],
        methods: ['POST'],
    )]
    public function generate(int $supplierId, int $paId, Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) {
            return $redirect;
        }

        // CSRF token id composed at runtime from route vars:
        // `dpa_generate_{supplierId}_{paId}` — must match the csrf_token() call
        // in generate_confirm.html.twig.
        // #[IsCsrfTokenValid] does not support runtime-composed ids, so we
        // validate manually here (same pattern as SupplierController::clone).
        $tokenId = sprintf('dpa_generate_%d_%d', $supplierId, $paId);
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges CSRF-Token.');
        }

        [$supplier, $pa] = $this->resolveAndGuard($supplierId, $paId);

        $doc = $this->dpaGenerator->generate($supplier, $pa, $this->currentUser());

        $this->addFlash('success', 'dpa.generate.flash.success');

        return $this->redirectToRoute('app_document_show', ['id' => $doc->getId()]);
    }

    /**
     * Download the generated AVV as PDF.
     * Streams policyBody via PolicyPdfExporter — no new PDF infra needed.
     */
    #[Route(
        '/{id}/pdf',
        name: 'pdf',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function downloadPdf(int $id): Response
    {
        if ($redirect = $this->checkModuleActive('privacy')) {
            return $redirect;
        }

        $doc = $this->documentRepository->find($id);
        if (!$doc instanceof Document || $doc->getCategory() !== 'dpa') {
            throw $this->createNotFoundException('AVV-Dokument nicht gefunden.');
        }

        // Tenant guard.
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $doc->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('Das Dokument gehört nicht zu Ihrer Organisation.');
        }

        $pdf = $this->pdfExporter->exportDocument($doc);

        $this->auditLogger->logExport(
            entityType: 'Document',
            entityId: $doc->getId(),
            description: sprintf(
                'AVV PDF-Download: Dokument #%d (%s) durch %s',
                $doc->getId() ?? 0,
                $doc->getOriginalFilename() ?? '',
                $this->currentUser()->getUserIdentifier(),
            ),
        );

        $filename = preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) ($doc->getOriginalFilename() ?? ('avv-' . $id)));
        if (!str_ends_with($filename, '.pdf')) {
            $filename .= '.pdf';
        }

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Content-Length'      => (string) strlen($pdf),
            'X-Robots-Tag'        => 'noindex',
        ]);
    }

    /**
     * Resolve Supplier + ProcessingActivity and guard both against the current tenant.
     *
     * @return array{0: Supplier, 1: ProcessingActivity}
     */
    private function resolveAndGuard(int $supplierId, int $paId): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        $supplier = $this->supplierRepository->find($supplierId);
        if (!$supplier instanceof Supplier) {
            throw $this->createNotFoundException(sprintf('Lieferant #%d nicht gefunden.', $supplierId));
        }
        if ($tenant !== null && $supplier->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('Lieferant gehört nicht zu Ihrer Organisation.');
        }

        $pa = $this->paRepository->find($paId);
        if (!$pa instanceof ProcessingActivity) {
            throw $this->createNotFoundException(sprintf('Verarbeitungstätigkeit #%d nicht gefunden.', $paId));
        }
        if ($tenant !== null && $pa->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('Verarbeitungstätigkeit gehört nicht zu Ihrer Organisation.');
        }

        return [$supplier, $pa];
    }
}
