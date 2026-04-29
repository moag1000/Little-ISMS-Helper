<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Form\Admin\GstoolImportUploadType;
use App\Service\Import\GstoolXmlImporter;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin import wizard for GSTOOL XML exports.
 *
 * Single-page upload + dry-run preview / commit. Routes:
 *   GET/POST /admin/import/gstool — upload form, preview or commit results.
 *
 * The actual parsing and persistence is delegated to GstoolXmlImporter.
 * See docs/features/GSTOOL_IMPORT.md for the supported schema and the
 * roadmap to Phase 3+ (Bausteine, Maßnahmen, Risikoanalyse).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route(
    path: '/admin/import/gstool',
    name: 'admin_gstool_import_',
)]
final class GstoolImportController extends AbstractController
{
    public function __construct(
        private readonly GstoolXmlImporter $importer,
        private readonly TenantContext $tenantContext,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('error', 'gstool_import.error.no_tenant');
            return $this->redirectToRoute('admin_dashboard');
        }

        $form = $this->createForm(GstoolImportUploadType::class);
        $form->handleRequest($request);

        $result = null;
        $isDryRun = false;

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $upload */
            $upload = $form->get('file')->getData();
            $isDryRun = (bool) $form->get('dryRun')->getData();

            if ($upload === null || !$upload->isValid()) {
                $this->addFlash('error', 'gstool_import.error.upload_failed');
                return $this->redirectToRoute('admin_gstool_import_index');
            }

            $stored = $this->storeUpload($upload);
            try {
                $result = $isDryRun
                    ? $this->importer->analyse($stored, $tenant)
                    : $this->importer->apply(
                        path: $stored,
                        tenant: $tenant,
                        user: $this->getUser() instanceof \App\Entity\User ? $this->getUser() : null,
                        originalFilename: $upload->getClientOriginalName(),
                    );

                if ($result['header_error'] !== null) {
                    $this->addFlash('error', $result['header_error']);
                } else {
                    $msg = $isDryRun ? 'gstool_import.flash.preview_ok' : 'gstool_import.flash.commit_ok';
                    $this->addFlash('success', $msg);
                }
            } finally {
                @unlink($stored);
            }
        }

        return $this->render('admin/gstool_import/index.html.twig', [
            'form' => $form,
            'result' => $result,
            'isDryRun' => $isDryRun,
        ]);
    }

    private function storeUpload(UploadedFile $upload): string
    {
        $dir = $this->projectDir . '/var/uploads/gstool-import';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $name = 'gstool-' . bin2hex(random_bytes(8)) . '.xml';
        $upload->move($dir, $name);
        return $dir . '/' . $name;
    }
}
