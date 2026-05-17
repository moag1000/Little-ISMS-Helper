<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Tenant;
use App\Entity\User;
use App\Exception\Tenant\TenantOrphanException;
use App\Repository\PolicyAcknowledgementRepository;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Per-user acknowledgement collection service for published policies.
 *
 * Closes the auditor's predicted ISO 27001 A.6.3 NC ("policy must be
 * communicated and acknowledged"). Pairs with W3-D's
 * `PolicyAcknowledgementCoverageCheck` which queries this data via the
 * repository.
 *
 * Responsibilities:
 *   - request acknowledgements when a policy goes public (pre-flight,
 *     idempotent — a re-run does not create stale rows nor duplicate);
 *   - record acknowledgements with method + IP + version snapshot;
 *   - produce coverage statistics for the W3-D check + dashboard widget.
 *
 * The service does NOT send emails / push notifications itself; that is
 * the responsibility of the caller (typically a CRON command). The
 * "request" call materialises the audit-trail "audience snapshot" so a
 * later auditor can verify that user X _was_ in scope at time T even
 * if they leave the company before acknowledging.
 *
 * Spec: ISO 27001 A.5.1, A.6.3 + ISO 27002 §6.3 awareness.
 */
final class PolicyAcknowledgementService
{
    public const METHOD_WEB_CLICK = 'web_click';
    public const METHOD_EMAIL_TOKEN = 'email_token';
    public const METHOD_TRAINING_PASS = 'training_pass';
    public const METHOD_SIGNED_PDF = 'signed_pdf';

    private const ALLOWED_METHODS = [
        self::METHOD_WEB_CLICK,
        self::METHOD_EMAIL_TOKEN,
        self::METHOD_TRAINING_PASS,
        self::METHOD_SIGNED_PDF,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PolicyAcknowledgementRepository $repository,
        private readonly PolicyAudienceResolver $audienceResolver,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Request acknowledgements for a freshly published Document. Idempotent:
     * users that already acknowledged the current document version are
     * skipped, no new row is created, no notification is emitted.
     *
     * Returns the number of users that still owe an acknowledgement after
     * this call (i.e. the "pending" count) — the caller can use this to
     * decide whether a notification round is worth firing.
     *
     * @param list<User> $audience explicit audience override; falls back
     *                             to {@see PolicyAudienceResolver} when null/empty.
     */
    public function requestAcknowledgements(Document $document, array $audience = []): int
    {
        $tenant = $document->getTenant();
        if (!$tenant instanceof Tenant) {
            throw new TenantOrphanException(null, 'Document must be tenant-scoped before acknowledgement requests can be raised.');
        }

        if ($audience === []) {
            $audience = $this->audienceResolver->resolveAudience($document);
        }

        $version = $this->resolveDocumentVersion($document);
        $pending = 0;

        foreach ($audience as $user) {
            if (!$user instanceof User) {
                continue;
            }
            if (!$user->isActive()) {
                continue;
            }
            $existing = $this->repository->findOneFor($tenant, $document, $user, $version);
            // Audit V3 W2-C4: PENDING rows still count toward "pending"
            // (the user owes an acknowledgement); only ACKNOWLEDGED
            // rows close the loop.
            if ($existing instanceof PolicyAcknowledgement
                && $existing->getStatus() === PolicyAcknowledgement::STATUS_ACKNOWLEDGED) {
                continue;
            }
            $pending++;
        }

        return $pending;
    }

    /**
     * Record a single acknowledgement. Throws when the unique constraint
     * (tenant, document, user, version) is already satisfied.
     */
    public function acknowledge(
        Document $document,
        User $user,
        string $method,
        ?string $ipAddress = null,
    ): PolicyAcknowledgement {
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Acknowledgement method "%s" is not supported. Allowed: %s.',
                $method,
                implode(', ', self::ALLOWED_METHODS),
            ));
        }
        $tenant = $document->getTenant();
        if (!$tenant instanceof Tenant) {
            throw new TenantOrphanException(null, 'Document must be tenant-scoped to be acknowledged.');
        }

        $version = $this->resolveDocumentVersion($document);

        $existing = $this->repository->findOneFor($tenant, $document, $user, $version);

        // Audit V3 W2-C4: an existing PENDING row (from the auto-campaign)
        // is upgraded in-place to ACKNOWLEDGED. Only an existing
        // ACKNOWLEDGED row blocks the call.
        if ($existing instanceof PolicyAcknowledgement
            && $existing->getStatus() === PolicyAcknowledgement::STATUS_ACKNOWLEDGED) {
            throw new RuntimeException(sprintf(
                'User %d has already acknowledged Document %d at version %s.',
                $user->getId() ?? -1,
                $document->getId() ?? -1,
                $version,
            ));
        }

        $ack = $existing instanceof PolicyAcknowledgement ? $existing : new PolicyAcknowledgement();
        $ack->setTenant($tenant);
        $ack->setDocument($document);
        $ack->setUser($user);
        $ack->setStatus(PolicyAcknowledgement::STATUS_ACKNOWLEDGED);
        $ack->setAcknowledgedAt(new DateTimeImmutable());
        $ack->setAcknowledgementMethod($method);
        $ack->setDocumentVersion($version);
        $ack->setIpAddress($ipAddress);

        if (!$ack->getId()) {
            $this->entityManager->persist($ack);
        }
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'policy-acknowledgement',
            entityType: 'PolicyAcknowledgement',
            entityId: $ack->getId(),
            newValues: [
                'document_id' => $document->getId(),
                'user_id' => $user->getId(),
                'method' => $method,
                'document_version' => $version,
            ],
            description: sprintf(
                'User %s acknowledged document #%d (v%s) via %s',
                (string) $user->getEmail(),
                (int) $document->getId(),
                $version,
                $method,
            ),
        );

        return $ack;
    }

    /**
     * Coverage tally for a single Document. Returns:
     *   acknowledged       — number of distinct users that acknowledged
     *                        the current version of the document;
     *   pending            — audience size minus acknowledged;
     *   percent            — round((acknowledged / audience) * 100, 1);
     *                        100.0 when audience is zero (audit-defang
     *                        for empty/sandbox tenants);
     *   audience_user_ids  — list<int> of resolved audience user IDs.
     *
     * @return array{acknowledged: int, pending: int, percent: float, audience_user_ids: list<int>}
     */
    public function coverageFor(Document $document): array
    {
        $audience = $this->audienceResolver->resolveAudience($document);
        $audienceIds = [];
        foreach ($audience as $user) {
            $id = $user->getId();
            if ($id !== null) {
                $audienceIds[] = $id;
            }
        }
        $audienceSize = count($audienceIds);

        if ($audienceSize === 0) {
            return [
                'acknowledged' => 0,
                'pending' => 0,
                'percent' => 100.0,
                'audience_user_ids' => [],
            ];
        }

        $version = $this->resolveDocumentVersion($document);
        $tenant = $document->getTenant();

        $acknowledged = 0;
        if ($tenant instanceof Tenant) {
            foreach ($audience as $user) {
                $existing = $this->repository->findOneFor($tenant, $document, $user, $version);
                // Audit V3 W2-C4: only ACKNOWLEDGED rows count toward coverage —
                // pending campaign rows are tracked but unsigned.
                if ($existing instanceof PolicyAcknowledgement
                    && $existing->getStatus() === PolicyAcknowledgement::STATUS_ACKNOWLEDGED) {
                    $acknowledged++;
                }
            }
        }

        $pending = $audienceSize - $acknowledged;
        $percent = round(($acknowledged / $audienceSize) * 100.0, 1);

        return [
            'acknowledged' => $acknowledged,
            'pending' => $pending,
            'percent' => $percent,
            'audience_user_ids' => $audienceIds,
        ];
    }

    /**
     * Pending documents for a single user — drives the inbox UX.
     *
     * @return list<Document>
     */
    public function pendingDocumentsForUser(User $user): array
    {
        $tenant = $user->getTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        // Walk all published documents in the tenant; check audience
        // membership; skip already-acked. The lookup is idempotent and
        // safe to run on every page-load (small dataset — published
        // policies are dozens, not thousands).
        $documents = $this->entityManager->getRepository(Document::class)
            ->createQueryBuilder('d')
            ->where('d.tenant = :tenant')
            ->andWhere('d.status IN (:statuses)')
            ->andWhere('d.isArchived = false')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', ['published', 'approved'])
            ->getQuery()
            ->getResult();

        $pending = [];
        foreach ($documents as $document) {
            if (!$document instanceof Document) {
                continue;
            }
            $audience = $this->audienceResolver->resolveAudience($document);
            $isInAudience = false;
            foreach ($audience as $candidate) {
                if ($candidate->getId() === $user->getId()) {
                    $isInAudience = true;
                    break;
                }
            }
            if (!$isInAudience) {
                continue;
            }
            $version = $this->resolveDocumentVersion($document);
            $existing = $this->repository->findOneFor($tenant, $document, $user, $version);
            // Audit V3 W2-C4: PENDING rows still count as "pending"
            // (auto-campaign created an audit-trail row but the user
            // hasn't clicked through). Only ACKNOWLEDGED rows close
            // the loop.
            if ($existing instanceof PolicyAcknowledgement
                && $existing->getStatus() === PolicyAcknowledgement::STATUS_ACKNOWLEDGED) {
                continue;
            }
            $pending[] = $document;
        }
        return $pending;
    }

    /**
     * Stable per-document version string consumed by the unique constraint.
     * Tries PolicyTemplate.version (when document was generated by the
     * Policy-Wizard) and falls back to the SHA256 hash on the document
     * (uploadedAt-stamped, monotonically non-decreasing for re-versions).
     */
    private function resolveDocumentVersion(Document $document): string
    {
        $vars = $document->getSubstitutionVariables();
        if (is_array($vars) && isset($vars['_template_version']) && (is_int($vars['_template_version']) || is_string($vars['_template_version']))) {
            return (string) $vars['_template_version'];
        }
        $hash = $document->getSha256Hash();
        if (is_string($hash) && $hash !== '') {
            return substr($hash, 0, 16);
        }
        // Last resort — uploadedAt timestamp; columns are length 32 so
        // we keep the value compact.
        $uploadedAt = $document->getUploadedAt();
        if ($uploadedAt !== null) {
            return $uploadedAt->format('YmdHis');
        }
        return '1';
    }
}
