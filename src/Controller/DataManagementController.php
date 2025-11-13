<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/data')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class DataManagementController extends AbstractController
{
    private string $backupDir;

    public function __construct(
        #[Autowire('%kernel.project_dir%')] string $projectDir
    )
    {
        $this->backupDir = $projectDir . '/var/backups';

        // Ensure backup directory exists
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    #[Route('/backup', name: 'data_backup_index', methods: ['GET'])]
    public function backupIndex(): Response
    {
        // List existing backups
        $backups = [];

        if (is_dir($this->backupDir)) {
            $files = scandir($this->backupDir);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                    $filePath = $this->backupDir . '/' . $file;
                    $backups[] = [
                        'filename' => $file,
                        'size' => filesize($filePath),
                        'date' => filemtime($filePath),
                        'path' => $filePath,
                    ];
                }
            }
        }

        // Sort by date (newest first)
        usort($backups, fn($a, $b) => $b['date'] - $a['date']);

        return $this->render('data_management/backup.html.twig', [
            'backups' => $backups,
            'backup_dir' => $this->backupDir,
        ]);
    }

    #[Route('/backup/create', name: 'data_backup_create', methods: ['POST'])]
    public function backupCreate(
        Request $request,
        TranslatorInterface $translator
    ): Response {
        if (!$this->isCsrfTokenValid('backup_create', $request->request->get('_token'))) {
            $this->addFlash('error', $translator->trans('data.backup.error.invalid_token'));
            return $this->redirectToRoute('data_backup_index');
        }

        try {
            $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
            $backupPath = $this->backupDir . '/' . $filename;

            // Get database connection parameters
            $dbUrl = $_ENV['DATABASE_URL'] ?? '';

            if (empty($dbUrl)) {
                throw new \Exception('DATABASE_URL not configured');
            }

            // Parse DATABASE_URL
            $dbParts = parse_url($dbUrl);
            $dbHost = $dbParts['host'] ?? 'localhost';
            $dbPort = $dbParts['port'] ?? 5432;
            $dbName = ltrim($dbParts['path'] ?? '', '/');
            $dbUser = $dbParts['user'] ?? 'postgres';
            $dbPass = $dbParts['pass'] ?? '';

            // Use pg_dump for PostgreSQL
            $command = sprintf(
                'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -F p -f %s %s 2>&1',
                escapeshellarg($dbPass),
                escapeshellarg($dbHost),
                escapeshellarg($dbPort),
                escapeshellarg($dbUser),
                escapeshellarg($backupPath),
                escapeshellarg($dbName)
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \Exception('Backup failed: ' . implode("\n", $output));
            }

            // Apply retention policy (keep last 7 backups)
            $this->applyRetentionPolicy(7);

            $this->addFlash('success', $translator->trans('data.backup.success.created', [
                'filename' => $filename,
            ]));
        } catch (\Exception $e) {
            $this->addFlash('error', $translator->trans('data.backup.error.create_failed', [
                'error' => $e->getMessage(),
            ]));
        }

        return $this->redirectToRoute('data_backup_index');
    }

    #[Route('/backup/download/{filename}', name: 'data_backup_download', methods: ['GET'])]
    public function backupDownload(string $filename): Response
    {
        $filePath = $this->backupDir . '/' . $filename;

        if (!file_exists($filePath) || pathinfo($filename, PATHINFO_EXTENSION) !== 'sql') {
            throw $this->createNotFoundException('Backup file not found');
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );

        return $response;
    }

    #[Route('/backup/delete/{filename}', name: 'data_backup_delete', methods: ['POST'])]
    public function backupDelete(
        string $filename,
        Request $request,
        TranslatorInterface $translator
    ): Response {
        if (!$this->isCsrfTokenValid('backup_delete_' . $filename, $request->request->get('_token'))) {
            $this->addFlash('error', $translator->trans('data.backup.error.invalid_token'));
            return $this->redirectToRoute('data_backup_index');
        }

        $filePath = $this->backupDir . '/' . $filename;

        if (file_exists($filePath) && pathinfo($filename, PATHINFO_EXTENSION) === 'sql') {
            unlink($filePath);
            $this->addFlash('success', $translator->trans('data.backup.success.deleted'));
        } else {
            $this->addFlash('error', $translator->trans('data.backup.error.not_found'));
        }

        return $this->redirectToRoute('data_backup_index');
    }

    #[Route('/export', name: 'data_export_index', methods: ['GET'])]
    public function exportIndex(EntityManagerInterface $em): Response
    {
        // Get all entity class names
        $metadata = $em->getMetadataFactory()->getAllMetadata();
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
        usort($entities, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $this->render('data_management/export.html.twig', [
            'entities' => $entities,
        ]);
    }

    #[Route('/export/execute', name: 'data_export_execute', methods: ['POST'])]
    public function exportExecute(
        Request $request,
        EntityManagerInterface $em,
        TranslatorInterface $translator
    ): Response {
        if (!$this->isCsrfTokenValid('data_export', $request->request->get('_token'))) {
            $this->addFlash('error', $translator->trans('data.export.error.invalid_token'));
            return $this->redirectToRoute('data_export_index');
        }

        $selectedEntities = $request->request->all('entities') ?? [];
        $format = $request->request->get('format', 'json');

        if (empty($selectedEntities)) {
            $this->addFlash('error', $translator->trans('data.export.error.no_entities'));
            return $this->redirectToRoute('data_export_index');
        }

        // Close session to prevent blocking other requests during export generation
        $request->getSession()->save();

        $exportData = [];

        foreach ($selectedEntities as $entityClass) {
            // Security: Only allow App\Entity namespace
            if (!str_starts_with($entityClass, 'App\\Entity\\')) {
                continue;
            }

            try {
                $repository = $em->getRepository($entityClass);
                $entities = $repository->findAll();

                $shortName = substr($entityClass, strrpos($entityClass, '\\') + 1);
                $exportData[$shortName] = [];

                foreach ($entities as $entity) {
                    // Convert entity to array (simplified)
                    $exportData[$shortName][] = $this->entityToArray($entity, $em);
                }
            } catch (\Exception $e) {
                // Skip entities that can't be exported
                continue;
            }
        }

        // Return response based on format
        if ($format === 'json') {
            return $this->createJsonExportResponse($exportData);
        } else {
            return $this->createCsvExportResponse($exportData);
        }
    }

    #[Route('/import', name: 'data_import_index', methods: ['GET'])]
    public function importIndex(): Response
    {
        return $this->render('data_management/import.html.twig');
    }

    #[Route('/import/upload', name: 'data_import_upload', methods: ['POST'])]
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
                throw new \Exception('Invalid JSON: ' . json_last_error_msg());
            }

            // Store data in session for preview
            $request->getSession()->set('import_preview_data', $data);

            return $this->redirectToRoute('data_import_preview');
        } catch (\Exception $e) {
            $this->addFlash('error', $translator->trans('data.import.error.invalid_file', [
                'error' => $e->getMessage(),
            ]));
            return $this->redirectToRoute('data_import_index');
        }
    }

    #[Route('/import/preview', name: 'data_import_preview', methods: ['GET'])]
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

    #[Route('/import/execute', name: 'data_import_execute', methods: ['POST'])]
    public function importExecute(
        Request $request,
        EntityManagerInterface $em,
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

        $imported = 0;
        $errors = [];

        // NOTE: This is a simplified implementation
        // In production, you'd need proper entity creation, validation, and relationship handling

        $this->addFlash('warning', $translator->trans('data.import.warning.not_implemented'));
        $request->getSession()->remove('import_preview_data');

        return $this->redirectToRoute('data_import_index');
    }

    #[Route('/setup', name: 'data_setup', methods: ['GET'])]
    public function setup(): Response
    {
        // Redirect to existing deployment wizard
        return $this->redirectToRoute('setup_index');
    }

    /**
     * Apply retention policy - keep only last N backups
     */
    private function applyRetentionPolicy(int $keepCount): void
    {
        $backups = [];

        if (is_dir($this->backupDir)) {
            $files = scandir($this->backupDir);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                    $filePath = $this->backupDir . '/' . $file;
                    $backups[] = [
                        'file' => $file,
                        'path' => $filePath,
                        'date' => filemtime($filePath),
                    ];
                }
            }
        }

        // Sort by date (newest first)
        usort($backups, fn($a, $b) => $b['date'] - $a['date']);

        // Delete old backups
        for ($i = $keepCount; $i < count($backups); $i++) {
            unlink($backups[$i]['path']);
        }
    }

    /**
     * Convert entity to array (simplified)
     */
    private function entityToArray($entity, EntityManagerInterface $em): array
    {
        $metadata = $em->getClassMetadata(get_class($entity));
        $data = [];

        foreach ($metadata->getFieldNames() as $field) {
            try {
                $value = $metadata->getFieldValue($entity, $field);

                // Handle DateTime objects
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d H:i:s');
                }

                $data[$field] = $value;
            } catch (\Exception $e) {
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
        $response = new StreamedResponse(function() use ($data) {
            $handle = fopen('php://output', 'w');

            foreach ($data as $entityName => $entities) {
                if (empty($entities)) {
                    continue;
                }

                // Write entity header
                fputcsv($handle, ['# ' . $entityName]);

                // Write column headers
                $headers = array_keys($entities[0]);
                fputcsv($handle, $headers);

                // Write data
                foreach ($entities as $entity) {
                    fputcsv($handle, $entity);
                }

                // Empty line between entities
                fputcsv($handle, []);
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition',
            'attachment; filename="export_' . date('Y-m-d_H-i-s') . '.csv"');

        return $response;
    }
}
