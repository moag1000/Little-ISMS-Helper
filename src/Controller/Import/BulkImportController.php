<?php

declare(strict_types=1);

namespace App\Controller\Import;

use App\Entity\BulkImportBatch;
use App\Entity\User;
use App\Form\Import\ColumnMappingType;
use App\Form\Import\PreviewConfirmType;
use App\Form\Import\UploadStepType;
use App\Repository\BulkImportBatchRepository;
use App\Repository\BulkImportRowRepository;
use App\Security\Voter\BulkImportVoter;
use App\Service\Import\BulkImportOrchestrator;
use App\Service\Import\EntityMapperRegistry;
use App\Service\Import\HeaderHeuristicMapper;
use App\Service\Import\SpreadsheetParser;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * BulkImportController — 4-step import wizard for Asset / Supplier / Control.
 *
 * Route pattern: /{_locale}/import/{entityType}
 *
 * Steps:
 *   1. index   — wizard entry + recent-batches list
 *   2. upload  — file upload + entity-type + mode selection
 *   3. map     — column-to-field mapping (auto-suggested via HeaderHeuristicMapper)
 *   4. preview — delta summary + confirm form
 *   5. commit  — dispatch async commit via Messenger
 *   6. diff    — result view with counters + error table
 *   7. errorCsv— streamed CSV of error rows for download
 */
#[Route('/import/{entityType}', requirements: ['entityType' => 'asset|supplier|control|risk|business_process'])]
#[IsGranted('ROLE_MANAGER')]
final class BulkImportController extends AbstractController
{
    public function __construct(
        private readonly BulkImportOrchestrator $orchestrator,
        private readonly EntityMapperRegistry $mapperRegistry,
        private readonly TenantContext $tenantContext,
        private readonly BulkImportBatchRepository $batchRepo,
        private readonly BulkImportRowRepository $rowRepo,
        private readonly HeaderHeuristicMapper $headerMapper,
        private readonly SpreadsheetParser $parser,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    // -------------------------------------------------------------------------
    // Step 1: Wizard Entry
    // -------------------------------------------------------------------------

    #[Route('/', name: 'app_bulk_import_index', methods: ['GET'])]
    public function index(string $entityType): Response
    {
        $this->denyAccessUnlessGranted(BulkImportVoter::BULK_IMPORT_TRIGGER, null);

        $tenant          = $this->tenantContext->getCurrentTenant();
        $entityTypePascal = $this->toPascalCase($entityType);

        $recentBatches = $tenant !== null
            ? $this->batchRepo->findByEntityTypeForTenant($entityTypePascal, $tenant)
            : [];

        return $this->render('import/index.html.twig', [
            'entityType'      => $entityType,
            'entityTypePascal' => $entityTypePascal,
            'recentBatches'   => $recentBatches,
            'supportedTypes'  => $this->mapperRegistry->getSupportedEntityTypes(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 2: File Upload
    // -------------------------------------------------------------------------

    #[Route('/upload', name: 'app_bulk_import_upload', methods: ['GET', 'POST'])]
    public function upload(string $entityType, Request $request): Response
    {
        $this->denyAccessUnlessGranted(BulkImportVoter::BULK_IMPORT_TRIGGER, null);

        $entityTypePascal = $this->toPascalCase($entityType);

        $form = $this->createForm(UploadStepType::class, null, [
            'entity_types' => $this->mapperRegistry->getSupportedEntityTypes(),
        ]);

        // Pre-fill entityType from URL param so the form shows the right selection
        $form->get('entityType')->setData($entityTypePascal);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $file   = $form->get('file')->getData();
            $mode   = $form->get('mode')->getData();
            $tenant = $this->tenantContext->getCurrentTenant();
            $user   = $this->security->getUser();

            /** @var User|null $userEntity */
            $userEntity = $user instanceof User ? $user : null;

            try {
                $batch = $this->orchestrator->upload(
                    $file,
                    $entityTypePascal,
                    $tenant,
                    $userEntity,
                    $mode ?? BulkImportBatch::MODE_INITIAL,
                );

                return $this->redirectToRoute('app_bulk_import_map', [
                    '_locale'    => $request->getLocale(),
                    'entityType' => $entityType,
                    'batchId'    => $batch->getId(),
                ]);
            } catch (\Throwable $e) {
                $this->addFlash('danger', $this->translator->trans(
                    'import.error.upload_failed',
                    ['%message%' => $e->getMessage()],
                    'data_import',
                ));
            }
        }

        return $this->render('import/wizard/upload.html.twig', [
            'form'            => $form,
            'entityType'      => $entityType,
            'entityTypePascal' => $entityTypePascal,
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 3: Column Mapping
    // -------------------------------------------------------------------------

    #[Route('/{batchId}/map', name: 'app_bulk_import_map', requirements: ['batchId' => '\d+'], methods: ['GET', 'POST'])]
    public function map(string $entityType, int $batchId, Request $request): Response
    {
        $batch = $this->findBatchOr404($batchId);
        $this->denyAccessUnlessGranted(BulkImportVoter::VIEW, $batch);
        $this->denyAccessUnlessGranted(BulkImportVoter::EDIT, $batch);

        $entityTypePascal = $this->toPascalCase($entityType);

        // Retrieve auto-suggested mappings via the orchestrator's preview (read-only parse).
        // We re-parse the spreadsheet to obtain headers and auto-mappings.
        try {
            $storedPath  = $this->resolveFilePath($batch);
            $parsed      = $this->parser->parse($storedPath);
            $autoMappings = $this->headerMapper->suggestMappings($parsed->headers, $entityTypePascal);
        } catch (\Throwable $e) {
            $this->addFlash('danger', $this->translator->trans(
                'import.error.parse_failed',
                ['%message%' => $e->getMessage()],
                'data_import',
            ));

            return $this->redirectToRoute('app_bulk_import_index', [
                '_locale'    => $request->getLocale(),
                'entityType' => $entityType,
            ]);
        }

        // Derive entity fields: collect unique target-field values from auto-mappings.
        // Since EntityMapperInterface does not expose getSupportedFields(), we derive
        // the known fields from the heuristic mapper's alias table by passing a broad
        // set of candidate headers and collecting unique targets.
        $entityFields = $this->resolveEntityFields($entityTypePascal, $autoMappings);

        $form = $this->createForm(ColumnMappingType::class, null, [
            'headers'       => $parsed->headers,
            'entity_fields' => $entityFields,
            'auto_mappings' => $autoMappings,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Build userColumnMapping: {header => entityField}, skipping empty-string (ignore) values
            $formData         = $form->getData();
            $userColumnMapping = [];

            foreach ($parsed->headers as $index => $header) {
                $fieldName  = 'column_' . $index;
                $fieldValue = $formData[$fieldName] ?? '';

                if ($fieldValue !== '' && $fieldValue !== null) {
                    $userColumnMapping[$header] = $fieldValue;
                }
            }

            try {
                $this->orchestrator->preview($batch, $userColumnMapping);

                return $this->redirectToRoute('app_bulk_import_preview', [
                    '_locale'    => $request->getLocale(),
                    'entityType' => $entityType,
                    'batchId'    => $batchId,
                ]);
            } catch (\Throwable $e) {
                $this->addFlash('danger', $this->translator->trans(
                    'import.error.parse_failed',
                    ['%message%' => $e->getMessage()],
                    'data_import',
                ));
            }
        }

        return $this->render('import/wizard/map.html.twig', [
            'form'            => $form,
            'batch'           => $batch,
            'entityType'      => $entityType,
            'entityTypePascal' => $entityTypePascal,
            'autoMappings'    => $autoMappings,
            'headers'         => $parsed->headers,
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 4: Preview & Confirm
    // -------------------------------------------------------------------------

    #[Route('/{batchId}/preview', name: 'app_bulk_import_preview', requirements: ['batchId' => '\d+'], methods: ['GET'])]
    public function preview(string $entityType, int $batchId): Response
    {
        $batch = $this->findBatchOr404($batchId);
        $this->denyAccessUnlessGranted(BulkImportVoter::VIEW, $batch);

        $entityTypePascal = $this->toPascalCase($entityType);

        // Re-run preview without changing mapping (uses persisted columnMapping)
        $deltaResult = $this->orchestrator->preview($batch, $batch->getColumnMapping());

        $confirmForm = $this->createForm(PreviewConfirmType::class, null);
        $confirmForm->get('batchId')->setData($batchId);

        return $this->render('import/wizard/preview.html.twig', [
            'batch'           => $batch,
            'entityType'      => $entityType,
            'entityTypePascal' => $entityTypePascal,
            'deltaResult'     => $deltaResult,
            'confirmForm'     => $confirmForm,
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 5: Commit (async dispatch)
    // -------------------------------------------------------------------------

    #[Route('/{batchId}/commit', name: 'app_bulk_import_commit', requirements: ['batchId' => '\d+'], methods: ['POST'])]
    public function commit(string $entityType, int $batchId, Request $request): Response
    {
        $batch = $this->findBatchOr404($batchId);
        $this->denyAccessUnlessGranted(BulkImportVoter::COMMIT, $batch);

        $confirmForm = $this->createForm(PreviewConfirmType::class, null);
        $confirmForm->handleRequest($request);

        if ($confirmForm->isSubmitted() && $confirmForm->isValid()) {
            $this->orchestrator->dispatchCommit($batch);

            $this->addFlash('success', $this->translator->trans(
                'import.success.dispatched',
                [],
                'data_import',
            ));

            return $this->redirectToRoute('app_bulk_import_diff', [
                '_locale'    => $request->getLocale(),
                'entityType' => $entityType,
                'batchId'    => $batchId,
            ]);
        }

        // Form validation failed — re-render preview with errors
        $deltaResult = $this->orchestrator->preview($batch, $batch->getColumnMapping());

        return $this->render('import/wizard/preview.html.twig', [
            'batch'           => $batch,
            'entityType'      => $entityType,
            'entityTypePascal' => $this->toPascalCase($entityType),
            'deltaResult'     => $deltaResult,
            'confirmForm'     => $confirmForm,
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 6: Diff / Result View
    // -------------------------------------------------------------------------

    #[Route('/{batchId}/diff', name: 'app_bulk_import_diff', requirements: ['batchId' => '\d+'], methods: ['GET'])]
    public function diff(string $entityType, int $batchId): Response
    {
        $batch = $this->findBatchOr404($batchId);
        $this->denyAccessUnlessGranted(BulkImportVoter::VIEW, $batch);

        $errorRows = $this->rowRepo->findErrorsByBatch($batch);

        return $this->render('import/wizard/diff.html.twig', [
            'batch'           => $batch,
            'entityType'      => $entityType,
            'entityTypePascal' => $this->toPascalCase($entityType),
            'errorRows'       => $errorRows,
            'isCommitting'    => $batch->getStatus() === BulkImportBatch::STATUS_COMMITTING,
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 7: Error CSV Download
    // -------------------------------------------------------------------------

    #[Route('/{batchId}/error-csv', name: 'app_bulk_import_error_csv', requirements: ['batchId' => '\d+'], methods: ['GET'])]
    public function errorCsv(string $entityType, int $batchId): StreamedResponse
    {
        $batch = $this->findBatchOr404($batchId);
        $this->denyAccessUnlessGranted(BulkImportVoter::VIEW, $batch);

        $errorRows = $this->rowRepo->findErrorsByBatch($batch);
        $filename  = sprintf('import-errors-%d.csv', $batchId);

        $response = new StreamedResponse(function () use ($errorRows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // UTF-8 BOM for Excel compatibility
            fwrite($handle, "\xEF\xBB\xBF");

            // Header row
            fputcsv($handle, ['rowNumber', 'parsedData', 'errorMessage'], ';', '"', '\\');

            foreach ($errorRows as $row) {
                fputcsv($handle, [
                    $row->getRowNumber(),
                    json_encode($row->getParsedData(), JSON_UNESCAPED_UNICODE),
                    $row->getErrorMessage() ?? '',
                ], ';', '"', '\\');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="%s"',
            $filename,
        ));

        return $response;
    }

    /**
     * Stream the sample-XLSX fixture for the current entity-type as a download.
     * Helps users build their first Excel without guessing column headers.
     * The fixture-files live in fixtures/sample-imports/ (committed via F2.11).
     */
    #[Route('/sample.xlsx', name: 'app_bulk_import_sample', methods: ['GET'])]
    public function sample(string $entityType): Response
    {
        $entityTypeLower = strtolower($entityType);
        $fileBaseName = match ($entityTypeLower) {
            'asset'            => 'assets-sample.xlsx',
            'supplier'         => 'suppliers-sample.xlsx',
            'control'          => 'controls-sample.xlsx',
            'risk'             => 'risks-sample.xlsx',
            'business_process' => 'business-processes-sample.xlsx',
            default            => throw $this->createNotFoundException('Unsupported entity-type: ' . $entityType),
        };

        $samplePath = sprintf('%s/fixtures/sample-imports/%s', $this->projectDir, $fileBaseName);

        if (!is_file($samplePath) || !is_readable($samplePath)) {
            throw $this->createNotFoundException('Sample fixture missing: ' . $fileBaseName);
        }

        return $this->file($samplePath, $fileBaseName);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Normalize entity type URL-slug to PascalCase for service calls.
     * Examples: 'asset' → 'Asset', 'supplier' → 'Supplier',
     *           'business_process' → 'BusinessProcess' (snake_case handled)
     */
    private function toPascalCase(string $entityType): string
    {
        // Handle snake_case slugs: 'business_process' → 'BusinessProcess'
        $parts = explode('_', $entityType);

        return implode('', array_map('ucfirst', $parts));
    }

    /**
     * Load a BulkImportBatch by its primary-key ID, throwing a 404 if absent.
     */
    private function findBatchOr404(int $batchId): BulkImportBatch
    {
        $batch = $this->batchRepo->find($batchId);

        if ($batch === null) {
            throw $this->createNotFoundException(sprintf(
                'BulkImportBatch #%d not found.',
                $batchId,
            ));
        }

        return $batch;
    }

    /**
     * Derive the list of known entity fields for a given entity type.
     *
     * Since EntityMapperInterface does not expose a getSupportedFields() method,
     * we collect unique target-field values from:
     *   1. Auto-mapping suggestions already computed from the spreadsheet headers
     *   2. A broad set of candidate alias headers covering all known fields per type
     *
     * @param array<string, array{target: string, confidence: float}> $alreadyMapped
     * @return list<string>
     */
    private function resolveEntityFields(string $entityTypePascal, array $alreadyMapped): array
    {
        // Known canonical alias terms per entity type — we use lowercase entity type
        // to match HeaderHeuristicMapper's ALIASES keys
        $canonicalTerms = match (strtolower($entityTypePascal)) {
            'asset'           => ['name', 'designation', 'type', 'assettype', 'owner', 'responsible',
                                  'classification', 'confidentiality', 'integrity', 'availability', 'description'],
            'supplier'        => ['name', 'supplier', 'contactemail', 'email', 'criticality', 'dorarelevant'],
            'control'         => ['identifier', 'ref', 'title', 'name', 'applicability', 'justification'],
            'risk'            => ['name', 'title', 'category', 'threat', 'vulnerability', 'impact',
                                  'likelihood', 'treatmentstrategy', 'riskowner', 'dpia'],
            'businessprocess' => ['name', 'criticality', 'rto', 'rpo', 'mtpd', 'processowner', 'description',
                                  'financialimpact'],
            default           => [],
        };

        // Collect unique field names from auto-mappings + canonical suggestions
        $fields = [];
        foreach ($alreadyMapped as $suggestion) {
            $fields[$suggestion['target']] = true;
        }

        // Fill remaining fields via suggestMappings on canonical terms
        if ($canonicalTerms !== []) {
            $extra = $this->headerMapper->suggestMappings($canonicalTerms, strtolower($entityTypePascal));
            foreach ($extra as $suggestion) {
                $fields[$suggestion['target']] = true;
            }
        }

        return array_keys($fields);
    }

    /**
     * Resolve the stored file path for a batch by scanning the upload directory.
     *
     * Delegates to the orchestrator's internal logic by triggering a preview parse.
     * This is a best-effort approach that leverages the file-hash lookup already
     * implemented in BulkImportOrchestrator::resolveStoredFilePath().
     *
     * @throws \RuntimeException when the file cannot be located
     */
    private function resolveFilePath(BulkImportBatch $batch): string
    {
        // The upload dir is configured as a named binding for BulkImportOrchestrator
        // in services.yaml. We reconstruct the same path here to access the stored file.
        $uploadDir = $this->projectDir . '/var/import-uploads';

        if (!is_dir($uploadDir)) {
            throw new \RuntimeException(sprintf('Upload directory not found: %s', $uploadDir));
        }

        $fileHash = $batch->getSourceFileHash();
        $iterator = new \DirectoryIterator($uploadDir);

        foreach ($iterator as $file) {
            if ($file->isFile() && hash_file('sha256', $file->getPathname()) === $fileHash) {
                return $file->getPathname();
            }
        }

        throw new \RuntimeException(sprintf(
            'Import source file not found for batch #%d (hash: %s).',
            $batch->getId(),
            $fileHash,
        ));
    }
}
