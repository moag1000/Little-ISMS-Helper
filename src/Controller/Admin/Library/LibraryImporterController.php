<?php

declare(strict_types=1);

namespace App\Controller\Admin\Library;

use App\Repository\ComplianceFrameworkRepository;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\AuditLogger;
use App\Service\Library\BsiKompendiumImporter;
use App\Service\Library\LibraryRoundtripService;
use App\Service\Library\VdaIsaImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin-only Library Import/Export controller.
 *
 * Routes:
 *  GET  /admin/library/import            — index: list available library YAMLs
 *  POST /admin/library/import            — run import (BSI or TISAX)
 *  GET  /admin/library/export/{id}/yaml  — download YAML export
 *  GET  /admin/library/export/{id}/csv   — download CSV export
 *
 * Module gate: 'compliance' module (existing — no new key needed).
 *
 * Authorization (Phase 4b of Role-Scope Architecture, spec
 * `docs/superpowers/specs/2026-05-18-role-scope-architecture.md`):
 *  - Class-level {@see TenantScopedAdminVoter::ADMIN_OWN_TENANT} as
 *    baseline (ROLE_ADMIN reads/imports for own tenant; SUPER_ADMIN
 *    passes transparently).
 *  - {@see import()} is `W global` per spec §3.1 — it writes global
 *    library frameworks (BSI / TISAX) that are shared across tenants —
 *    so the method-level guard is tightened to
 *    {@see TenantScopedAdminVoter::ADMIN_GLOBAL_OP} (SUPER_ADMIN only).
 */
#[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
#[Route('/admin/library', name: 'admin_library_')]
class LibraryImporterController extends AbstractController
{
    public function __construct(
        private readonly BsiKompendiumImporter $bsiImporter,
        private readonly VdaIsaImporter $vdaImporter,
        private readonly LibraryRoundtripService $roundtripService,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly AuditLogger $auditLogger,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Overview: list available library YAMLs with last-import status.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $availableLibraries = $this->discoverAvailableLibraries();
        $importedFrameworks = $this->frameworkRepository->findBy([], ['code' => 'ASC']);

        return $this->render('admin/library/index.html.twig', [
            'available_libraries' => $availableLibraries,
            'imported_frameworks' => $importedFrameworks,
        ]);
    }

    /**
     * Run a library import (BSI or TISAX).
     *
     * POST ?type=bsi   — imports bsi-it-grundschutz-2024.yaml
     * POST ?type=tisax — imports vda-isa-tisax-v6.yaml
     *
     * Global op: writes global ComplianceFramework rows shared across all
     * tenants. Restricted to ROLE_SUPER_ADMIN per spec §3.1.
     */
    #[Route('/import', name: 'import', methods: ['POST'])]
    #[IsGranted(TenantScopedAdminVoter::ADMIN_GLOBAL_OP)]
    #[IsCsrfTokenValid('library_import', tokenKey: '_token')]
    public function import(Request $request): Response
    {
        $type = $request->query->getString('type', 'bsi');
        $stats = [];
        $frameworkCode = '';

        if ($type === 'tisax') {
            $stats = $this->vdaImporter->importDefault();
            $frameworkCode = 'TISAX-VDA-ISA-6';
        } else {
            // Default: BSI
            $stats = $this->bsiImporter->importDefault();
            $frameworkCode = 'BSI-GRUNDSCHUTZ-2024';
        }

        $this->auditLogger->logCreate(
            'LibraryFramework',
            null,
            [
                'type' => $type,
                'framework_code' => $frameworkCode,
                'frameworks_created' => $stats['frameworks_created'],
                'frameworks_updated' => $stats['frameworks_updated'],
                'requirements_created' => $stats['requirements_created'],
                'requirements_updated' => $stats['requirements_updated'],
                'errors_count' => count($stats['errors']),
            ],
            AuditLogger::ACTION_LIBRARY_FRAMEWORK_IMPORTED,
        );

        $hasErrors = !empty($stats['errors']);

        return $this->render('admin/library/_import_result.html.twig', [
            'stats' => $stats,
            'type' => $type,
            'has_errors' => $hasErrors,
        ]);
    }

    /**
     * Export a framework as YAML download.
     */
    #[Route('/export/{id}/yaml', name: 'export_yaml', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportYaml(int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if ($framework === null) {
            throw $this->createNotFoundException('Framework not found.');
        }

        $yaml = $this->roundtripService->exportYaml($framework);
        $filename = sprintf('framework_%s_%s.yaml', $framework->getCode(), date('Ymd'));

        $this->auditLogger->logCreate(
            'LibraryFramework',
            $id,
            ['framework_code' => $framework->getCode(), 'export_format' => 'yaml'],
            AuditLogger::ACTION_LIBRARY_FRAMEWORK_EXPORTED_YAML,
        );

        $response = new Response($yaml);
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename,
        );
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', 'application/yaml');

        return $response;
    }

    /**
     * Export a framework as CSV download.
     */
    #[Route('/export/{id}/csv', name: 'export_csv', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportCsv(int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if ($framework === null) {
            throw $this->createNotFoundException('Framework not found.');
        }

        $csv = $this->roundtripService->exportCsv($framework);
        $filename = sprintf('framework_%s_%s.csv', $framework->getCode(), date('Ymd'));

        $this->auditLogger->logCreate(
            'LibraryFramework',
            $id,
            ['framework_code' => $framework->getCode(), 'export_format' => 'csv'],
            AuditLogger::ACTION_LIBRARY_FRAMEWORK_EXPORTED_CSV,
        );

        $response = new Response($csv);
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename,
        );
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', 'text/csv');

        return $response;
    }

    /**
     * Discover available library YAML files in fixtures/library/frameworks/.
     *
     * @return list<array{filename: string, path: string, type: string, code: string, label: string}>
     */
    private function discoverAvailableLibraries(): array
    {
        $dir = $this->projectDir . '/fixtures/library/frameworks';
        if (!is_dir($dir)) {
            return [];
        }

        $libraries = [];
        foreach (glob($dir . '/*.yaml') as $path) {
            $filename = basename($path, '.yaml');

            if (str_contains($filename, 'tisax') || str_contains($filename, 'vda')) {
                $type = 'tisax';
                $label = 'TISAX VDA ISA v6.0';
                $code = 'TISAX-VDA-ISA-6';
            } elseif (str_contains($filename, 'bsi') && str_contains($filename, 'grundschutz')) {
                $type = 'bsi';
                $label = 'BSI IT-Grundschutz 2024';
                $code = 'BSI-GRUNDSCHUTZ-2024';
            } else {
                $type = 'unknown';
                $label = $filename;
                $code = strtoupper($filename);
            }

            $libraries[] = [
                'filename' => $filename,
                'path' => $path,
                'type' => $type,
                'code' => $code,
                'label' => $label,
            ];
        }

        return $libraries;
    }
}
