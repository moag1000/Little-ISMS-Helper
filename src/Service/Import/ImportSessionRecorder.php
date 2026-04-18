<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\ImportRowEvent;
use App\Entity\ImportSession;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * ISB-Review Sprint-2 gate MINOR-1 (docs/DATA_REUSE_PLAN_REVIEW_ISB.md):
 * persists per-row audit events for every compliance-mapping import.
 *
 * Lifecycle:
 *   - openSession()  : hashes the file, stores header, status=preview.
 *   - recordRow()    : one row event per CSV/XML line (flushes every 100).
 *   - closeSession() : rolls up counters, stamps committedAt on commit.
 *
 * JSON payloads (beforeState / afterState / sourceRowRaw) are truncated at
 * the 4 KB boundary before encoding — matches the AuditLogger sanitize
 * pattern to keep the audit tables bounded.
 */
final class ImportSessionRecorder
{
    /** Match AuditLogger: keep audit rows bounded. */
    public const MAX_PAYLOAD_BYTES = 4096;

    private const FLUSH_BATCH_SIZE = 100;

    /** @var array<int, int> session-id → pending row count since last flush */
    private array $pendingByCount = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Create an ImportSession header for a freshly uploaded file.
     *
     * @param UploadedFile|string $sourcePath UploadedFile or absolute path to
     *                                        an already-stored copy of the file.
     */
    public function openSession(
        UploadedFile|string $sourcePath,
        string $format,
        string $originalName,
        ?User $user,
        Tenant $tenant,
    ): ImportSession {
        $realPath = $sourcePath instanceof UploadedFile
            ? (string) $sourcePath->getRealPath()
            : $sourcePath;

        if (!is_file($realPath) || !is_readable($realPath)) {
            throw new RuntimeException(sprintf(
                'ImportSessionRecorder: file not readable at %s',
                $realPath,
            ));
        }

        $hash = hash_file('sha256', $realPath);
        if ($hash === false) {
            throw new RuntimeException('ImportSessionRecorder: sha256 hash failed.');
        }

        $size = @filesize($realPath);

        $session = (new ImportSession())
            ->setTenant($tenant)
            ->setUploadedBy($user)
            ->setUploadedAt(new DateTimeImmutable())
            ->setOriginalFilename($originalName)
            ->setStoredFilename(basename($realPath))
            ->setFileSha256($hash)
            ->setFileSizeBytes($size === false ? 0 : (int) $size)
            ->setFormat($format)
            ->setStatus(ImportSession::STATUS_PREVIEW);

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    /**
     * Persist one ImportRowEvent. Flushes every FLUSH_BATCH_SIZE rows.
     *
     * @param array<string, mixed>|null $beforeState
     * @param array<string, mixed>|null $afterState
     * @param array<string, mixed>|null $sourceRowRaw
     */
    public function recordRow(
        ImportSession $session,
        int $lineNumber,
        string $decision,
        ?string $targetEntityType,
        ?int $targetEntityId,
        ?array $beforeState,
        ?array $afterState,
        ?array $sourceRowRaw,
        ?string $errorMessage = null,
    ): ImportRowEvent {
        $event = (new ImportRowEvent())
            ->setSession($session)
            ->setLineNumber($lineNumber)
            ->setDecision($decision)
            ->setTargetEntityType($targetEntityType)
            ->setTargetEntityId($targetEntityId)
            ->setBeforeState($this->encodePayload($beforeState))
            ->setAfterState($this->encodePayload($afterState))
            ->setSourceRowRaw($this->encodePayload($sourceRowRaw))
            ->setErrorMessage($errorMessage);

        $this->entityManager->persist($event);

        $sessionId = (int) $session->getId();
        $this->pendingByCount[$sessionId] = ($this->pendingByCount[$sessionId] ?? 0) + 1;

        if ($this->pendingByCount[$sessionId] >= self::FLUSH_BATCH_SIZE) {
            $this->entityManager->flush();
            $this->pendingByCount[$sessionId] = 0;
        }

        return $event;
    }

    /**
     * Roll up counters + persist final session status.
     *
     * $status must be one of ImportSession::STATUS_*. When committed the
     * $committedAt timestamp is set to now.
     */
    public function closeSession(ImportSession $session, string $status): void
    {
        $sessionId = (int) $session->getId();

        // Force a flush so all pending row events are counted below.
        if (($this->pendingByCount[$sessionId] ?? 0) > 0) {
            $this->entityManager->flush();
            $this->pendingByCount[$sessionId] = 0;
        }

        /** @var \App\Repository\ImportRowEventRepository $eventRepo */
        $eventRepo = $this->entityManager->getRepository(ImportRowEvent::class);

        $imported = $eventRepo->countBySession($session, ImportRowEvent::DECISION_IMPORT);
        $updated = $eventRepo->countBySession($session, ImportRowEvent::DECISION_UPDATE);
        $merged = $eventRepo->countBySession($session, ImportRowEvent::DECISION_MERGE);
        $skipped = $eventRepo->countBySession($session, ImportRowEvent::DECISION_SKIP);
        $errored = $eventRepo->countBySession($session, ImportRowEvent::DECISION_ERROR);

        $session->setRowCountImported($imported + $merged);
        // "superseded" in the existing UI = replaced existing mapping; we
        // treat DECISION_UPDATE as a superseded count for the roll-up.
        $session->setRowCountSuperseded($updated);
        $session->setRowCountSkipped($skipped + $errored);
        $session->setRowCountTotal($imported + $updated + $merged + $skipped + $errored);

        $session->setStatus($status);
        if ($status === ImportSession::STATUS_COMMITTED) {
            $session->setCommittedAt(new DateTimeImmutable());
        }

        $this->entityManager->flush();
    }

    /**
     * Encode an arbitrary array to a JSON string, truncating at 4 KB.
     *
     * The truncation guarantees the stored payload never exceeds the
     * MAX_PAYLOAD_BYTES budget, even if JSON encoding expands the input.
     */
    private function encodePayload(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return null;
        }

        if (strlen($encoded) <= self::MAX_PAYLOAD_BYTES) {
            return $encoded;
        }

        // Cut at the byte budget and append a truncation marker that keeps
        // the string valid UTF-8 (no invalid multibyte boundary).
        $truncated = mb_strcut($encoded, 0, self::MAX_PAYLOAD_BYTES - 32, 'UTF-8');

        return $truncated . '...[truncated]';
    }
}
