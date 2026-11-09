<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\FourEyesApprovalRequest;
use App\Entity\User;
use App\Enum\FourEyesApprovalRequestStatus;
use App\Repository\FourEyesApprovalRequestRepository;
use App\Service\AuditLogger;
use App\Service\CompliancePolicyService;
use App\Service\FourEyesApprovalService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Four eyes for a large compliance import (W9): from the organisation's
 * "Import 4-Augen-Pflicht ab Zeilen" (Admin > Compliance-Richtlinien, 0 = off)
 * the commit needs an approval of exactly this file by a second person.
 *
 * The approval is a {@see FourEyesApprovalRequest} of type
 * `import_large_commit` bound to the file's SHA-256: another file, a pending,
 * rejected or expired request, or one already used for a commit does not count.
 * The commit consumes it, so one approval opens one commit.
 */
final class ImportCommitApproval
{
    public const string STATE_NOT_REQUIRED = 'not_required';
    public const string STATE_MISSING = 'missing';
    public const string STATE_PENDING = 'pending';
    public const string STATE_APPROVED = 'approved';

    public function __construct(
        private readonly CompliancePolicyService $policy,
        private readonly FourEyesApprovalService $fourEyes,
        private readonly FourEyesApprovalRequestRepository $repository,
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function threshold(): int
    {
        return $this->policy->getInt(CompliancePolicyService::KEY_IMPORT_FOUR_EYES_ROW_THRESHOLD, 50);
    }

    public function isRequired(int $rows): bool
    {
        return $this->threshold() > 0 && $rows >= $this->threshold();
    }

    /** One of the STATE_* constants for this file. */
    public function state(string $filePath, int $rows): string
    {
        if (!$this->isRequired($rows)) {
            return self::STATE_NOT_REQUIRED;
        }

        $request = $this->latestFor($filePath);
        if ($request?->isPending() === true) {
            return self::STATE_PENDING;
        }

        // none, rejected or expired (also before the nightly clean-up marked it) → ask again
        return $request?->getStatus() === FourEyesApprovalRequestStatus::Approved->value
            ? self::STATE_APPROVED
            : self::STATE_MISSING;
    }

    /** Ask a second person to approve the commit of this file. */
    public function request(string $filePath, int $rows, string $fileName, User $requester): FourEyesApprovalRequest
    {
        return $this->fourEyes->requestApproval(
            FourEyesApprovalRequest::ACTION_IMPORT_LARGE_COMMIT,
            ['file_sha256' => hash_file('sha256', $filePath), 'rows' => $rows, 'file_name' => $fileName],
            $requester,
        );
    }

    /** Mark the approval of this file as used — called right after the commit. */
    public function consume(string $filePath): void
    {
        $request = $this->latestFor($filePath);
        if ($request === null || $request->getStatus() !== FourEyesApprovalRequestStatus::Approved->value) {
            return;
        }
        $request->setPayload($request->getPayload() + ['consumed_at' => (new DateTimeImmutable())->format(DATE_ATOM)]);
        $this->entityManager->flush();
        $this->auditLogger->logCustom(
            'four_eyes.consumed',
            'FourEyesApprovalRequest',
            $request->getId(),
            null,
            ['action_type' => FourEyesApprovalRequest::ACTION_IMPORT_LARGE_COMMIT],
            '4-eyes approval used for the import commit',
        );
    }

    /** The newest unused request of the current organisation for this exact file. */
    private function latestFor(string $filePath): ?FourEyesApprovalRequest
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $hash = is_file($filePath) ? hash_file('sha256', $filePath) : false;
        if ($tenant === null || $hash === false) {
            return null;
        }
        $requests = $this->repository->findByActionType($tenant, FourEyesApprovalRequest::ACTION_IMPORT_LARGE_COMMIT);
        foreach ($requests as $request) {
            $payload = $request->getPayload();
            if (($payload['file_sha256'] ?? null) === $hash && !isset($payload['consumed_at'])) {
                return $request;
            }
        }

        return null;
    }
}
