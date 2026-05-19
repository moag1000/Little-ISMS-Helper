<?php

declare(strict_types=1);

namespace App\Controller;

use Exception;
use InvalidArgumentException;
use DateTimeInterface;
use App\Job\CreateBackupJob;
use App\Job\RestoreBackupJob;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\BackupService;
use App\Service\Job\JobDispatcher;
use App\Service\Job\JobStatusService;
use App\Service\RestoreService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Backup / Restore / Export / Import admin controller.
 *
 * Reference implementation of the Role-Scope Architecture (Phase 3, spec
 * `docs/superpowers/specs/2026-05-18-role-scope-architecture.md`).
 *
 * Authorization model:
 *  - Class-level `ROLE_ADMIN` is the baseline fence — anonymous / regular users
 *    cannot reach any endpoint here.
 *  - Tenant-scoped backup endpoints (`data_backup_create`, `data_backup_restore`,
 *    `data_backup_download`, `data_backup_delete`, `data_backup_validate`,
 *    `data_backup_preview`, `data_backup_upload`) use
 *    {@see TenantScopedAdminVoter::ADMIN_OWN_TENANT} so `ROLE_ADMIN`
 *    (Tenant-Admin) can operate inside their own tenant tree, and SUPER_ADMIN
 *    transparently across any tenant.
 *  - `data_backup_index` lists backups under the class-level `ROLE_ADMIN`
 *    baseline — tenant-scoped filtering of the listing is deferred to Phase 5
 *    (service-layer `BackupService::listBackups(?Tenant)` filter).
 *  - Generic data export / import endpoints currently rely on the class-level
 *    `ROLE_ADMIN` baseline as well; tenant-scope filtering of exported data is
 *    deferred to Phase 5.
 *
 * Cross-tenant attempts are rejected by
 * {@see TenantContext::resolveAdminScope()}, which throws
 * `AccessDeniedException` instead of the previous duplicated inline logic.
 */
#[IsGranted('ROLE_ADMIN')]
class AdminBackupController extends AbstractController
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly RestoreService $restoreService,
        private readonly LoggerInterface $logger,
        private readonly TenantContext $tenantContext,
        private readonly JobStatusService $jobStatusService,
        private readonly JobDispatcher $jobDispatcher,
    ) {
    }

    #[Route('/admin/data/backup', name: 'data_backup_index', methods: ['GET'])]
    public function index(): Response
    {
        // Phase 5: service-layer tenant filtering — SUPER_ADMIN sees all backups,
        // ROLE_ADMIN sees only backups whose embedded tenant_scope intersects with
        // their own tenant tree. Pass null for SUPER to bypass the filter.
        $scope = $this->isGranted('ROLE_SUPER_ADMIN')
            ? null
            : $this->tenantContext->resolveAdminScope(null);
        $backups = $this->backupService->listBackups($scope);

        return $this->render('data_management/backup.html.twig', [
            'backups' => $backups,
        ]);
    }

    #[Route('/admin/data/backup/create', name: 'data_backup_create', methods: ['POST'])]
    #[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
    public function createBackup(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('data_backup_create', $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        // Resolve tenant scope BEFORE dispatch so cross-tenant attempts
        // surface as Symfony's standard 403 instead of running for several
        // minutes in a worker only to fail at write-time.
        $tenantScope = $this->tenantContext->resolveAdminScope($request->request->get('tenant_id'));

        $includeAuditLog = $request->request->getBoolean('include_audit_log', true);
        $includeUserSessions = $request->request->getBoolean('include_user_sessions', false);

        $this->logger->info('Dispatching async backup job', [
            'user'                  => $this->getUser()?->getUserIdentifier(),
            'include_audit_log'     => $includeAuditLog,
            'include_user_sessions' => $includeUserSessions,
            'tenant_scope'          => $tenantScope?->getId(),
        ]);

        $jobId = $this->jobStatusService->create(
            'admin.backup.create',
            [
                'includeAuditLog'     => $includeAuditLog,
                'includeUserSessions' => $includeUserSessions,
                'tenantId'            => $tenantScope?->getId(),
            ],
        );

        $args = [
            'includeAuditLog'     => $includeAuditLog,
            'includeUserSessions' => $includeUserSessions,
            'tenantId'            => $tenantScope?->getId(),
        ];

        // Safety net: when the page's inline JS fails to attach the submit
        // listener (e.g. transient parse error in a third-party module),
        // the browser submits the form directly via the form's `action`. In
        // that case there's no JS to read `progressUrl` from a JsonResponse,
        // so we redirect to the progress page server-side instead. Detect
        // the AJAX path via `X-Requested-With` (set explicitly by the page
        // fetch() call) — XHR clients still receive the JsonResponse.
        //
        // Build the response BEFORE dispatch so InRequestJobRunner can flush
        // it and detach the connection before running the long backup job.
        if (!$request->isXmlHttpRequest()) {
            $response = $this->redirectToRoute('data_backup_progress', ['id' => $jobId]);
        } else {
            // Frontend JS reads `async` + `jobId` + `progressUrl` and redirects
            // to the progress page; legacy fields (`success`, `message`) are
            // kept for backward-compat with any third-party callers.
            $response = new JsonResponse([
                'success'      => true,
                'async'        => true,
                'message'      => 'Backup-Job gestartet — Fortschritt wird auf der Status-Seite angezeigt.',
                'jobId'        => $jobId,
                'progressUrl'  => $this->generateUrl('data_backup_progress', ['id' => $jobId]),
                'statusUrl'    => $this->generateUrl('admin_job_status', ['id' => $jobId]),
            ]);
        }

        return $this->jobDispatcher->dispatch(
            CreateBackupJob::class,
            $args,
            $jobId,
            $response,
            $request->getSession(),
        );
    }

    /**
     * Progress page for the async backup-create / restore jobs.
     *
     * Renders the shared {@see _async_job_progress.html.twig} partial,
     * which polls /admin/jobs/{id}/status every 3 seconds and surfaces
     * progress / success / failure to the operator.
     */
    #[Route('/admin/data/backup/progress/{id}', name: 'data_backup_progress', methods: ['GET'])]
    #[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
    public function backupProgress(string $id): Response
    {
        return $this->render('data_management/backup_progress.html.twig', [
            'jobId' => $id,
            'cancelUrl' => $this->generateUrl('data_backup_index'),
        ]);
    }

    #[Route('/admin/data/backup/download/{filename}', name: 'data_backup_download', methods: ['GET'])]
    #[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
    public function downloadBackup(string $filename): Response
    {
        // Validate filename to prevent directory traversal (accept both backup_ and uploaded_ files)
        // Supports .zip (format 2.0), .json.gz, .json, and bare .gz (legacy)
        if (!preg_match('/^(backup_|uploaded_)\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.(zip|json\.gz|json|gz)$/', $filename)) {
            throw $this->createNotFoundException('Invalid backup filename');
        }

        $backupDir = $this->getParameter('kernel.project_dir') . '/var/backups';
        $filepath = $backupDir . '/' . $filename;

        if (!file_exists($filepath)) {
            throw $this->createNotFoundException('Backup file not found');
        }

        $streamedResponse = new StreamedResponse(function () use ($filepath): void {
            $outputStream = fopen('php://output', 'wb');
            $fileStream = fopen($filepath, 'rb');
            stream_copy_to_stream($fileStream, $outputStream);
            fclose($fileStream);
            fclose($outputStream);
        });

        // Set content type based on file extension
        $contentType = match (true) {
            str_ends_with($filename, '.zip') => 'application/zip',
            str_ends_with($filename, '.gz')  => 'application/gzip',
            default                          => 'application/json',
        };
        $streamedResponse->headers->set('Content-Type', $contentType);
        $streamedResponse->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $streamedResponse->headers->set('Content-Length', (string) filesize($filepath));

        return $streamedResponse;
    }

    #[Route('/admin/data/backup/upload', name: 'data_backup_upload', methods: ['POST'])]
    #[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
    public function uploadBackup(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('data_backup_upload', $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $isXhr = $request->isXmlHttpRequest();

        try {
            /** @var UploadedFile|null $file */
            $file = $request->files->get('backup_file');

            if (!$file) {
                if (!$isXhr) {
                    $this->addFlash('error', 'Keine Datei hochgeladen');

                    return $this->redirectToRoute('data_backup_index');
                }

                return new JsonResponse([
                    'success' => false,
                    'message' => 'Keine Datei hochgeladen',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate file extension (.zip added for format 2.0 backups with embedded files)
            $allowedExtensions = ['json', 'gz', 'zip'];
            $extension = $file->getClientOriginalExtension();

            if (!in_array($extension, $allowedExtensions)) {
                if (!$isXhr) {
                    $this->addFlash('error', 'Ungültiges Dateiformat. Nur .json, .gz oder .zip Dateien sind erlaubt.');

                    return $this->redirectToRoute('data_backup_index');
                }

                return new JsonResponse([
                    'success' => false,
                    'message' => 'Ungültiges Dateiformat. Nur .json, .gz oder .zip Dateien sind erlaubt.',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Move file to backups directory
            $backupDir = $this->getParameter('kernel.project_dir') . '/var/backups';
            $filename = 'uploaded_' . date('Y-m-d_H-i-s') . '.' . $file->getClientOriginalExtension();

            $file->move($backupDir, $filename);

            $this->logger->info('Backup file uploaded', [
                'user' => $this->getUser()?->getUserIdentifier(),
                'filename' => $filename,
            ]);

            // Safety net: see createBackup() comment above. Non-XHR submits
            // (JS failed to attach the submit listener) redirect to the
            // backup index instead of returning a JsonResponse the browser
            // would render as raw text.
            if (!$isXhr) {
                $this->addFlash('success', 'Backup-Datei erfolgreich hochgeladen: ' . $filename);

                return $this->redirectToRoute('data_backup_index');
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Backup-Datei erfolgreich hochgeladen',
                'filename' => $filename,
            ]);
        } catch (FileException $e) {
            $this->logger->error('Backup upload failed', [
                'error' => $e->getMessage(),
            ]);

            if (!$isXhr) {
                $this->addFlash('error', 'Fehler beim Hochladen der Datei: ' . $e->getMessage());

                return $this->redirectToRoute('data_backup_index');
            }

            return new JsonResponse([
                'success' => false,
                'message' => 'Fehler beim Hochladen der Datei: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/admin/data/backup/validate/{filename}', name: 'data_backup_validate', methods: ['POST'])]
    #[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
    public function validateBackup(string $filename, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('data_backup_validate', $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        // Phase 5: resolve caller scope so validateBackup() can reject cross-tenant
        // attempts with AccessDeniedException (Symfony maps to 403).
        $callerScope = $this->tenantContext->resolveAdminScope($request->request->get('tenant_id'));

        try {
            // Validate filename
            if (!preg_match('/^(backup_|uploaded_).+\.(json|gz)$/', $filename)) {
                throw new InvalidArgumentException('Invalid backup filename');
            }

            $backupDir = $this->getParameter('kernel.project_dir') . '/var/backups';
            $filepath = $backupDir . '/' . $filename;

            if (!file_exists($filepath)) {
                throw new InvalidArgumentException('Backup file not found');
            }

            // Load and validate backup (with tenant-scope rejection for non-SUPER callers)
            $backup = $this->backupService->loadBackupFromFile($filepath);
            $validation = $this->restoreService->validateBackup($backup, $callerScope);

            return new JsonResponse([
                'success' => $validation['valid'],
                'valid' => $validation['valid'],
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
                'metadata' => $backup['metadata'] ?? [],
            ]);
        } catch (Exception $e) {
            $this->logger->error('Backup validation failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Fehler bei der Validierung: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/admin/data/backup/preview/{filename}', name: 'data_backup_preview', methods: ['GET'])]
    #[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
    public function previewRestore(string $filename): JsonResponse
    {
        try {
            // Validate filename
            if (!preg_match('/^(backup_|uploaded_).+\.(json|gz)$/', $filename)) {
                throw new InvalidArgumentException('Invalid backup filename');
            }

            $backupDir = $this->getParameter('kernel.project_dir') . '/var/backups';
            $filepath = $backupDir . '/' . $filename;

            if (!file_exists($filepath)) {
                throw new InvalidArgumentException('Backup file not found');
            }

            // Load backup and get preview
            $backup = $this->backupService->loadBackupFromFile($filepath);
            $preview = $this->restoreService->getRestorePreview($backup);

            return new JsonResponse([
                'success' => true,
                'preview' => $preview,
            ]);
        } catch (Exception $e) {
            $this->logger->error('Backup preview failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Fehler beim Laden der Vorschau: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/admin/data/backup/restore/{filename}', name: 'data_backup_restore', methods: ['POST'])]
    #[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
    public function restoreBackup(string $filename, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('data_backup_restore', $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        // Resolve tenant scope BEFORE dispatch so a cross-tenant restore
        // attempt by ROLE_ADMIN surfaces as Symfony's standard 403 instead of
        // running for several minutes in a worker only to fail at flush-time.
        $targetTenantScope = $this->tenantContext->resolveAdminScope($request->request->get('tenant_id'));

        if (!preg_match('/^(backup_|uploaded_).+\.(json|gz)$/', $filename)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid backup filename',
            ], Response::HTTP_BAD_REQUEST);
        }

        $backupDir = $this->getParameter('kernel.project_dir') . '/var/backups';
        $filepath = $backupDir . '/' . $filename;

        if (!file_exists($filepath)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Backup file not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $options = [
            'missing_field_strategy' => $request->request->get('missing_field_strategy', RestoreService::STRATEGY_USE_DEFAULT),
            'existing_data_strategy' => $request->request->get('existing_data_strategy', RestoreService::EXISTING_UPDATE),
            'skip_entities' => $request->request->all('skip_entities') ?? [],
            'dry_run' => $request->request->getBoolean('dry_run', false),
            'clear_before_restore' => $request->request->getBoolean('clear_before_restore', false),
            'best_effort' => $request->request->getBoolean('best_effort', false),
            'admin_password' => $request->request->get('admin_password', ''),
        ];

        $this->logger->info('Dispatching async restore job', [
            'user'         => $this->getUser()?->getUserIdentifier(),
            'filename'     => $filename,
            'tenant_scope' => $targetTenantScope?->getId(),
            'options'      => array_diff_key($options, ['admin_password' => '']), // Don't log password
        ]);

        $jobId = $this->jobStatusService->create(
            'admin.backup.restore',
            [
                'filename'       => $filename,
                'tenantId'       => $targetTenantScope?->getId(),
                'dry_run'        => $options['dry_run'],
                // admin_password intentionally NOT persisted to the status payload
            ],
        );

        $args = [
            'filepath'        => $filepath,
            'options'         => $options,
            'targetTenantId'  => $targetTenantScope?->getId(),
        ];

        // Build the JSON envelope BEFORE dispatch — the in-request runner
        // flushes it to the browser then keeps running the restore in this
        // same PHP-FPM worker.
        $response = new JsonResponse([
            'success'      => true,
            'async'        => true,
            'message'      => $options['dry_run']
                ? 'Test-Restore-Job gestartet — Fortschritt wird auf der Status-Seite angezeigt.'
                : 'Restore-Job gestartet — Fortschritt wird auf der Status-Seite angezeigt.',
            'jobId'        => $jobId,
            'progressUrl'  => $this->generateUrl('data_backup_progress', ['id' => $jobId]),
            'statusUrl'    => $this->generateUrl('admin_job_status', ['id' => $jobId]),
            'tenant_scope' => $targetTenantScope?->getId(),
            'dry_run'      => $options['dry_run'],
        ]);

        return $this->jobDispatcher->dispatch(
            RestoreBackupJob::class,
            $args,
            $jobId,
            $response,
            $request->getSession(),
        );
    }

    #[Route('/admin/data/backup/delete/{filename}', name: 'data_backup_delete', methods: ['POST', 'DELETE'])]
    #[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
    public function deleteBackup(string $filename, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('data_backup_delete', $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        try {
            // Validate filename
            if (!preg_match('/^(backup_|uploaded_).+\.(json|gz)$/', $filename)) {
                throw new InvalidArgumentException('Invalid backup filename');
            }

            $backupDir = $this->getParameter('kernel.project_dir') . '/var/backups';
            $filepath = $backupDir . '/' . $filename;

            if (!file_exists($filepath)) {
                throw new InvalidArgumentException('Backup file not found');
            }

            unlink($filepath);

            $this->logger->info('Backup deleted', [
                'user' => $this->getUser()?->getUserIdentifier(),
                'filename' => $filename,
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Backup erfolgreich gelöscht',
            ]);
        } catch (Exception $e) {
            $this->logger->error('Backup deletion failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Fehler beim Löschen: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/admin/data/export', name: 'data_export_index', methods: ['GET'])]
    public function exportIndex(EntityManagerInterface $entityManager): Response
    {
        // Get all entity class names
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $entities = [];

        foreach ($metadata as $meta) {
            $className = $meta->getName();
            $shortName = substr($className, strrpos($className, '\\') + 1);

            $entities[] = [
                'name' => $shortName,
                'class' => $className,
                'table' => $meta->getTableName(),
            ];
        }

        // Sort by name
        usort($entities, fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $this->render('data_management/export.html.twig', [
            'entities' => $entities,
        ]);
    }

    #[Route('/admin/data/export/execute', name: 'data_export_execute', methods: ['POST'])]
    public function exportExecute(
        Request $request,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator
    ): Response {
        if (!$this->isCsrfTokenValid('data_export', $request->request->get('_token'))) {
            $this->addFlash('error', $translator->trans('data.export.error.invalid_token'));
            return $this->redirectToRoute('data_export_index');
        }

        $selectedEntities = $request->request->all('entities') ?? [];
        $format = $request->request->get('format', 'json');

        if ($selectedEntities === []) {
            $this->addFlash('error', $translator->trans('data.export.error.no_entities'));
            return $this->redirectToRoute('data_export_index');
        }

        // Close session to prevent blocking other requests during export generation
        $request->getSession()->save();

        $exportData = [];

        foreach ($selectedEntities as $selectedEntity) {
            // Security: Only allow App\Entity namespace
            if (!str_starts_with((string) $selectedEntity, 'App\\Entity\\')) {
                continue;
            }

            try {
                $repository = $entityManager->getRepository($selectedEntity);
                $entities = $repository->findAll();

                $shortName = substr((string) $selectedEntity, strrpos((string) $selectedEntity, '\\') + 1);
                $exportData[$shortName] = [];

                foreach ($entities as $entity) {
                    // Convert entity to array (simplified)
                    $exportData[$shortName][] = $this->entityToArray($entity, $entityManager);
                }
            } catch (Exception) {
                // Skip entities that can't be exported
                continue;
            }
        }

        // Return response based on format
        if ($format === 'json') {
            return $this->createJsonExportResponse($exportData);
        }
        return $this->createCsvExportResponse($exportData);
    }

    #[Route('/admin/data/import', name: 'data_import_index', methods: ['GET'])]
    public function importIndex(): Response
    {
        return $this->render('data_management/import.html.twig');
    }

    #[Route('/admin/data/import/upload', name: 'data_import_upload', methods: ['POST'])]
    public function importUpload(
        Request $request,
        TranslatorInterface $translator
    ): Response {
        if (!$this->isCsrfTokenValid('data_import', $request->request->get('_token'))) {
            $this->addFlash('error', $translator->trans('data.import.error.invalid_token'));
            return $this->redirectToRoute('data_import_index');
        }

        $file = $request->files->get('import_file');

        if (!$file) {
            $this->addFlash('error', $translator->trans('data.import.error.no_file'));
            return $this->redirectToRoute('data_import_index');
        }

        try {
            $content = file_get_contents($file->getPathname());
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON: ' . json_last_error_msg());
            }

            // Store data in session for preview
            $request->getSession()->set('import_preview_data', $data);

            return $this->redirectToRoute('data_import_preview');
        } catch (Exception $e) {
            $this->addFlash('error', $translator->trans('data.import.error.invalid_file', [
                'error' => $e->getMessage(),
            ]));
            return $this->redirectToRoute('data_import_index');
        }
    }

    #[Route('/admin/data/import/preview', name: 'data_import_preview', methods: ['GET'])]
    public function importPreview(Request $request): Response
    {
        $data = $request->getSession()->get('import_preview_data');

        if (!$data) {
            $this->addFlash('error', 'No import data found');
            return $this->redirectToRoute('data_import_index');
        }

        $stats = [];
        foreach ($data as $entityName => $entities) {
            $stats[$entityName] = count($entities);
        }

        return $this->render('data_management/import_preview.html.twig', [
            'stats' => $stats,
            'data' => $data,
        ]);
    }

    #[Route('/admin/data/import/execute', name: 'data_import_execute', methods: ['POST'])]
    public function importExecute(
        Request $request,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator
    ): Response {
        if (!$this->isCsrfTokenValid('data_import_execute', $request->request->get('_token'))) {
            $this->addFlash('error', $translator->trans('data.import.error.invalid_token'));
            return $this->redirectToRoute('data_import_index');
        }

        $data = $request->getSession()->get('import_preview_data');

        if (!$data) {
            $this->addFlash('error', 'No import data found');
            return $this->redirectToRoute('data_import_index');
        }

        // NOTE: This is a simplified implementation
        // In production, you'd need proper entity creation, validation, and relationship handling

        $this->addFlash('warning', $translator->trans('data.import.warning.not_implemented'));
        $request->getSession()->remove('import_preview_data');

        return $this->redirectToRoute('data_import_index');
    }

    /**
     * Convert entity to array (simplified)
     */
    private function entityToArray(object $entity, EntityManagerInterface $entityManager): array
    {
        $classMetadata = $entityManager->getClassMetadata($entity::class);
        $data = [];

        foreach ($classMetadata->getFieldNames() as $field) {
            try {
                $value = $classMetadata->getFieldValue($entity, $field);

                // Handle DateTime objects
                if ($value instanceof DateTimeInterface) {
                    $value = $value->format('Y-m-d H:i:s');
                }

                $data[$field] = $value;
            } catch (Exception) {
                // Skip fields that can't be accessed
            }
        }

        return $data;
    }

    /**
     * Create JSON export response
     */
    private function createJsonExportResponse(array $data): Response
    {
        $response = new Response(json_encode($data, JSON_PRETTY_PRINT));
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition',
            'attachment; filename="export_' . date('Y-m-d_H-i-s') . '.json"');

        return $response;
    }

    /**
     * Create CSV export response
     */
    private function createCsvExportResponse(array $data): StreamedResponse
    {
        $streamedResponse = new StreamedResponse(function() use ($data): void {
            $handle = fopen('php://output', 'w');

            foreach ($data as $entityName => $entities) {
                if (empty($entities)) {
                    continue;
                }

                // Write entity header
                fputcsv($handle, ['# ' . $entityName], escape: '\\');

                // Write column headers
                $headers = array_keys($entities[0]);
                fputcsv($handle, $headers, escape: '\\');

                // Write data
                foreach ($entities as $entity) {
                    fputcsv($handle, array_map([$this, 'sanitizeCsvValue'], $entity), escape: '\\');
                }

                // Empty line between entities
                fputcsv($handle, [], escape: '\\');
            }

            fclose($handle);
        });

        $streamedResponse->headers->set('Content-Type', 'text/csv');
        $streamedResponse->headers->set('Content-Disposition',
            'attachment; filename="export_' . date('Y-m-d_H-i-s') . '.csv"');

        return $streamedResponse;
    }

    /**
     * Sanitize a CSV cell value to prevent formula injection (OWASP - Injection).
     * Prefixes values starting with =, +, -, @, TAB or CR with a single quote.
     */
    private function sanitizeCsvValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
