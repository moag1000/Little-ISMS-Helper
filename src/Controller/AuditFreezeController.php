<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditFreeze;
use App\Form\AuditFreezeType;
use App\Repository\AuditFreezeRepository;
use App\Service\AuditFreezeService;
use App\Service\PdfExportService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Audit Freeze Controller — manual Stichtag snapshots for certification /
 * surveillance audits.
 *
 * By-design immutable: no edit, no delete endpoint. The payload is sealed
 * with SHA-256 at create time and verified on demand. PDF generation is
 * separate from freeze creation so an admin can re-generate the PDF after
 * styling changes without affecting payload integrity.
 *
 * @see docs/CM_JUNIOR_RESPONSE.md CM-8
 */
#[Route('/audit-freeze')]
#[IsGranted('ROLE_MANAGER')]
class AuditFreezeController extends AbstractController
{
    private const PDF_SUBDIR = 'audit_freezes';

    public function __construct(
        private readonly AuditFreezeService $freezeService,
        private readonly AuditFreezeRepository $freezeRepository,
        private readonly TenantContext $tenantContext,
        private readonly PdfExportService $pdfExportService,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $projectDir,
    ) {
    }

    #[Route('', name: 'app_audit_freeze_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', 'audit_freeze.flash.no_tenant');
            return $this->redirectToRoute('app_dashboard');
        }

        $purpose = $request->query->get('purpose');
        $yearRaw = $request->query->get('year');
        $year = ($yearRaw !== null && $yearRaw !== '') ? (int) $yearRaw : null;

        $freezes = $this->freezeRepository->findByTenantFiltered($tenant, $purpose, $year);
        $years = $this->freezeRepository->findDistinctYears($tenant);

        return $this->render('audit_freeze/index.html.twig', [
            'freezes' => $freezes,
            'years' => $years,
            'purposes' => AuditFreeze::PURPOSES,
            'selected_purpose' => $purpose,
            'selected_year' => $year,
        ]);
    }

    #[Route('/new', name: 'app_audit_freeze_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', 'audit_freeze.flash.no_tenant');
            return $this->redirectToRoute('app_dashboard');
        }

        $freeze = new AuditFreeze();
        $form = $this->createForm(AuditFreezeType::class, $freeze);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUser();
            if (!$user instanceof UserInterface) {
                throw $this->createAccessDeniedException();
            }
            /** @var \App\Entity\User $user */
            $stichtag = $freeze->getStichtag();
            // Defensive: form-level check repeated here, since the
            // DateType widget cannot fully prevent a crafted POST.
            if ($stichtag > new DateTimeImmutable('today')) {
                $this->addFlash('danger', 'audit_freeze.flash.stichtag_future');
                return $this->redirectToRoute('app_audit_freeze_new');
            }

            $created = $this->freezeService->create(
                $tenant,
                $freeze->getFreezeName(),
                $stichtag,
                $freeze->getFrameworkCodes(),
                $freeze->getPurpose(),
                $freeze->getNotes(),
                $user,
            );

            $this->addFlash('success', 'audit_freeze.flash.created');
            return $this->redirectToRoute('app_audit_freeze_show', ['id' => $created->getId()]);
        }

        return $this->render('audit_freeze/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_audit_freeze_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $freeze = $this->loadForTenant($id);
        $verified = $this->freezeService->verify($freeze);

        return $this->render('audit_freeze/show.html.twig', [
            'freeze' => $freeze,
            'verified' => $verified,
            'payload_pretty' => json_encode(
                $freeze->getPayloadJson(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ]);
    }

    #[Route('/{id}/verify', name: 'app_audit_freeze_verify', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function verify(int $id): Response
    {
        $freeze = $this->loadForTenant($id);
        $verified = $this->freezeService->verify($freeze);

        $this->addFlash(
            $verified ? 'success' : 'danger',
            $verified ? 'audit_freeze.flash.verify_ok' : 'audit_freeze.flash.verify_failed'
        );

        return $this->redirectToRoute('app_audit_freeze_show', ['id' => $id]);
    }

    #[Route('/{id}/generate-pdf', name: 'app_audit_freeze_generate_pdf', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function generatePdf(int $id): Response
    {
        $freeze = $this->loadForTenant($id);

        $pdfBytes = $this->pdfExportService->generatePdf(
            'audit_freeze/pdf/freeze_report.html.twig',
            [
                'freeze' => $freeze,
                'payload' => $freeze->getPayloadJson(),
            ],
        );

        $dir = $this->projectDir . '/var/' . self::PDF_SUBDIR;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create PDF output directory "%s".', $dir));
        }

        $filename = sprintf('%d.pdf', $freeze->getId());
        $fullPath = $dir . '/' . $filename;
        if (file_put_contents($fullPath, $pdfBytes) === false) {
            throw new \RuntimeException(sprintf('Could not write PDF file "%s".', $fullPath));
        }

        $freeze->setPdfPath($filename);
        $freeze->setPdfGeneratedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->addFlash('success', 'audit_freeze.flash.pdf_generated');
        return $this->redirectToRoute('app_audit_freeze_show', ['id' => $id]);
    }

    #[Route('/{id}/pdf', name: 'app_audit_freeze_download_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function downloadPdf(int $id): Response
    {
        $freeze = $this->loadForTenant($id);

        if (!$freeze->hasPdf()) {
            $this->addFlash('warning', 'audit_freeze.flash.no_pdf');
            return $this->redirectToRoute('app_audit_freeze_show', ['id' => $id]);
        }

        $fullPath = $this->projectDir . '/var/' . self::PDF_SUBDIR . '/' . $freeze->getPdfPath();
        if (!is_file($fullPath)) {
            $this->addFlash('warning', 'audit_freeze.flash.pdf_missing_on_disk');
            return $this->redirectToRoute('app_audit_freeze_show', ['id' => $id]);
        }

        $response = new BinaryFileResponse($fullPath);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('audit-freeze-%d-%s.pdf', $freeze->getId(), $freeze->getStichtag()->format('Y-m-d'))
        );

        return $response;
    }

    private function loadForTenant(int $id): AuditFreeze
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant in context.');
        }
        $freeze = $this->freezeRepository->find($id);
        if ($freeze === null || $freeze->getTenant()->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Audit freeze not found.');
        }
        return $freeze;
    }
}
