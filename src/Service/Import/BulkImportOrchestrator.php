<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\BulkImportBatch;
use App\Entity\BulkImportRow;
use App\Entity\Tenant;
use App\Entity\User;
use App\Message\BulkImportMessage;
use App\Service\AuditLogger;
use App\Service\Import\Dto\DeltaConfig;
use App\Service\Import\Dto\DeltaResult;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * High-level orchestrator for the bulk-import pipeline.
 *
 * Four-step lifecycle:
 *   1. upload()         — accept & store the file, create BulkImportBatch (status=uploaded)
 *   2. preview()        — parse + suggest/apply column mapping + calculate delta (status=preview)
 *   3. dispatchCommit() — dispatch BulkImportMessage via Messenger for async processing
 *   4. commit()         — called by MessageHandler; write entities, update counters, emit audit log
 *
 * Invariants:
 *   - Commit is wrapped in a single DB transaction via EntityManager::wrapInTransaction().
 *   - Entities are flushed in batches of 100 to avoid ORM memory bloat.
 *   - AuditLogger::logBulk() is called AFTER the final flush so the batch_id
 *     correlates only to successfully committed rows (ISO 27001 Clause 7.5.3).
 *   - All entity persistence goes through the Doctrine lifecycle so
 *     AuditLogSubscriber + tenant-filter fire normally (no raw SQL shortcuts).
 */
class BulkImportOrchestrator
{
    /** Flush + clear the EM after this many rows to cap memory usage. */
    private const BATCH_FLUSH_SIZE = 100;

    public function __construct(
        private readonly SpreadsheetParser $parser,
        private readonly HeaderHeuristicMapper $headerMapper,
        private readonly EntityMapperRegistry $mapperRegistry,
        private readonly DeltaCalculator $deltaCalculator,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly string $uploadDir,
    ) {}

    // -------------------------------------------------------------------------
    // Step 1: upload
    // -------------------------------------------------------------------------

    /**
     * Persist the uploaded file to disk, compute SHA-256, and create a
     * BulkImportBatch entity with status=uploaded.
     *
     * The source-file evidence document linkage (Document entity with
     * documentType='import_evidence') is intentionally deferred:
     * @todo 2026-05-14 (F2.6) Create Document entity and set $batch->setSourceDocument()
     * once the Document CRUD service is wired to the import pipeline.
     * Blocked by: BulkImport ↔ Document linkage service (Sprint F2.6).
     *
     * @param string $mode One of BulkImportBatch::MODE_* constants
     */
    public function upload(
        UploadedFile $file,
        string $entityType,
        Tenant $tenant,
        ?User $user,
        string $mode = BulkImportBatch::MODE_INITIAL,
    ): BulkImportBatch {
        // Ensure the upload directory exists
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0750, true);
        }

        // Generate a unique filename to avoid collisions
        $originalName = $file->getClientOriginalName();
        $extension    = $file->getClientOriginalExtension() ?: 'bin';
        $storedName   = sprintf('%s_%s.%s', date('Ymd_His'), bin2hex(random_bytes(6)), $extension);
        $storedPath   = $this->uploadDir . DIRECTORY_SEPARATOR . $storedName;

        $file->move($this->uploadDir, $storedName);

        // Compute SHA-256 of the persisted file for tamper-evidence + dedup
        $fileHash = hash_file('sha256', $storedPath);
        if ($fileHash === false) {
            throw new \RuntimeException(sprintf('Could not compute SHA-256 for uploaded file: %s', $storedPath));
        }

        $fileSize = (string) filesize($storedPath);

        $batch = new BulkImportBatch();
        $batch->setTenant($tenant);
        $batch->setEntityType($entityType);
        $batch->setMode($mode);
        $batch->setStatus(BulkImportBatch::STATUS_UPLOADED);
        $batch->setSourceFileName($originalName);
        $batch->setSourceFileHash($fileHash);
        $batch->setSourceFileSize($fileSize);
        // $batch->setSourceDocument(null) — document linkage deferred to F2.6

        if ($user !== null) {
            $batch->setExecutedBy($user);
        }

        $this->em->persist($batch);
        $this->em->flush();

        return $batch;
    }

    // -------------------------------------------------------------------------
    // Step 2: preview
    // -------------------------------------------------------------------------

    /**
     * Parse the stored file, apply (or suggest) column mappings, run a
     * read-only delta calculation, and persist the resolved column mapping on
     * the batch (status → preview).
     *
     * @param array<string, string>|null $userColumnMapping
     *   When null the heuristic mapper suggests mappings automatically.
     *   When provided the user-supplied mapping is stored and used for the delta.
     */
    public function preview(BulkImportBatch $batch, ?array $userColumnMapping = null): DeltaResult
    {
        // Reconstruct the stored file path from hash + batch metadata.
        // The file is located in the upload dir; we find it by listing files
        // matching the batch's source-file hash (best-effort) or fall back to
        // scanning for a file with hash that matches.
        $storedPath = $this->resolveStoredFilePath($batch->getSourceFileHash());

        $parsed = $this->parser->parse($storedPath);

        // Determine final column mapping
        if ($userColumnMapping !== null) {
            $columnMapping = $userColumnMapping;
        } else {
            // Auto-suggest mappings from headers; transform suggestion map to
            // {sourceColumn => entityField} for downstream use
            $suggestions   = $this->headerMapper->suggestMappings($parsed->headers, $batch->getEntityType());
            $columnMapping = [];
            foreach ($suggestions as $header => $suggestion) {
                if (isset($suggestion['target'])) {
                    $columnMapping[$header] = $suggestion['target'];
                }
            }
        }

        $batch->setColumnMapping($columnMapping);
        $batch->setStatus(BulkImportBatch::STATUS_PREVIEW);
        $this->em->flush();

        $config = new DeltaConfig(
            entityType: $batch->getEntityType(),
            tenant: $batch->getTenant(),
            includeDeletes: false,
        );

        return $this->deltaCalculator->calculate($parsed, $config, $columnMapping);
    }

    // -------------------------------------------------------------------------
    // Step 3: dispatchCommit
    // -------------------------------------------------------------------------

    /**
     * Transition the batch to status=committing and dispatch an async
     * BulkImportMessage so the commit runs in a background worker.
     */
    public function dispatchCommit(BulkImportBatch $batch): void
    {
        $batch->setStatus(BulkImportBatch::STATUS_COMMITTING);
        $this->em->flush();

        $this->bus->dispatch(new BulkImportMessage($batch->getId()));
    }

    // -------------------------------------------------------------------------
    // Step 4: commit (called by BulkImportMessageHandler)
    // -------------------------------------------------------------------------

    /**
     * Synchronous commit: re-run delta, write entities, emit audit log, update
     * batch counters and status.
     *
     * Called by BulkImportMessageHandler — not intended for direct invocation
     * outside tests.
     *
     * @throws \Throwable on commit failure (batch status set to failed before re-throw)
     */
    public function commit(BulkImportBatch $batch): void
    {
        try {
            $this->em->wrapInTransaction(function () use ($batch): void {
                $this->doCommit($batch);
            });
        } catch (\Throwable $e) {
            // Status may already be failed if doCommit set it inside the
            // rolled-back transaction; set it again outside the transaction.
            $batch->setStatus(BulkImportBatch::STATUS_FAILED);
            $batch->setNotes($e->getMessage());

            // Use a fresh flush here — the EM may or may not be open depending
            // on whether the exception originated inside a nested flush.
            if ($this->em->isOpen()) {
                $this->em->flush();
            }

            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Inner commit logic executed inside wrapInTransaction().
     */
    private function doCommit(BulkImportBatch $batch): void
    {
        $storedPath = $this->resolveStoredFilePath($batch->getSourceFileHash());
        $parsed     = $this->parser->parse($storedPath);

        $config = new DeltaConfig(
            entityType: $batch->getEntityType(),
            tenant: $batch->getTenant(),
            includeDeletes: false,
        );

        $deltaResult = $this->deltaCalculator->calculate($parsed, $config, $batch->getColumnMapping());

        $mapper = $this->mapperRegistry->getMapperFor($batch->getEntityType());

        $rowCountSuccess = 0;
        $rowCountUpdated = 0;
        $rowCountSkipped = 0;
        $rowCountError   = 0;

        /** @var list<array{action: string, entity_id: int|null, new_values: array<string, mixed>, old_values: array<string, mixed>|null}> $perEntityForAudit */
        $perEntityForAudit = [];

        $flushedRowCount = 0;

        // Keep references that must survive flush+clear cycles
        $tenant = $batch->getTenant();

        // ── Creates ────────────────────────────────────────────────────────────
        foreach ($deltaResult->creates as $createRow) {
            $rowNumber = $createRow['rowNumber'];

            try {
                $entityData = $mapper->toEntityData($createRow['data'], $batch->getColumnMapping());
                $entity     = $this->instantiateEntity($batch->getEntityType(), $entityData, $tenant);

                $this->em->persist($entity);

                $bulkRow = $this->buildBulkRow($batch, $rowNumber, BulkImportRow::STATUS_CREATED, BulkImportRow::ACTION_CREATE, $createRow['data'], null, $entityData);
                $this->em->persist($bulkRow);

                $flushedRowCount++;
                $rowCountSuccess++;

                $perEntityForAudit[] = [
                    'action'     => 'create',
                    'entity_id'  => null, // ID available only after flush
                    'new_values' => $entityData,
                    'old_values' => null,
                ];
            } catch (\Throwable $e) {
                $this->persistErrorRow($batch, $rowNumber, $createRow['data'], $e->getMessage());
                $rowCountError++;
                $flushedRowCount++;
            }

            if ($flushedRowCount % self::BATCH_FLUSH_SIZE === 0) {
                $this->em->flush();
                $this->em->clear();
                // Re-merge batch and tenant after clear
                $batch  = $this->em->merge($batch) ?? $batch;
                $tenant = $this->em->merge($tenant) ?? $tenant;
            }
        }

        // ── Updates ────────────────────────────────────────────────────────────
        foreach ($deltaResult->updates as $updateRow) {
            $rowNumber = $updateRow['rowNumber'];

            try {
                $existing   = $mapper->findExisting($updateRow['data'], $tenant);
                $entityData = $mapper->toEntityData($updateRow['data'], $batch->getColumnMapping());

                if ($existing !== null) {
                    $this->applyEntityData($existing, $entityData);
                    $this->em->persist($existing);

                    $bulkRow = $this->buildBulkRow($batch, $rowNumber, BulkImportRow::STATUS_UPDATED, BulkImportRow::ACTION_UPDATE, $updateRow['data'], $updateRow['oldValues'], $entityData);
                    $bulkRow->setEntityId($existing->getId());
                    $this->em->persist($bulkRow);

                    $rowCountUpdated++;
                    $rowCountSuccess++;

                    $perEntityForAudit[] = [
                        'action'     => 'update',
                        'entity_id'  => $existing->getId(),
                        'new_values' => $entityData,
                        'old_values' => $updateRow['oldValues'],
                    ];
                } else {
                    // Entity disappeared between preview and commit — treat as skipped
                    $bulkRow = $this->buildBulkRow($batch, $rowNumber, BulkImportRow::STATUS_SKIPPED, BulkImportRow::ACTION_NOOP, $updateRow['data'], null, null);
                    $this->em->persist($bulkRow);
                    $rowCountSkipped++;
                }
            } catch (\Throwable $e) {
                $this->persistErrorRow($batch, $rowNumber, $updateRow['data'], $e->getMessage());
                $rowCountError++;
            }

            $flushedRowCount++;

            if ($flushedRowCount % self::BATCH_FLUSH_SIZE === 0) {
                $this->em->flush();
                $this->em->clear();
                $batch  = $this->em->merge($batch) ?? $batch;
                $tenant = $this->em->merge($tenant) ?? $tenant;
            }
        }

        // ── Unchanged ──────────────────────────────────────────────────────────
        foreach ($deltaResult->unchanged as $unchangedRow) {
            $rowNumber = $unchangedRow['rowNumber'];
            $bulkRow   = $this->buildBulkRow($batch, $rowNumber, BulkImportRow::STATUS_UNCHANGED, BulkImportRow::ACTION_NOOP, $unchangedRow['data'], null, null);
            $bulkRow->setEntityId($unchangedRow['entityId']);
            $this->em->persist($bulkRow);
            $rowCountSkipped++;
            $flushedRowCount++;

            if ($flushedRowCount % self::BATCH_FLUSH_SIZE === 0) {
                $this->em->flush();
                $this->em->clear();
                $batch  = $this->em->merge($batch) ?? $batch;
                $tenant = $this->em->merge($tenant) ?? $tenant;
            }
        }

        // ── Errors (validation failures from delta stage) ──────────────────────
        foreach ($deltaResult->errors as $errorRow) {
            $rowNumber    = $errorRow['rowNumber'];
            $errorMessage = implode('; ', $errorRow['errors']);
            $this->persistErrorRow($batch, $rowNumber, $errorRow['data'], $errorMessage);
            $rowCountError++;
            $flushedRowCount++;

            if ($flushedRowCount % self::BATCH_FLUSH_SIZE === 0) {
                $this->em->flush();
                $this->em->clear();
                $batch  = $this->em->merge($batch) ?? $batch;
                $tenant = $this->em->merge($tenant) ?? $tenant;
            }
        }

        // Final flush for remaining rows
        $this->em->flush();

        // ── Audit log (after successful flush) ─────────────────────────────────
        $rowCountTotal = $rowCountSuccess + $rowCountSkipped + $rowCountError;

        $batchId = $this->auditLogger->logBulk(
            eventType: 'bulk_import',
            entityType: $batch->getEntityType(),
            batchData: [
                'source_file_hash'    => $batch->getSourceFileHash(),
                'file_name'           => $batch->getSourceFileName(),
                'row_count_total'     => $rowCountTotal,
                'row_count_success'   => $rowCountSuccess,
                'row_count_skipped'   => $rowCountSkipped,
                'row_count_error'     => $rowCountError,
                'row_count_updated'   => $rowCountUpdated,
                'dry_run_result_hash' => $batch->getDryRunResultHash(),
                'mode'                => $batch->getMode(),
            ],
            perEntityData: $perEntityForAudit,
        );

        // ── Finalise batch ─────────────────────────────────────────────────────
        $batch->setBatchId($batchId);
        $batch->setRowCountTotal($rowCountTotal);
        $batch->setRowCountSuccess($rowCountSuccess);
        $batch->setRowCountSkipped($rowCountSkipped);
        $batch->setRowCountError($rowCountError);
        $batch->setRowCountUpdated($rowCountUpdated);
        $batch->setStatus(BulkImportBatch::STATUS_COMPLETED);
        $batch->setCommittedAt(new DateTimeImmutable());

        $this->em->persist($batch);
        $this->em->flush();
    }

    /**
     * Locate the stored file on disk by scanning the upload directory for a
     * file whose SHA-256 matches the batch's source-file hash.
     *
     * @throws \RuntimeException when no matching file is found
     */
    private function resolveStoredFilePath(string $fileHash): string
    {
        if (!is_dir($this->uploadDir)) {
            throw new \RuntimeException(sprintf(
                'Upload directory does not exist: %s',
                $this->uploadDir,
            ));
        }

        $iterator = new \DirectoryIterator($this->uploadDir);
        foreach ($iterator as $file) {
            if ($file->isFile() && hash_file('sha256', $file->getPathname()) === $fileHash) {
                return $file->getPathname();
            }
        }

        throw new \RuntimeException(sprintf(
            'No stored import file found for hash "%s" in directory "%s".',
            $fileHash,
            $this->uploadDir,
        ));
    }

    /**
     * Instantiate a new entity of the given type and apply property values via
     * setters (camelCase property name → setSomeProperty convention).
     *
     * The tenant property is always set if the entity has a setTenant() method.
     *
     * @param array<string, mixed> $entityData
     */
    private function instantiateEntity(string $entityType, array $entityData, Tenant $tenant): object
    {
        $fqcn   = 'App\\Entity\\' . $entityType;
        $entity = new $fqcn();

        // Inject tenant first so downstream setters have context
        if (method_exists($entity, 'setTenant')) {
            $entity->setTenant($tenant);
        }

        $this->applyEntityData($entity, $entityData);

        return $entity;
    }

    /**
     * Apply a flat property array to an existing entity via setter methods.
     *
     * Setter name: "set" + ucfirst($propertyName).
     * Properties whose setter does not exist are silently skipped.
     *
     * @param array<string, mixed> $entityData
     */
    private function applyEntityData(object $entity, array $entityData): void
    {
        foreach ($entityData as $property => $value) {
            $setter = 'set' . ucfirst($property);
            if (method_exists($entity, $setter)) {
                $entity->$setter($value);
            }
        }
    }

    /**
     * Build a BulkImportRow for a successfully processed row.
     *
     * @param array<string, mixed>      $parsedData
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    private function buildBulkRow(
        BulkImportBatch $batch,
        int $rowNumber,
        string $status,
        string $action,
        array $parsedData,
        ?array $oldValues,
        ?array $newValues,
    ): BulkImportRow {
        $row = new BulkImportRow();
        $row->setBatch($batch);
        $row->setRowNumber($rowNumber);
        $row->setStatus($status);
        $row->setAction($action);
        $row->setParsedData($parsedData);
        $row->setOldValues($oldValues);
        $row->setNewValues($newValues);

        return $row;
    }

    /**
     * Persist a BulkImportRow with status=error and the given error message.
     *
     * @param array<string, mixed> $parsedData
     */
    private function persistErrorRow(
        BulkImportBatch $batch,
        int $rowNumber,
        array $parsedData,
        string $errorMessage,
    ): void {
        $row = new BulkImportRow();
        $row->setBatch($batch);
        $row->setRowNumber($rowNumber);
        $row->setStatus(BulkImportRow::STATUS_ERROR);
        $row->setAction(null);
        $row->setParsedData($parsedData);
        $row->setErrorMessage($errorMessage);

        $this->em->persist($row);
    }
}
