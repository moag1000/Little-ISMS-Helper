<?php

namespace App\Controller;

use Exception;
use InvalidArgumentException;
use DateTimeInterface;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\BackupService;
use App\Service\RestoreService;
use App\Service\TenantContext;
use App\Repository\TenantRepository;
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

// Default class-level guard: SUPER_ADMIN for global operations.
// Individual endpoints may loosen this to ROLE_ADMIN for scoped (per-tenant) operations.
#[IsGranted('ROLE_ADMIN')]
class AdminBackupController extends AbstractController
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly RestoreService $restoreService,
        private readonly LoggerInterface $logger,
        private readonly TenantContext $tenantContext,
        private readonly TenantRepository $tenantRepository
    ) {
    }

    #[Route('/admin/data/backup', name: 'data_backup_index', methods: ['GET'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function index(): Response
    {
        $backups = $this->backupService->listBackups();

        return $this->render('data_management/backup.html.twig', [
            'backups' => $backups,
        ]);
    }

    #[Route('/admin/data/backup/create', name: 'data_backup_create', methods: ['POST'])]
    public function createBackup(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('data_backup_create', $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        try {
            $includeAuditLog = $request->request->getBoolean('include_audit_log', true);
            $includeUserSessions = $request->request->getBoolean('include_user_sessions', false);

            // Determine tenant scope from the request
            $tenantScope = $this->resolveTenantScopeForBackup($request);

            $this->logger->info('Creating backup', [
                'user'                 => $this->getUser()?->getUserIdentifier(),
                'include_audit_log'    => $includeAuditLog,
                'include_user_sessions' => $includeUserSessions,
                'tenant_scope'         => $tenantScope?->getId(),
            ]);

            // Create backup
            $backup = $this->backupService->createBackup($includeAuditLog, $includeUserSessions, true, $tenantScope);

            // Save to file
            $filepath = $this->backupService->saveBackupToFile($backup);

            return new JsonResponse([
                'success'    => true,
                'message'    => 'Backup erfolgreich erstellt',
                'filename'   => basename($filepath),
                'statistics' => $backup['statistics'],
                'scope_type' => $backup['metadata']['scope_type'],
            ]);
        } catch (Exception $e) {
            $this->logger->error('Backup creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Fehler beim Erstellen des Backups: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Resolve the tenant scope for a backup request.
     *
     * Access-Control rules:
     *  - SUPER_ADMIN: may request global backup (no tenant_id) or any specific tenant.
     *  - ADMIN (Tenant-Admin): may only backup their own tenant or subsidiaries.
     *    Any attempt to specify a tenant outside their tree is rejected with 403.
     *
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException
     */
    private function resolveTenantScopeForBackup(Request $request): ?Tenant
    {
        $tenantIdParam = $request->request->get('tenant_id');

        // No tenant_id requested → global backup (SUPER_ADMIN only)
        if ($tenantIdParam === null || $tenantIdParam === '' || $tenantIdParam === 'global') {
            if (!$this->isGranted('ROLE_SUPER_ADMIN')) {
                // Non-SUPER_ADMIN cannot create global backups — default to their own tenant
                $currentTenant = $this->tenantContext->getCurrentTenant();
                if ($currentTenant === null) {
                    throw $this->createAccessDeniedException(
                        'Kein Tenant-Kontext verfügbar. Bitte kontaktieren Sie einen Super-Administrator.'
                    );
                }
                return $currentTenant;
            }
            return null; // global
        }

        // Specific tenant requested
        $tenantId = (int) $tenantIdParam;
        $tenant = $this->tenantRepository->find($tenantId);
        if ($tenant === null) {
            throw $this->createNotFoundException('Tenant nicht gefunden: ' . $tenantId);
        }

        // SUPER_ADMIN may access any tenant
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $tenant;
        }

        // ADMIN may only access their own tenant tree
        if (!$this->tenantContext->canAccessTenant($tenant)) {
            throw $this->createAccessDeniedException(
                'Sie haben keine Berechtigung, ein Backup für diesen Mandanten zu erstellen.'
            );
        }

        return $tenant;
    }

    /**
     * Resolve the tenant scope for a restore request.
     *
     * Access-Control rules mirror resolveTenantScopeForBackup():
     *  - SUPER_ADMIN: may restore globally or to any tenant.
     *  - ADMIN: may only restore into their own tenant tree.
     */
    private function resolveTenantScopeForRestore(Request $request): ?Tenant
    {
        $tenantIdParam = $request->request->get('tenant_id');

        if ($tenantIdParam === null || $tenantIdParam === '' || $tenantIdParam === 'global') {
            if (!$this->isGranted('ROLE_SUPER_ADMIN')) {
                $currentTenant = $this->tenantContext->getCurrentTenant();
                if ($currentTenant === null) {
                    throw $this->createAccessDeniedException(
                        'Kein Tenant-Kontext verfügbar. Bitte kontaktieren Sie einen Super-Administrator.'
                    );
                }
                return $currentTenant;
            }
            return null; // global
        }

        $tenantId = (int) $tenantIdParam;
        $tenant = $this->tenantRepository->find($tenantId);
        if ($tenant === null) {
            throw $this->createNotFoundException('Tenant nicht gefunden: ' . $tenantId);
        }

        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return $tenant;
        }

        if (!$this->tenantContext->canAccessTenant($tenant)) {
            throw $this->createAccessDeniedException(
                'Sie haben keine Berechtigung, eine Wiederherstellung für diesen Mandanten durchzuführen.'
            );
        }

        return $tenant;
    }

    #[Route('/admin/data/backup/download/{filename}', name: 'data_backup_download', methods: ['GET'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
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
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function uploadBackup(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('data_backup_upload', $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        try {
            /** @var UploadedFile|null $file */
            $file = $request->files->get('backup_file');

            if (!$file) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Keine Datei hochgeladen',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate file extension (.zip added for format 2.0 backups with embedded files)
            $allowedExtensions = ['json', 'gz', 'zip'];
            $extension = $file->getClientOriginalExtension();

            if (!in_array($extension, $allowedExtensions)) {
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

            return new JsonResponse([
                'success' => true,
                'message' => 'Backup-Datei erfolgreich hochgeladen',
                'filename' => $filename,
            ]);
        } catch (FileException $e) {
            $this->logger->error('Backup upload failed', [
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Fehler beim Hochladen der Datei: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/admin/data/backup/validate/{filename}', name: 'data_backup_validate', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function validateBackup(string $filename, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('data_backup_validate', $request->request->get('_token'))) {
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

            // Load and validate backup
            $backup = $this->backupService->loadBackupFromFile($filepath);
            $validation = $this->restoreService->validateBackup($backup);

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
    #[IsGranted('ROLE_SUPER_ADMIN')]
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
    public function restoreBackup(string $filename, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('data_backup_restore', $request->request->get('_token'))) {
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

            // Resolve tenant scope for this restore operation
            $targetTenantScope = $this->resolveTenantScopeForRestore($request);

            // Get restore options from request
            $options = [
                'missing_field_strategy' => $request->request->get('missing_field_strategy', RestoreService::STRATEGY_USE_DEFAULT),
                'existing_data_strategy' => $request->request->get('existing_data_strategy', RestoreService::EXISTING_UPDATE),
                'skip_entities' => $request->request->all('skip_entities') ?? [],
                'dry_run' => $request->request->getBoolean('dry_run', false),
                'clear_before_restore' => $request->request->getBoolean('clear_before_restore', false),
                'admin_password' => $request->request->get('admin_password', ''),
            ];

            $this->logger->info('Starting restore', [
                'user'         => $this->getUser()?->getUserIdentifier(),
                'filename'     => $filename,
                'tenant_scope' => $targetTenantScope?->getId(),
                'options'      => array_diff_key($options, ['admin_password' => '']), // Don't log password
            ]);

            // Load backup
            $backup = $this->backupService->loadBackupFromFile($filepath);

            // Perform restore (tenant-scoped if applicable)
            $result = $this->restoreService->restoreFromBackup($backup, $options, $targetTenantScope);

            return new JsonResponse([
                'success'      => $result['success'],
                'message'      => $options['dry_run']
                    ? 'Testlauf erfolgreich abgeschlossen (keine Daten wurden geändert)'
                    : 'Wiederherstellung erfolgreich abgeschlossen',
                'statistics'   => $result['statistics'],
                'warnings'     => $result['warnings'],
                'dry_run'      => $result['dry_run'],
                'tenant_scope' => $targetTenantScope?->getId(),
            ]);
        } catch (Exception $e) {
            $this->logger->error('Restore failed', [
                'filename' => $filename,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Fehler bei der Wiederherstellung: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/admin/data/backup/delete/{filename}', name: 'data_backup_delete', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
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
                    fputcsv($handle, $entity, escape: '\\');
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
}
