<?php

namespace App\Controller;

use App\Service\BackupService;
use App\Service\RestoreService;
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

#[Route('/admin/data')]
#[IsGranted('ROLE_ADMIN')]
class AdminBackupController extends AbstractController
{
    public function __construct(
        private BackupService $backupService,
        private RestoreService $restoreService,
        private LoggerInterface $logger
    ) {
    }

    #[Route('/backup', name: 'data_backup_index', methods: ['GET'])]
    public function index(): Response
    {
        $backups = $this->backupService->listBackups();

        return $this->render('data_management/backup.html.twig', [
            'backups' => $backups,
        ]);
    }

    #[Route('/backup/create', name: 'data_backup_create', methods: ['POST'])]
    public function createBackup(Request $request): JsonResponse
    {
        try {
            $includeAuditLog = $request->request->getBoolean('include_audit_log', true);
            $includeUserSessions = $request->request->getBoolean('include_user_sessions', false);

            $this->logger->info('Creating backup', [
                'user' => $this->getUser()?->getUserIdentifier(),
                'include_audit_log' => $includeAuditLog,
                'include_user_sessions' => $includeUserSessions,
            ]);

            // Create backup
            $backup = $this->backupService->createBackup($includeAuditLog, $includeUserSessions);

            // Save to file
            $filepath = $this->backupService->saveBackupToFile($backup);

            return new JsonResponse([
                'success' => true,
                'message' => 'Backup erfolgreich erstellt',
                'filename' => basename($filepath),
                'statistics' => $backup['statistics'],
            ]);
        } catch (\Exception $e) {
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

    #[Route('/backup/download/{filename}', name: 'data_backup_download', methods: ['GET'])]
    public function downloadBackup(string $filename): Response
    {
        // Validate filename to prevent directory traversal
        if (!preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.json\.gz$/', $filename)) {
            throw $this->createNotFoundException('Invalid backup filename');
        }

        $backupDir = $this->getParameter('kernel.project_dir') . '/var/backups';
        $filepath = $backupDir . '/' . $filename;

        if (!file_exists($filepath)) {
            throw $this->createNotFoundException('Backup file not found');
        }

        $response = new StreamedResponse(function () use ($filepath) {
            $outputStream = fopen('php://output', 'wb');
            $fileStream = fopen($filepath, 'rb');
            stream_copy_to_stream($fileStream, $outputStream);
            fclose($fileStream);
            fclose($outputStream);
        });

        $response->headers->set('Content-Type', 'application/gzip');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Content-Length', (string) filesize($filepath));

        return $response;
    }

    #[Route('/backup/upload', name: 'data_backup_upload', methods: ['POST'])]
    public function uploadBackup(Request $request): JsonResponse
    {
        try {
            /** @var UploadedFile|null $file */
            $file = $request->files->get('backup_file');

            if (!$file) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Keine Datei hochgeladen',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate file extension
            $allowedExtensions = ['json', 'gz'];
            $extension = $file->getClientOriginalExtension();

            if (!in_array($extension, $allowedExtensions)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Ungültiges Dateiformat. Nur .json oder .gz Dateien sind erlaubt.',
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

    #[Route('/backup/validate/{filename}', name: 'data_backup_validate', methods: ['POST'])]
    public function validateBackup(string $filename): JsonResponse
    {
        try {
            // Validate filename
            if (!preg_match('/^(backup_|uploaded_).+\.(json|gz)$/', $filename)) {
                throw new \InvalidArgumentException('Invalid backup filename');
            }

            $backupDir = $this->getParameter('kernel.project_dir') . '/var/backups';
            $filepath = $backupDir . '/' . $filename;

            if (!file_exists($filepath)) {
                throw new \InvalidArgumentException('Backup file not found');
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
        } catch (\Exception $e) {
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

    #[Route('/backup/preview/{filename}', name: 'data_backup_preview', methods: ['GET'])]
    public function previewRestore(string $filename): JsonResponse
    {
        try {
            // Validate filename
            if (!preg_match('/^(backup_|uploaded_).+\.(json|gz)$/', $filename)) {
                throw new \InvalidArgumentException('Invalid backup filename');
            }

            $backupDir = $this->getParameter('kernel.project_dir') . '/var/backups';
            $filepath = $backupDir . '/' . $filename;

            if (!file_exists($filepath)) {
                throw new \InvalidArgumentException('Backup file not found');
            }

            // Load backup and get preview
            $backup = $this->backupService->loadBackupFromFile($filepath);
            $preview = $this->restoreService->getRestorePreview($backup);

            return new JsonResponse([
                'success' => true,
                'preview' => $preview,
            ]);
        } catch (\Exception $e) {
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

    #[Route('/backup/restore/{filename}', name: 'data_backup_restore', methods: ['POST'])]
    public function restoreBackup(string $filename, Request $request): JsonResponse
    {
        try {
            // Validate filename
            if (!preg_match('/^(backup_|uploaded_).+\.(json|gz)$/', $filename)) {
                throw new \InvalidArgumentException('Invalid backup filename');
            }

            $backupDir = $this->getParameter('kernel.project_dir') . '/var/backups';
            $filepath = $backupDir . '/' . $filename;

            if (!file_exists($filepath)) {
                throw new \InvalidArgumentException('Backup file not found');
            }

            // Get restore options from request
            $options = [
                'missing_field_strategy' => $request->request->get('missing_field_strategy', RestoreService::STRATEGY_USE_DEFAULT),
                'existing_data_strategy' => $request->request->get('existing_data_strategy', RestoreService::EXISTING_UPDATE),
                'skip_entities' => $request->request->all('skip_entities') ?? [],
                'dry_run' => $request->request->getBoolean('dry_run', false),
            ];

            $this->logger->info('Starting restore', [
                'user' => $this->getUser()?->getUserIdentifier(),
                'filename' => $filename,
                'options' => $options,
            ]);

            // Load backup
            $backup = $this->backupService->loadBackupFromFile($filepath);

            // Perform restore
            $result = $this->restoreService->restoreFromBackup($backup, $options);

            return new JsonResponse([
                'success' => $result['success'],
                'message' => $options['dry_run']
                    ? 'Testlauf erfolgreich abgeschlossen (keine Daten wurden geändert)'
                    : 'Wiederherstellung erfolgreich abgeschlossen',
                'statistics' => $result['statistics'],
                'warnings' => $result['warnings'],
                'dry_run' => $result['dry_run'],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Restore failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Fehler bei der Wiederherstellung: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/backup/delete/{filename}', name: 'data_backup_delete', methods: ['DELETE'])]
    public function deleteBackup(string $filename): JsonResponse
    {
        try {
            // Validate filename
            if (!preg_match('/^(backup_|uploaded_).+\.(json|gz)$/', $filename)) {
                throw new \InvalidArgumentException('Invalid backup filename');
            }

            $backupDir = $this->getParameter('kernel.project_dir') . '/var/backups';
            $filepath = $backupDir . '/' . $filename;

            if (!file_exists($filepath)) {
                throw new \InvalidArgumentException('Backup file not found');
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
        } catch (\Exception $e) {
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
}
