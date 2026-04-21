<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ComplianceFrameworkRepository;
use App\Service\Import\CrossFrameworkMappingImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sprint 5 / R5 — CSV-Import-UI für Cross-Framework-Mappings.
 *
 * Browser-Upload für consultant-gelieferte Mapping-CSVs.
 * Nutzt `CrossFrameworkMappingImporter` mit Dry-Run-Preview.
 *
 *  - GET  /compliance/mapping/import          → Formular
 *  - POST /compliance/mapping/import          → Preview (dry-run default)
 *  - POST /compliance/mapping/import?commit=1 → tatsächlicher Import
 */
#[IsGranted('ROLE_MANAGER')]
class ComplianceMappingImportController extends AbstractController
{
    private const MAX_CSV_BYTES = 2_000_000;

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly CrossFrameworkMappingImporter $importer,
    ) {
    }

    #[Route('/compliance/mapping/import', name: 'app_compliance_mapping_import', methods: ['GET', 'POST'])]
    public function import(Request $request): Response
    {
        $frameworks = $this->frameworkRepository->findBy(['active' => true], ['code' => 'ASC']);

        $result = null;
        $error = null;
        $sourceCode = (string) $request->request->get('source_framework_code', '');
        $targetCode = (string) $request->request->get('target_framework_code', '');
        $commit = $request->request->getBoolean('commit');

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token');
            if (!$this->isCsrfTokenValid('mapping_import', $token)) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            if ($sourceCode === '' || $targetCode === '') {
                $error = 'compliance.mapping.import.error.frameworks_required';
            } elseif ($sourceCode === $targetCode) {
                $error = 'compliance.mapping.import.error.same_framework';
            } else {
                $csv = $this->extractCsv($request, $error);
                if ($error === null && $csv !== null) {
                    $result = $this->importer->import(
                        $csv,
                        $sourceCode,
                        $targetCode,
                        $commit,
                        $commit ? 'csv_import_ui' : 'csv_import_ui_dryrun',
                    );

                    if ($commit && $result['created'] > 0) {
                        $this->addFlash('success', sprintf(
                            'CSV-Import: %d neue Mapping(s) angelegt, %d übersprungen.',
                            $result['created'],
                            $result['skipped_existing'] + $result['skipped_missing'],
                        ));
                    }
                }
            }
        }

        return $this->render('compliance/mapping/import.html.twig', [
            'frameworks' => $frameworks,
            'result' => $result,
            'error' => $error,
            'source_code' => $sourceCode,
            'target_code' => $targetCode,
            'was_commit' => $commit,
        ]);
    }

    private function extractCsv(Request $request, ?string &$error): ?string
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('csv_file');
        if (!$file instanceof UploadedFile) {
            $error = 'compliance.mapping.import.error.file_required';
            return null;
        }
        if (!$file->isValid()) {
            $error = 'compliance.mapping.import.error.file_invalid';
            return null;
        }
        if ($file->getSize() !== null && $file->getSize() > self::MAX_CSV_BYTES) {
            $error = 'compliance.mapping.import.error.file_too_large';
            return null;
        }

        $content = @file_get_contents($file->getPathname());
        if ($content === false || $content === '') {
            $error = 'compliance.mapping.import.error.file_empty';
            return null;
        }

        return $content;
    }
}
