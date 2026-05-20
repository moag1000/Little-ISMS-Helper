<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PolicyAcknowledgementStatus;
use App\Repository\PolicyAcknowledgementRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-user acknowledgement of a published policy document.
 *
 * Closes the auditor's predicted ISO 27001 A.6.3 NC ("policy must be
 * communicated and acknowledged"). The wizard generates a CRON to
 * push acknowledgement requests for any Document with status=published
 * whose required-audience users haven't yet acknowledged. Captures
 * the document version at acknowledgement time so re-versions don't
 * silently void existing acks. See `05-architecture.md` §4.1.
 */
#[ORM\Entity(repositoryClass: PolicyAcknowledgementRepository::class)]
#[ORM\Table(name: 'policy_acknowledgement')]
#[ORM\UniqueConstraint(
    name: 'uq_policy_acknowledgement_tenant_doc_user_ver',
    columns: ['tenant_id', 'document_id', 'user_id', 'document_version'],
)]
#[ORM\Index(name: 'idx_policy_acknowledgement_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_policy_acknowledgement_document', columns: ['document_id'])]
#[ORM\Index(name: 'idx_policy_acknowledgement_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_policy_acknowledgement_status', columns: ['status'])]
class PolicyAcknowledgement
{
    /**
     * Audit V3 W2-C4 — explicit pending/completed state.
     *
     * Previously PolicyAcknowledgement was a "completion-only" row;
     * the auto-campaign listener could only log a campaign trigger,
     * not persist per-user audit-trail of "user X was asked to ack
     * version Y at time T". Adding STATUS_PENDING closes that gap.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACKNOWLEDGED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Document $document = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * Lifecycle status. STATUS_PENDING for rows created by the auto
     * acknowledgement-campaign listener, STATUS_ACKNOWLEDGED once the
     * user signed off via the inbox UI.
     */
    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_ACKNOWLEDGED;

    /**
     * When the campaign requested the acknowledgement (snapshot of
     * the audience at campaign time). Always set; equal to
     * acknowledgedAt for legacy rows that were created in completed
     * state directly via the inbox UI.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $requestedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $acknowledgedAt = null;

    /**
     * Acknowledgement method. Allowed values:
     *   web_click | email_token | training_pass | signed_pdf
     *
     * Nullable now: pending rows have no method yet.
     */
    #[ORM\Column(length: 24, nullable: true)]
    private ?string $acknowledgementMethod = null;

    /**
     * Document version captured at acknowledgement time so that a later
     * re-version does not silently invalidate this row.
     */
    #[ORM\Column(length: 32)]
    private ?string $documentVersion = null;

    /**
     * Optional source IP (audit trail). 45 chars covers IPv6.
     */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    public function __construct()
    {
        $now = new DateTimeImmutable();
        $this->requestedAt = $now;
        // Backwards-compat: existing call-sites construct + setAcknowledgedAt
        // immediately, so we keep populating acknowledgedAt on construct.
        // Auto-campaign callers explicitly downgrade to pending via setStatus().
        $this->acknowledgedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getTenantId(): ?int
    {
        return $this->tenant?->getId();
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(?Document $document): static
    {
        $this->document = $document;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(PolicyAcknowledgementStatus|string $status): static
    {
        // Accept both enum and string so new code can pass the typed enum
        // while existing string-passing callers keep working unchanged.
        $value = is_string($status) ? $status : $status->value;
        if (!in_array($value, self::ALLOWED_STATUSES, true)) {
            throw new \App\Exception\InvalidArgument\InvalidArgumentException(sprintf(
                'Invalid PolicyAcknowledgement status "%s". Allowed: %s',
                $value,
                implode(', ', self::ALLOWED_STATUSES),
            ));
        }
        $this->status = $value;
        return $this;
    }

    /** Typed status surface for enum-aware code. */
    public function getStatusEnum(): PolicyAcknowledgementStatus
    {
        return PolicyAcknowledgementStatus::from($this->status);
    }

    public function getRequestedAt(): ?DateTimeInterface
    {
        return $this->requestedAt;
    }

    public function setRequestedAt(DateTimeInterface $requestedAt): static
    {
        $this->requestedAt = $requestedAt;
        return $this;
    }

    public function getAcknowledgedAt(): ?DateTimeInterface
    {
        return $this->acknowledgedAt;
    }

    public function setAcknowledgedAt(?DateTimeInterface $acknowledgedAt): static
    {
        $this->acknowledgedAt = $acknowledgedAt;
        return $this;
    }

    public function getAcknowledgementMethod(): ?string
    {
        return $this->acknowledgementMethod;
    }

    public function setAcknowledgementMethod(?string $acknowledgementMethod): static
    {
        $this->acknowledgementMethod = $acknowledgementMethod;
        return $this;
    }

    public function getDocumentVersion(): ?string
    {
        return $this->documentVersion;
    }

    public function setDocumentVersion(string $documentVersion): static
    {
        $this->documentVersion = $documentVersion;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }
}
