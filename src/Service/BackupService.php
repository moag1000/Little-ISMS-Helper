<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class BackupService
{
    // Entities that contain productive user data
    private const PRODUCTIVE_ENTITIES = [
        'User',
        'Tenant',
        'Role',
        'Permission',
        'Risk',
        'Incident',
        'Asset',
        'Control',
        'Vulnerability',
        'Patch',
        'Training',
        'Document',
        'BusinessContinuityPlan',
        'BCExercise',
        'BusinessProcess',
        'CrisisTeam',
        'RiskTreatmentPlan',
        'ComplianceFramework',
        'ComplianceMapping',
        'ComplianceRequirement',
        'InternalAudit',
        'MappingGapItem',
        'ISMSContext',
        'ISMSObjective',
        'CorporateGovernance',
        'InterestedParty',
        'Supplier',
        'ThreatIntelligence',
        'CryptographicOperation',
        'PhysicalAccessLog',
        'Location',
        'SystemSettings',
        'Workflow',
        'WorkflowInstance',
        'WorkflowStep',
        'ManagementReview',
        'RiskAppetite',
    ];

    // Entities for logs
    private const LOG_ENTITIES = [
        'AuditLog',
        'UserSession',
    ];

    // Fields to exclude from backup (sensitive or regeneratable)
    private const EXCLUDED_FIELDS = [
        'password',
        'salt',
        'mfaSecret',
        'resetToken',
        'resetTokenExpiresAt',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
        private string $projectDir
    ) {
    }

    /**
     * Create a backup of all productive data
     *
     * @param bool $includeAuditLog Include audit log in backup
     * @param bool $includeUserSessions Include user sessions in backup
     * @return array Backup data with metadata
     */
    public function createBackup(bool $includeAuditLog = true, bool $includeUserSessions = false): array
    {
        $this->logger->info('Starting backup creation', [
            'include_audit_log' => $includeAuditLog,
            'include_user_sessions' => $includeUserSessions,
        ]);

        $backup = [
            'metadata' => [
                'version' => '1.0',
                'created_at' => (new \DateTime())->format('c'),
                'application_version' => $this->getApplicationVersion(),
                'php_version' => PHP_VERSION,
                'doctrine_version' => $this->getDoctrineVersion(),
            ],
            'data' => [],
            'statistics' => [],
        ];

        // Backup productive entities
        foreach (self::PRODUCTIVE_ENTITIES as $entityName) {
            $entityClass = 'App\\Entity\\' . $entityName;
            if (!class_exists($entityClass)) {
                $this->logger->warning('Entity class not found, skipping', ['entity' => $entityName]);
                continue;
            }

            try {
                $entities = $this->entityManager->getRepository($entityClass)->findAll();
                $backup['data'][$entityName] = $this->serializeEntities($entities);
                $backup['statistics'][$entityName] = count($entities);

                $this->logger->info('Backed up entity', [
                    'entity' => $entityName,
                    'count' => count($entities),
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Error backing up entity', [
                    'entity' => $entityName,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        // Backup audit log if requested
        if ($includeAuditLog) {
            try {
                $auditLogs = $this->entityManager->getRepository(AuditLog::class)->findAll();
                $backup['data']['AuditLog'] = $this->serializeEntities($auditLogs);
                $backup['statistics']['AuditLog'] = count($auditLogs);

                $this->logger->info('Backed up audit log', ['count' => count($auditLogs)]);
            } catch (\Exception $e) {
                $this->logger->error('Error backing up audit log', ['error' => $e->getMessage()]);
            }
        }

        // Backup user sessions if requested
        if ($includeUserSessions) {
            $sessionClass = 'App\\Entity\\UserSession';
            if (class_exists($sessionClass)) {
                try {
                    $sessions = $this->entityManager->getRepository($sessionClass)->findAll();
                    $backup['data']['UserSession'] = $this->serializeEntities($sessions);
                    $backup['statistics']['UserSession'] = count($sessions);

                    $this->logger->info('Backed up user sessions', ['count' => count($sessions)]);
                } catch (\Exception $e) {
                    $this->logger->error('Error backing up user sessions', ['error' => $e->getMessage()]);
                }
            }
        }

        $this->logger->info('Backup creation completed', [
            'total_entities' => count($backup['statistics']),
            'total_records' => array_sum($backup['statistics']),
        ]);

        return $backup;
    }

    /**
     * Save backup to file
     *
     * @param array $backup Backup data
     * @param string|null $filename Optional filename
     * @return string Path to backup file
     */
    public function saveBackupToFile(array $backup, ?string $filename = null): string
    {
        $backupDir = $this->projectDir . '/var/backups';

        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0755, true)) {
                throw new FileException('Could not create backup directory: ' . $backupDir);
            }
        }

        if ($filename === null) {
            $filename = 'backup_' . date('Y-m-d_H-i-s') . '.json';
        }

        $filepath = $backupDir . '/' . $filename;

        $json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode backup data to JSON: ' . json_last_error_msg());
        }

        if (file_put_contents($filepath, $json) === false) {
            throw new FileException('Could not write backup file: ' . $filepath);
        }

        // Compress the backup file
        $this->compressBackupFile($filepath);

        $this->logger->info('Backup saved to file', [
            'file' => $filepath,
            'size' => filesize($filepath . '.gz'),
        ]);

        return $filepath . '.gz';
    }

    /**
     * Get list of available backups
     *
     * @return array List of backup files with metadata
     */
    public function listBackups(): array
    {
        $backupDir = $this->projectDir . '/var/backups';

        if (!is_dir($backupDir)) {
            return [];
        }

        // Include both created backups (backup_*) and uploaded files (uploaded_*)
        $files = array_merge(
            glob($backupDir . '/backup_*.json.gz') ?: [],
            glob($backupDir . '/uploaded_*.json.gz') ?: [],
            glob($backupDir . '/uploaded_*.json') ?: [],
            glob($backupDir . '/uploaded_*.gz') ?: []
        );
        $backups = [];

        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'created_at' => date('Y-m-d H:i:s', filemtime($file)),
            ];
        }

        // Sort by creation date (newest first)
        usort($backups, function ($a, $b) {
            return $b['created_at'] <=> $a['created_at'];
        });

        return $backups;
    }

    /**
     * Load backup from file
     *
     * @param string $filepath Path to backup file
     * @return array Backup data
     */
    public function loadBackupFromFile(string $filepath): array
    {
        if (!file_exists($filepath)) {
            throw new \InvalidArgumentException('Backup file not found: ' . $filepath);
        }

        // Decompress if needed
        if (str_ends_with($filepath, '.gz')) {
            $json = @gzdecode(file_get_contents($filepath)); // Suppress warning for intentionally corrupted test files
            if ($json === false) {
                throw new \RuntimeException('Failed to decompress backup file');
            }
        } else {
            $json = file_get_contents($filepath);
        }

        $backup = json_decode($json, true);
        if ($backup === null) {
            throw new \RuntimeException('Failed to decode backup JSON: ' . json_last_error_msg());
        }

        return $backup;
    }

    /**
     * Serialize entities to array
     *
     * @param array $entities
     * @return array
     */
    private function serializeEntities(array $entities): array
    {
        $data = [];

        foreach ($entities as $entity) {
            $serialized = [];
            $metadata = $this->entityManager->getClassMetadata(get_class($entity));

            // Serialize field mappings
            foreach ($metadata->getFieldNames() as $fieldName) {
                if (in_array($fieldName, self::EXCLUDED_FIELDS)) {
                    continue;
                }

                $value = $metadata->getFieldValue($entity, $fieldName);

                // Convert DateTime to ISO 8601 string
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('c');
                }

                $serialized[$fieldName] = $value;
            }

            // Serialize associations (store IDs only)
            foreach ($metadata->getAssociationNames() as $assocName) {
                if ($metadata->isSingleValuedAssociation($assocName)) {
                    $value = $metadata->getFieldValue($entity, $assocName);
                    if ($value !== null) {
                        $assocMetadata = $this->entityManager->getClassMetadata(get_class($value));
                        $serialized[$assocName . '_id'] = $assocMetadata->getIdentifierValues($value);
                    }
                } else {
                    // For collections, store array of IDs
                    $collection = $metadata->getFieldValue($entity, $assocName);
                    if ($collection !== null && count($collection) > 0) {
                        $ids = [];
                        foreach ($collection as $item) {
                            $assocMetadata = $this->entityManager->getClassMetadata(get_class($item));
                            $ids[] = $assocMetadata->getIdentifierValues($item);
                        }
                        $serialized[$assocName . '_ids'] = $ids;
                    }
                }
            }

            $data[] = $serialized;
        }

        return $data;
    }

    /**
     * Compress backup file using gzip
     *
     * @param string $filepath
     * @return void
     */
    private function compressBackupFile(string $filepath): void
    {
        $content = file_get_contents($filepath);
        $compressed = gzencode($content, 9);

        if ($compressed === false) {
            $this->logger->warning('Failed to compress backup file', ['file' => $filepath]);
            return;
        }

        file_put_contents($filepath . '.gz', $compressed);
        unlink($filepath); // Remove uncompressed file
    }

    /**
     * Get application version from composer.json
     *
     * @return string
     */
    private function getApplicationVersion(): string
    {
        $composerFile = $this->projectDir . '/composer.json';
        if (file_exists($composerFile)) {
            $composer = json_decode(file_get_contents($composerFile), true);
            return $composer['version'] ?? 'unknown';
        }
        return 'unknown';
    }

    /**
     * Get Doctrine version
     *
     * @return string
     */
    private function getDoctrineVersion(): string
    {
        try {
            if (class_exists(\Composer\InstalledVersions::class)) {
                $packages = \Composer\InstalledVersions::getAllRawData()[0]['versions'] ?? [];
                return $packages['doctrine/orm']['version'] ?? 'unknown';
            }
        } catch (\Exception $e) {
            // Fallback if Composer runtime API is not available
        }
        return 'unknown';
    }
}
