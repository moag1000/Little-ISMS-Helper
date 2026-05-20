<?php

declare(strict_types=1);

namespace App\Service;

use DateTime;
use App\Entity\Tenant;
use App\Entity\UserSession;
use DateTimeInterface;
use Composer\InstalledVersions;
use App\Entity\AuditLog;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use ZipArchive;

class BackupService
{
    /** Current backup format version (2.0 adds ZIP+files support). */
    public const string BACKUP_FORMAT_VERSION = '2.0';

    /** Minimum format version that can still be restored (legacy JSON-only). */
    public const string MIN_COMPATIBLE_VERSION = '1.0';

    /**
     * File-reference fields per entity: [entityName => fieldName].
     * Values stored in the DB are relative paths under public/ (Document)
     * or relative paths under public/ (Tenant logo).
     */
    private const array FILE_REFERENCE_FIELDS = [
        'Document'        => 'filePath',   // stored as '/uploads/documents/foo.pdf'
        'DocumentVersion' => 'filePath',   // version-specific upload (history)
        'Tenant'          => 'logoPath',   // stored as 'uploads/tenants/logo.png'
        'TenantBranding'  => 'logoPath',   // separate branding overrides (subsidiaries)
    ];
    // Entities that contain productive user data
    // Order matters: entities with foreign keys must come after their dependencies.
    //
    // EVERY entity class in `src/Entity/` MUST be either listed here OR in
    // EXCLUDED_FROM_BACKUP below — enforced by Gate 43
    // (`scripts/quality/check_backup_entity_coverage.py`).
    private const array PRODUCTIVE_ENTITIES = [
        // Core entities (no dependencies)
        'Tenant',
        'Role',
        'Permission',
        'User',
        'Person',
        'Location',
        'Supplier',
        'SystemSettings',

        // Tenant-level overrides / branding
        'TenantBranding',           // FK: Tenant, User (has logoPath upload)
        'TenantPolicySetting',      // FK: Tenant, User
        'TenantPolicySettingChangeAttempt', // FK: Tenant, User (audit-trail)
        'LifecycleConfig',          // FK: Tenant, User — per-tenant lifecycle override

        // SSO / Identity (after User + Role + Tenant)
        'IdentityProvider',                  // FK: Tenant
        'IdentityProviderRoleMapping',       // FK: Tenant, IdentityProvider, Role
        'IdentityProviderUserMapping',       // FK: Tenant, IdentityProvider, User
        'SsoUserApproval',                   // FK: Tenant, IdentityProvider, User

        // Configuration entities (Phase 8 / QW-5)
        'RiskApprovalConfig',       // Phase 8L.F1 — FK: Tenant, User
        'IncidentSlaConfig',        // Phase 8L.F2 — FK: Tenant, User
        'SupplierCriticalityLevel', // Phase 8QW-5 — FK: Tenant
        'KpiThresholdConfig',       // FK: Tenant
        'Tag',                      // FK: Tenant
        'EntityTag',                // FK: Tag, User

        // ISMS Core
        'Asset',
        'Control',
        'Risk',
        'RiskAppetite',
        'RiskTreatmentPlan',
        'Incident',
        'RiskIncidentLink',         // FK: Tenant, Risk, Incident, User
        'Vulnerability',
        'Patch',
        'ThreatIntelligence',

        // BCM
        'BusinessProcess',
        'BusinessContinuityPlan',
        'BCExercise',
        'Bsi2004ExerciseLog',       // FK: Tenant, BCExercise, User — BSI-200-4 evidence
        'CrisisTeam',

        // Compliance
        'ComplianceFramework',
        'ComplianceRequirement',
        'ComplianceMapping',
        'ComplianceRequirementFulfillment',
        'FulfillmentInheritanceLog', // FK: Tenant, ComplianceRequirementFulfillment, ComplianceMapping, User — audit-trail
        'MappingGapItem',

        // GDPR/Privacy (CRITICAL - was missing!)
        'ProcessingActivity',
        'DataProtectionImpactAssessment',
        'DataBreach',
        'Consent',
        'DataSubjectRequest',       // DSGVO Art. 15-22 — FK: Tenant, User, ProcessingActivity

        // Policies & templates (global PolicyTemplate, tenant-scoped acknowledgements)
        'PolicyTemplate',           // GLOBAL (no tenant_id) — productive catalogue with custom edits
        'AuthorityTemplate',        // FK: Tenant — TISAX / authority template
        'PolicyAcknowledgement',    // FK: Tenant, Document, User — sign-offs

        // Documents & Training
        'Document',
        'DocumentVersion',          // FK: Tenant, Document, User (has filePath upload)
        'DocumentSection',          // FK: Tenant, Document, User — review/approve flow
        'DocumentControlLink',      // FK: Document, Control — link table
        'Training',
        'TrainingParticipation',    // FK: Tenant, Training, User — sign-offs

        // Comments (polymorphic, after all target entities)
        'Comment',                  // FK: Tenant, User — polymorphic threads

        // Audit & Reviews
        'InternalAudit',
        'AuditChecklist',
        'AuditFinding',             // H-01 — FK: Tenant, InternalAudit, Control, User
        'CorrectiveAction',         // FK: Tenant, AuditFinding, User
        'AuditFreeze',              // H-01 tamper-evident — FK: Tenant, User
        'ManagementReview',

        // DORA / Penetration Testing
        'ThreatLedPenetrationTest', // DORA Art. 26 — FK: Tenant; ManyToMany: AuditFinding

        // Context & Objectives
        'ISMSContext',
        'ISMSObjective',
        'InterestedParty',
        'CorporateGovernance',

        // TISAX / Prototype Protection
        'PrototypeProtectionAssessment', // FK: Tenant, Supplier, Location, User, Person

        // Operations
        'ChangeRequest',
        'CryptographicOperation',
        'PhysicalAccessLog',
        'FourEyesApprovalRequest',  // FK: Tenant, User (requester/approver/reviewer)
        'EvidenceReverificationTask', // FK: Tenant, DocumentVersion, Control, ComplianceRequirementFulfillment, User

        // Workflows
        'Workflow',
        'WorkflowStep',
        'WorkflowInstance',

        // Reports & Snapshots
        'ScheduledReport',          // FK: User (tenantId as raw int)
        'CustomReport',             // FK: User (tenantId as raw int)
        'AppliedBaseline',          // FK: Tenant, User
        'KpiSnapshot',              // FK: Tenant
        'SoaSnapshot',              // FK: Tenant, User — productive ISO 27001 SoA snapshot

        // Import audit-trail (after target entities + Document)
        'BulkImportBatch',          // FK: Tenant, Document, User — bulk-import receipts
        'BulkImportRow',            // FK: BulkImportBatch
        'ImportSession',            // FK: Tenant, User — OSCAL/CSV session
        'ImportRowEvent',           // FK: ImportSession
        'SampleDataImport',         // FK: Tenant, User — sample-data origin marker (kept for re-run idempotency)

        // Wizards
        'WizardRun',                // FK: Tenant, User — productive wizard run
        'WizardSession',            // FK: Tenant, User — wizard checkpoint state

        // User Preferences (optional but useful)
        'DashboardLayout',
        'MfaToken',
        'ScheduledTask',
        'PushSubscription',         // FK: Tenant, User — Web-Push endpoints (auth-token)

        // Alva-Hint user state + telemetry
        'AlvaHintDismissal',        // FK: Tenant, User — per-user dismiss state
        'AlvaHintRenderCount',      // FK: Tenant — telemetry (kept for trend continuity)

        // Misc tenant customizations
        'GuidedTourStepOverride',   // FK: Tenant — tour customizations
    ];

    /**
     * Entities deliberately excluded from backup with rationale.
     *
     * These entities are intentionally NOT in PRODUCTIVE_ENTITIES because they
     * fall into one of three categories:
     *   1) Global seeded catalogues that are re-loaded via console commands
     *      (no tenant data, no productive value in backup).
     *   2) Derived/cache rows that are re-computed on demand from primary data.
     *   3) Transient/ephemeral state that is rebuilt on next login/use.
     *
     * Entities listed here are skipped silently by the backup but accepted by
     * Gate 43 (`scripts/quality/check_backup_entity_coverage.py`).
     *
     * When ADDING a new entity to this list, you MUST include a one-line
     * rationale comment — Gate 43 enforces presence of inline reasoning.
     */
    private const array EXCLUDED_FROM_BACKUP = [
        // Global seeded catalogues (re-loadable via App\Command\Load*Command)
        'IndustryBaseline'      => 'global seeded catalogue, re-loadable via LoadIndustryBaseline commands',
        'IndustryPresetBundle'  => 'global seeded catalogue (no tenant_id) — wizard W4-B preset bundles',
        'ElementaryThreat'      => 'global BSI threat catalogue, re-loadable via LoadElementaryThreats command',

        // Derived / re-computable snapshots
        'PortfolioSnapshot'     => 'derived trend-cache, re-computable from primary entities',
        'ReuseTrendSnapshot'    => 'derived trend-cache, re-computable from primary entities',
    ];

    // Fields to exclude from backup (sensitive or regeneratable)
    private const array EXCLUDED_FIELDS = [
        'password',
        'salt',
        'mfaSecret',
        'resetToken',
        'resetTokenExpiresAt',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
        private readonly ?BackupEncryptionService $backupEncryption = null
    ) {
    }

    /**
     * Create a backup of all productive data.
     *
     * When $tenantScope is provided, only entities belonging to that tenant (and its
     * subsidiaries for holding tenants) are included.  Entities without a `tenant`
     * association (e.g. Role, Permission, SystemSettings) are silently skipped and
     * their names are recorded in metadata.skipped_global_entities.
     *
     * @param bool        $includeAuditLog    Include audit log in backup
     * @param bool        $includeUserSessions Include user sessions in backup
     * @param bool        $includeFiles       When true, collect file-path references for ZIP packaging
     * @param Tenant|null $tenantScope        When set, restricts backup to this tenant's data
     * @return array Backup data with metadata
     * @throws Exception If an error occurs during backup creation
     * @throws RuntimeException If backup cannot be compressed
     */
    public function createBackup(
        bool $includeAuditLog = true,
        bool $includeUserSessions = false,
        bool $includeFiles = true,
        ?Tenant $tenantScope = null
    ): array {
        // Determine scope tree (self + all subsidiaries for holding tenants)
        $scopeIds = $this->resolveScopeIds($tenantScope);
        $scopeType = $this->resolveScopeType($tenantScope);

        $this->logger->info('Starting backup creation', [
            'include_audit_log'   => $includeAuditLog,
            'include_user_sessions' => $includeUserSessions,
            'include_files'       => $includeFiles,
            'tenant_scope'        => $tenantScope?->getId(),
            'scope_type'          => $scopeType,
            'scope_ids'           => $scopeIds,
        ]);

        $backup = [
            'metadata' => [
                'version'            => self::BACKUP_FORMAT_VERSION,
                'app_version'        => $this->getApplicationVersion(),
                'schema_version'     => $this->getSchemaVersion(),
                'php_version'        => PHP_VERSION,
                'symfony_version'    => \Symfony\Component\HttpKernel\Kernel::VERSION,
                'doctrine_version'   => $this->getDoctrineVersion(),
                'files_included'     => false, // updated below if files are packaged
                'file_count'         => 0,
                'created_at'         => (new DateTime())->format('c'),
                'scope_type'         => $scopeType,
                'tenant_scope'       => $scopeIds,
            ],
            'data'                       => [],
            'statistics'                 => [],
        ];

        $skippedGlobalEntities = [];

        // Backup productive entities
        foreach (self::PRODUCTIVE_ENTITIES as $entityName) {
            $entityClass = 'App\\Entity\\' . $entityName;
            if (!class_exists($entityClass)) {
                $this->logger->warning('Entity class not found, skipping', ['entity' => $entityName]);
                continue;
            }

            // When a tenant scope is active, skip entities without a tenant association
            if ($tenantScope !== null && !$this->entityHasTenantField($entityClass)) {
                $skippedGlobalEntities[] = $entityName;
                $this->logger->debug('Skipping global entity (no tenant field) in tenant-scoped backup', [
                    'entity' => $entityName,
                ]);
                continue;
            }

            try {
                $entities = $this->fetchEntities($entityClass, $scopeIds);
                $backup['data'][$entityName] = $this->serializeEntities($entities);
                $backup['statistics'][$entityName] = count($entities);

                $this->logger->info('Backed up entity', [
                    'entity' => $entityName,
                    'count'  => count($entities),
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Error backing up entity', [
                    'entity' => $entityName,
                    'error'  => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        if ($skippedGlobalEntities !== []) {
            $backup['metadata']['skipped_global_entities'] = $skippedGlobalEntities;
        }

        // Collect file references for optional ZIP packaging
        if ($includeFiles) {
            $fileRefs = $this->collectFileReferences($backup['data']);
            $backup['metadata']['_file_refs'] = $fileRefs; // internal; used by saveBackupToFile()
        }

        // Backup audit log if requested
        if ($includeAuditLog) {
            try {
                $entities = $this->fetchEntities(AuditLog::class, $scopeIds);
                $backup['data']['AuditLog'] = $this->serializeEntities($entities);
                $backup['statistics']['AuditLog'] = count($entities);

                $this->logger->info('Backed up audit log', ['count' => count($entities)]);
            } catch (\Exception $e) {
                $this->logger->error('Error backing up audit log', ['error' => $e->getMessage()]);
            }
        }

        // Backup user sessions if requested
        if ($includeUserSessions) {
            $sessionClass = UserSession::class;
            if (class_exists($sessionClass)) {
                try {
                    $sessions = $this->fetchEntities($sessionClass, $scopeIds);
                    $backup['data']['UserSession'] = $this->serializeEntities($sessions);
                    $backup['statistics']['UserSession'] = count($sessions);

                    $this->logger->info('Backed up user sessions', ['count' => count($sessions)]);
                } catch (\Exception $e) {
                    $this->logger->error('Error backing up user sessions', ['error' => $e->getMessage()]);
                }
            }
        }

        // Encrypt sensitive SystemSettings values before computing the hash.
        if ($this->backupEncryption !== null && isset($backup['data']['SystemSettings'])) {
            $backup['data']['SystemSettings'] = $this->encryptSystemSettingsValues($backup['data']['SystemSettings']);
        }

        // SHA256 integrity seal: hash over the data section only (not metadata, to avoid chicken-egg).
        $backup['metadata']['sha256'] = hash('sha256', (string) json_encode($backup['data']));

        $this->logger->info('Backup creation completed', [
            'total_entities' => count($backup['statistics']),
            'total_records'  => array_sum($backup['statistics']),
        ]);

        return $backup;
    }

    // ------------------------------------------------------------------
    // Tenant-scope helpers
    // ------------------------------------------------------------------

    /**
     * Resolve the list of tenant IDs that belong to the given scope.
     * Returns an empty array when $tenantScope is null (= global backup).
     *
     * @return int[]
     */
    public function resolveScopeIds(?Tenant $tenantScope): array
    {
        if ($tenantScope === null) {
            return [];
        }

        $ids = [$tenantScope->getId()];
        foreach ($tenantScope->getAllSubsidiaries() as $subsidiary) {
            $ids[] = $subsidiary->getId();
        }

        return array_filter($ids, fn($id): bool => $id !== null);
    }

    /**
     * Return 'global', 'holding', or 'single' depending on the scope.
     */
    private function resolveScopeType(?Tenant $tenantScope): string
    {
        if ($tenantScope === null) {
            return 'global';
        }

        return $tenantScope->getAllSubsidiaries() !== [] ? 'holding' : 'single';
    }

    /**
     * Check whether an entity class has a `tenant` single-valued association.
     */
    private function entityHasTenantField(string $entityClass): bool
    {
        try {
            $metadata = $this->entityManager->getClassMetadata($entityClass);
            foreach ($metadata->getAssociationNames() as $name) {
                if ($name === 'tenant' && $metadata->isSingleValuedAssociation($name)) {
                    return true;
                }
            }
        } catch (\Exception) {
            // Entity not registered with Doctrine (e.g. in tests) → treat as no tenant field
        }

        return false;
    }

    /**
     * Fetch entities for an entity class, optionally filtered by tenant scope IDs.
     *
     * When $scopeIds is empty (global backup) all rows are returned via findAll().
     * When $scopeIds is non-empty, a QueryBuilder filters `e.tenant IN (:scope)` —
     * but only if the entity has a `tenant` association; otherwise findAll() is used.
     *
     * @param int[] $scopeIds
     * @return object[]
     */
    private function fetchEntities(string $entityClass, array $scopeIds): array
    {
        if ($scopeIds === [] || !$this->entityHasTenantField($entityClass)) {
            return $this->entityManager->getRepository($entityClass)->findAll();
        }

        $qb = $this->entityManager->getRepository($entityClass)->createQueryBuilder('e');
        $qb->andWhere('e.tenant IN (:scope)')
            ->setParameter('scope', $scopeIds);

        return $qb->getQuery()->getResult();
    }

    /**
     * Save backup to file.
     *
     * When the backup array contains resolvable file references
     * (i.e. createBackup() was called with $includeFiles=true and actual files exist),
     * a ZIP archive is produced:
     *
     *   backup_YYYY-MM-DD_HH-ii-ss.zip
     *   ├── backup.json
     *   └── files/
     *       ├── documents/uuid.pdf
     *       └── tenant_logos/logo.png
     *
     * When no files exist (or $includeFiles was false), the legacy .json.gz is produced.
     *
     * @param array $backup Backup data (returned by createBackup())
     * @param string|null $filename Optional base filename (without extension)
     * @return string Absolute path to the produced file (.zip or .json.gz)
     */
    public function saveBackupToFile(array $backup, ?string $filename = null): string
    {
        $backupDir = $this->projectDir . '/var/backups';

        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
            throw new FileException('Could not create backup directory: ' . $backupDir);
        }

        $baseName = $filename ?? ('backup_' . date('Y-m-d_H-i-s'));
        // Strip any extension the caller may have included
        $baseName = preg_replace('/\.(json|zip|gz).*$/', '', $baseName) ?? $baseName;

        // Determine whether we should build a ZIP with embedded files
        $fileRefs = $backup['metadata']['_file_refs'] ?? [];
        $existingFileRefs = $this->filterExistingFiles($fileRefs);

        if ($existingFileRefs !== []) {
            return $this->saveAsZip($backup, $existingFileRefs, $backupDir, $baseName);
        }

        // Fallback: legacy .json + optional .gz
        return $this->saveAsJson($backup, $backupDir, $baseName);
    }

    /**
     * Build a ZIP archive containing backup.json + all referenced files.
     *
     * @param array  $backup          Full backup array (metadata._file_refs will be stripped before embedding)
     * @param array  $fileRefs        Map of zipPath → absoluteLocalPath for files that exist on disk
     * @param string $backupDir       Absolute directory where the ZIP is written
     * @param string $baseName        Filename without extension
     * @return string Absolute path to the produced .zip file
     */
    private function saveAsZip(array $backup, array $fileRefs, string $backupDir, string $baseName): string
    {
        if (!class_exists(ZipArchive::class)) {
            $this->logger->warning('ZipArchive not available, falling back to JSON-only backup');
            return $this->saveAsJson($backup, $backupDir, $baseName);
        }

        $zipPath = $backupDir . '/' . $baseName . '.zip';

        // Update metadata counts and remove internal _file_refs before embedding
        $backup['metadata']['files_included'] = true;
        $backup['metadata']['file_count']     = count($fileRefs);
        unset($backup['metadata']['_file_refs']);

        $json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \App\Exception\Io\IoException('Failed to encode backup data to JSON: ' . json_last_error_msg());
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \App\Exception\Io\IoException('Could not create ZIP archive: ' . $zipPath);
        }

        $zip->addFromString('backup.json', $json);

        foreach ($fileRefs as $zipEntryPath => $localAbsPath) {
            // Security: validate that the zip entry path stays within files/
            $safeEntry = $this->sanitizeZipEntryPath($zipEntryPath);
            if ($safeEntry === null) {
                $this->logger->warning('Skipping file reference with unsafe ZIP path', [
                    'path' => $zipEntryPath,
                ]);
                continue;
            }
            $zip->addFile($localAbsPath, 'files/' . $safeEntry);
        }

        $zip->close();

        $this->logger->info('Backup saved as ZIP archive', [
            'file'       => $zipPath,
            'size'       => filesize($zipPath),
            'file_count' => count($fileRefs),
        ]);

        return $zipPath;
    }

    /**
     * Save backup as plain JSON (optionally gzip-compressed) — legacy format.
     */
    private function saveAsJson(array $backup, string $backupDir, string $baseName): string
    {
        // Strip internal key before writing
        unset($backup['metadata']['_file_refs']);
        $backup['metadata']['files_included'] = false;
        $backup['metadata']['file_count']     = 0;

        $filepath = $backupDir . '/' . $baseName . '.json';

        $json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \App\Exception\Io\IoException('Failed to encode backup data to JSON: ' . json_last_error_msg());
        }

        if (file_put_contents($filepath, $json) === false) {
            throw new FileException('Could not write backup file: ' . $filepath);
        }

        // Compress the backup file (if ext-zlib is available)
        $this->compressBackupFile($filepath);

        // Determine final filepath (compressed or uncompressed)
        $finalPath = file_exists($filepath . '.gz') ? $filepath . '.gz' : $filepath;

        $this->logger->info('Backup saved to file', [
            'file'       => $finalPath,
            'size'       => filesize($finalPath),
            'compressed' => file_exists($filepath . '.gz'),
        ]);

        return $finalPath;
    }

    /**
     * Get list of available backups.
     *
     * When $scope is null, all backups are returned (SUPER_ADMIN behaviour).
     * When $scope is a Tenant, the listing is filtered to backups whose embedded
     * tenant_scope intersects with the scope's accessible tree (self + subsidiaries).
     *
     * Detection order for each file:
     *   1. Sidecar `<filename>.meta.json` (preferred — fast, no decompression)
     *   2. Read metadata from backup payload (ZIP/gz/json) — opportunistically writes a sidecar
     *   3. Legacy filename without metadata → tenant_scope is null
     *
     * Legacy backups without detectable scope are EXCLUDED from non-SUPER listings
     * (safer default). SUPER (scope=null) always sees them.
     *
     * @param Tenant|null $scope When set, restricts the listing to backups in scope's tree
     * @return array List of backup files with metadata (filename, path, size, created_at,
     *               tenant_scope_ids, scope_type)
     */
    public function listBackups(?Tenant $scope = null): array
    {
        $backupDir = $this->projectDir . '/var/backups';

        if (!is_dir($backupDir)) {
            return [];
        }

        // Include both created backups (backup_*) and uploaded files (uploaded_*)
        // Support compressed (.gz), uncompressed (.json), and ZIP (.zip) files.
        // Sidecar `*.meta.json` files match the `backup_*.json` glob too, so
        // they are filtered out below.
        $files = array_merge(
            glob($backupDir . '/backup_*.zip') ?: [],
            glob($backupDir . '/backup_*.json.gz') ?: [],
            glob($backupDir . '/backup_*.json') ?: [],
            glob($backupDir . '/uploaded_*.zip') ?: [],
            glob($backupDir . '/uploaded_*.json.gz') ?: [],
            glob($backupDir . '/uploaded_*.json') ?: [],
            glob($backupDir . '/uploaded_*.gz') ?: []
        );

        // Strip Phase-5 sidecar files (`*.meta.json`) from the listing.
        $files = array_values(array_filter(
            $files,
            static fn(string $f): bool => !str_ends_with($f, '.meta.json')
        ));

        $accessibleIds = $this->resolveScopeIds($scope);
        $backups = [];

        foreach ($files as $file) {
            $tenantScopeIds = $this->detectBackupTenantScope($file);

            // Apply tenant filter when a scope is active
            if ($scope !== null) {
                if ($tenantScopeIds === null) {
                    // Legacy backup without scope info — exclude from non-SUPER listings
                    continue;
                }
                if ($tenantScopeIds !== [] && array_intersect($tenantScopeIds, $accessibleIds) === []) {
                    continue;
                }
            }

            $backups[] = [
                'filename'         => basename($file),
                'path'             => $file,
                'size'             => filesize($file),
                'created_at'       => date('Y-m-d H:i:s', filemtime($file)),
                'tenant_scope_ids' => $tenantScopeIds,
                'scope_type'       => $tenantScopeIds === null
                    ? 'unknown'
                    : ($tenantScopeIds === [] ? 'global' : 'tenant'),
            ];
        }

        // Sort by creation date (newest first)
        usort($backups, fn(array $a, array $b): int => $b['created_at'] <=> $a['created_at']);

        return $backups;
    }

    /**
     * Detect the tenant_scope embedded in a backup file.
     *
     * Returns:
     *  - array of tenant IDs (possibly empty = global backup) when detectable
     *  - null when not detectable (legacy filename without sidecar / unreadable metadata)
     *
     * Performs opportunistic sidecar caching: when scope is detected from the
     * backup payload (slow path), a `<filename>.meta.json` is written alongside
     * so subsequent listBackups() calls hit the fast path without decompression.
     *
     * @return int[]|null
     */
    private function detectBackupTenantScope(string $filepath): ?array
    {
        $sidecarPath = $filepath . '.meta.json';

        // Fast path: sidecar file
        if (is_file($sidecarPath)) {
            $sidecar = @json_decode((string) @file_get_contents($sidecarPath), true);
            if (is_array($sidecar) && array_key_exists('tenant_scope', $sidecar)) {
                $scope = $sidecar['tenant_scope'];
                return is_array($scope)
                    ? array_values(array_map(static fn($v): int => (int) $v, $scope))
                    : null;
            }
        }

        // Slow path: read metadata from the backup payload
        $metadata = $this->readBackupMetadata($filepath);
        if ($metadata === null) {
            return null;
        }

        $scope = $metadata['tenant_scope'] ?? null;
        if ($scope === null) {
            // Backup metadata present but no tenant_scope — treat as unknown (legacy/global)
            return null;
        }

        $scopeIds = is_array($scope)
            ? array_values(array_map(static fn($v): int => (int) $v, $scope))
            : [];

        // Opportunistic sidecar write (best-effort — silent on failure)
        @file_put_contents(
            $sidecarPath,
            (string) json_encode(['tenant_scope' => $scopeIds, 'detected_at' => date('c')])
        );

        return $scopeIds;
    }

    /**
     * Read the `metadata` section of a backup file without restoring data.
     *
     * Supports .zip (reads backup.json), .json.gz, .gz, and .json.
     * Returns null on any failure (corrupt file, missing extension support, …).
     *
     * @return array<string, mixed>|null
     */
    private function readBackupMetadata(string $filepath): ?array
    {
        try {
            if ($this->isZipFile($filepath)) {
                if (!class_exists(ZipArchive::class)) {
                    return null;
                }
                $zip = new ZipArchive();
                if ($zip->open($filepath) !== true) {
                    return null;
                }
                $json = $zip->getFromName('backup.json');
                $zip->close();
                if ($json === false) {
                    return null;
                }
            } elseif (str_ends_with($filepath, '.gz')) {
                if (!extension_loaded('zlib')) {
                    return null;
                }
                $raw = @file_get_contents($filepath);
                if ($raw === false) {
                    return null;
                }
                $json = @gzdecode($raw);
                if ($json === false) {
                    return null;
                }
            } else {
                $json = @file_get_contents($filepath);
                if ($json === false) {
                    return null;
                }
            }

            $decoded = json_decode($json, true);
            if (!is_array($decoded) || !isset($decoded['metadata']) || !is_array($decoded['metadata'])) {
                return null;
            }

            return $decoded['metadata'];
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Load backup from file.
     *
     * Supports three formats automatically detected by magic bytes / extension:
     *  - ZIP archive (.zip): extracts backup.json and optionally restores files
     *  - gzip-compressed JSON (.json.gz / .gz)
     *  - Plain JSON (.json)
     *
     * When a ZIP is loaded, the method also extracts embedded files into
     * public/uploads/ (path-traversal-safe) and populates
     * $backup['metadata']['_extracted_file_count'] for callers.
     *
     * @param string $filepath Path to backup file
     * @return array Backup data
     */
    public function loadBackupFromFile(string $filepath): array
    {
        if (!file_exists($filepath)) {
            throw new \App\Exception\InvalidArgument\InvalidArgumentException('Backup file not found: ' . $filepath, 'filepath');
        }

        // Auto-detect ZIP by magic bytes (PK\x03\x04) for robustness
        if ($this->isZipFile($filepath)) {
            return $this->loadFromZip($filepath);
        }

        // Decompress if needed
        if (str_ends_with($filepath, '.gz')) {
            // Check if zlib extension is available
            if (!extension_loaded('zlib')) {
                throw new \App\Exception\Io\IoException('Cannot decompress backup: ext-zlib extension not available');
            }

            $json = @gzdecode(file_get_contents($filepath)); // Suppress warning for intentionally corrupted test files
            if ($json === false) {
                throw new \App\Exception\Io\IoException('Failed to decompress backup file');
            }
        } else {
            $json = file_get_contents($filepath);
        }

        $backup = json_decode($json, true);
        if ($backup === null) {
            throw new \App\Exception\Io\IoException('Failed to decode backup JSON: ' . json_last_error_msg());
        }

        return $backup;
    }

    /**
     * Load backup from a ZIP archive.
     *
     * Extracts backup.json and copies embedded files to public/uploads/
     * with path-traversal protection.
     */
    private function loadFromZip(string $filepath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \App\Exception\Io\IoException('ZipArchive extension is not available — cannot read ZIP backup');
        }

        $zip = new ZipArchive();
        if ($zip->open($filepath) !== true) {
            throw new \App\Exception\Io\IoException('Failed to open ZIP backup: ' . $filepath);
        }

        $jsonContent = $zip->getFromName('backup.json');
        if ($jsonContent === false) {
            $zip->close();
            throw new \App\Exception\Io\IoException('ZIP backup does not contain backup.json');
        }

        $backup = json_decode($jsonContent, true);
        if ($backup === null) {
            $zip->close();
            throw new \App\Exception\Io\IoException('Failed to decode backup JSON from ZIP: ' . json_last_error_msg());
        }

        // Extract embedded files into public/uploads/ (before entity restore so paths resolve)
        $extractedCount = 0;
        $publicDir = $this->projectDir . '/public';

        for ($i = 0; $i < $zip->count(); $i++) {
            $entryName = $zip->getNameIndex($i);

            // Only process entries under files/
            if (!str_starts_with((string) $entryName, 'files/')) {
                continue;
            }

            $relativePath = substr((string) $entryName, strlen('files/'));
            if ($relativePath === '' || $relativePath === false) {
                continue;
            }

            // Security: path-traversal check — resolved path must stay inside public/uploads/
            $targetPath = realpath($publicDir . '/uploads') . '/' . ltrim($relativePath, '/');
            $uploadsBase = realpath($publicDir . '/uploads') . '/';

            if (!str_starts_with($targetPath, $uploadsBase)) {
                $this->logger->warning('Skipping file with unsafe path in ZIP', ['entry' => $entryName]);
                continue;
            }

            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                $this->logger->warning('Could not create target directory for extracted file', [
                    'dir' => $targetDir,
                ]);
                continue;
            }

            $content = $zip->getFromIndex($i);
            if ($content !== false && file_put_contents($targetPath, $content) !== false) {
                $extractedCount++;
            } else {
                $this->logger->warning('Failed to extract file from ZIP', ['entry' => $entryName]);
            }
        }

        $zip->close();

        $backup['metadata']['_extracted_file_count'] = $extractedCount;

        $this->logger->info('Loaded backup from ZIP', [
            'file'            => $filepath,
            'extracted_files' => $extractedCount,
        ]);

        return $backup;
    }

    /**
     * Detect ZIP format via magic bytes (PK header: 0x50 0x4B 0x03 0x04).
     */
    private function isZipFile(string $filepath): bool
    {
        $handle = fopen($filepath, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = fread($handle, 4);
        fclose($handle);
        return $header !== false && $header === "PK\x03\x04";
    }

    /**
     * Collect all file references from the serialized entity data.
     *
     * Returns a map of [zipEntryPath => absoluteLocalPath].
     * Only entities listed in FILE_REFERENCE_FIELDS are scanned.
     *
     * @param array $data Serialized entity data (backup['data'])
     * @return array<string, string> zipEntryPath => absoluteLocalPath
     */
    private function collectFileReferences(array $data): array
    {
        $refs = [];

        foreach (self::FILE_REFERENCE_FIELDS as $entityName => $field) {
            $entities = $data[$entityName] ?? [];
            foreach ($entities as $entityData) {
                $relativePath = $entityData[$field] ?? null;
                if ($relativePath === null || $relativePath === '') {
                    continue;
                }

                // Normalize: strip leading slash
                $relativePath = ltrim((string) $relativePath, '/');

                $absPath = $this->projectDir . '/public/' . $relativePath;

                if (!file_exists($absPath) || !is_file($absPath)) {
                    continue;
                }

                // Derive a safe ZIP entry path based on entity type
                $zipEntry = match ($entityName) {
                    'Document'        => 'documents/' . basename($absPath),
                    'DocumentVersion' => 'document_versions/' . basename($absPath),
                    'Tenant'          => 'tenant_logos/' . basename($absPath),
                    'TenantBranding'  => 'tenant_logos/' . basename($absPath),
                    default           => $entityName . '/' . basename($absPath),
                };

                $refs[$zipEntry] = $absPath;
            }
        }

        return $refs;
    }

    /**
     * Filter file references to only those whose local file actually exists.
     *
     * @param array $fileRefs Raw file refs (may include non-existent paths)
     * @return array Filtered refs
     */
    private function filterExistingFiles(array $fileRefs): array
    {
        return array_filter($fileRefs, fn(string $absPath): bool => file_exists($absPath) && is_file($absPath));
    }

    /**
     * Sanitize a ZIP entry path to prevent path traversal.
     *
     * Returns null if the path is unsafe (contains .., absolute segments, etc.).
     */
    private function sanitizeZipEntryPath(string $path): ?string
    {
        // Normalize directory separators
        $path = str_replace('\\', '/', $path);

        // Reject absolute paths and traversal sequences
        if (str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, ':')) {
            return null;
        }

        // Allow only safe characters (alphanumeric, dash, underscore, dot, slash)
        if (!preg_match('#^[a-zA-Z0-9/_.\-]+$#', $path)) {
            return null;
        }

        return $path;
    }

    /**
     * Serialize entities to array
     */
    private function serializeEntities(array $entities): array
    {
        $data = [];

        foreach ($entities as $entity) {
            $serialized = [];
            $metadata = $this->entityManager->getClassMetadata($entity::class);

            // Serialize field mappings
            foreach ($metadata->getFieldNames() as $fieldName) {
                if (in_array($fieldName, self::EXCLUDED_FIELDS)) {
                    continue;
                }

                $value = $metadata->getFieldValue($entity, $fieldName);

                // Convert DateTime to ISO 8601 string
                if ($value instanceof DateTimeInterface) {
                    $value = $value->format('c');
                }

                $serialized[$fieldName] = $value;
            }

            // Serialize associations (store IDs only)
            foreach ($metadata->getAssociationNames() as $assocName) {
                if ($metadata->isSingleValuedAssociation($assocName)) {
                    $value = $metadata->getFieldValue($entity, $assocName);
                    if ($value !== null) {
                        $assocMetadata = $this->entityManager->getClassMetadata($value::class);
                        $serialized[$assocName . '_id'] = $assocMetadata->getIdentifierValues($value);
                    }
                } else {
                    // For collections, store array of IDs
                    $collection = $metadata->getFieldValue($entity, $assocName);
                    if ($collection !== null && count($collection) > 0) {
                        $ids = [];
                        foreach ($collection as $item) {
                            $assocMetadata = $this->entityManager->getClassMetadata($item::class);
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
     * Compress backup file using gzip (if ext-zlib is available)
     */
    private function compressBackupFile(string $filepath): void
    {
        // Check if zlib extension is available
        if (!extension_loaded('zlib')) {
            $this->logger->info('ext-zlib not available, skipping compression', ['file' => $filepath]);
            return;
        }

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
     * Get the latest applied Doctrine migration version.
     *
     * Reads from the doctrine_migration_versions table via DBAL to avoid
     * Doctrine ORM entity manager overhead.
     */
    private function getSchemaVersion(): string
    {
        try {
            $connection = $this->entityManager->getConnection();
            $result = $connection->executeQuery(
                'SELECT version FROM doctrine_migration_versions ORDER BY executed_at DESC LIMIT 1'
            );
            $row = $result->fetchAssociative();
            if ($row !== false && isset($row['version'])) {
                // Strip the namespace prefix for readability: keep only the timestamp part
                return preg_replace('/^.*\\\\/', '', (string) $row['version']) ?? (string) $row['version'];
            }
        } catch (\Exception) {
            // Table may not exist in test environments or on fresh installs
        }
        return 'unknown';
    }

    /**
     * Get Doctrine version
     */
    private function getDoctrineVersion(): string
    {
        try {
            if (class_exists(InstalledVersions::class)) {
                $packages = InstalledVersions::getAllRawData()[0]['versions'] ?? [];
                return $packages['doctrine/orm']['version'] ?? 'unknown';
            }
        } catch (\Exception) {
            // Fallback if Composer runtime API is not available
        }
        return 'unknown';
    }

    /**
     * Encrypt sensitive values in the serialised SystemSettings data.
     *
     * Only `value` fields whose corresponding `key` matches a known sensitive
     * pattern (secret, password, api_key, …) are replaced with an AES-256-GCM
     * envelope. All other rows are returned unchanged.
     *
     * @param array<int, array<string, mixed>> $rows Serialised SystemSettings rows.
     * @return array<int, array<string, mixed>> Rows with sensitive values encrypted.
     */
    private function encryptSystemSettingsValues(array $rows): array
    {
        if ($this->backupEncryption === null) {
            return $rows;
        }

        foreach ($rows as &$row) {
            $key   = (string) ($row['key'] ?? '');
            $value = $row['value'] ?? null;

            if ($key === '' || $value === null || !$this->backupEncryption->isSensitiveKey($key)) {
                continue;
            }

            // Encrypt the value (stringify non-string scalars first so the cipher input is predictable).
            $plaintext = is_string($value) ? $value : (string) json_encode($value);
            $row['value'] = $this->backupEncryption->encryptValue($plaintext);
        }
        unset($row);

        return $rows;
    }
}
