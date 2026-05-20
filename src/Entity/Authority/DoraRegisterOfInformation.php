<?php

declare(strict_types=1);

namespace App\Entity\Authority;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Authority\DoraRegisterOfInformationRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * F30 — DORA Register of Information (RoI) submission record.
 *
 * Tracks each XBRL export / submission event for the DORA Article 28
 * Register of Information (RoI) that financial entities must maintain
 * and submit to their competent authority (e.g. BaFin, ECB, EBA).
 *
 * Per DORA Art. 28 and ESA Joint RTS 2024/xxx, financial entities must
 * maintain a register of all contractual arrangements with ICT third-party
 * service providers and report material changes. The yearly full submission
 * follows the ESA XBRL taxonomy.
 *
 * One record per (tenant, reportingDate) — enforced by unique constraint.
 *
 * Module gate: nis2_dora
 * ISO 27001: Clause 6.1 — supply chain information security
 * DORA: Art. 28 — register of information on ICT third-party providers
 */
#[ORM\Entity(repositoryClass: DoraRegisterOfInformationRepository::class)]
#[ORM\Table(name: 'dora_register_of_information')]
#[ORM\UniqueConstraint(name: 'uniq_tenant_date', columns: ['tenant_id', 'reporting_date'])]
#[ORM\Index(name: 'idx_dora_roi_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_dora_roi_reporting_date', columns: ['reporting_date'])]
#[ORM\HasLifecycleCallbacks]
class DoraRegisterOfInformation
{
    /** Scope: significant changes only (triggered report) */
    public const string SCOPE_SIGNIFICANT_CHANGES = 'significant_changes';

    /** Scope: full yearly submission (annual report) */
    public const string SCOPE_YEARLY_FULL = 'yearly_full';

    public const array VALID_SCOPES = [
        self::SCOPE_SIGNIFICANT_CHANGES,
        self::SCOPE_YEARLY_FULL,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(name: 'reporting_date', type: Types::DATE_IMMUTABLE)]
    private ?DateTimeImmutable $reportingDate = null;

    #[ORM\Column(name: 'reporting_scope', type: Types::STRING, length: 30)]
    private string $reportingScope = self::SCOPE_YEARLY_FULL;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $submittedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'submitted_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $submittedBy = null;

    /** SHA-256 hash of the XBRL payload for audit-trail integrity */
    #[ORM\Column(name: 'payload_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $payloadHash = null;

    /** Authority-issued confirmation number after successful submission */
    #[ORM\Column(name: 'confirmation_number', type: Types::STRING, length: 100, nullable: true)]
    private ?string $confirmationNumber = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new DateTimeImmutable();
    }

    // ─── Accessors ────────────────────────────────────────────────────────────

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

    public function getReportingDate(): ?DateTimeImmutable
    {
        return $this->reportingDate;
    }

    public function setReportingDate(?DateTimeImmutable $reportingDate): static
    {
        $this->reportingDate = $reportingDate;
        return $this;
    }

    public function getReportingScope(): string
    {
        return $this->reportingScope;
    }

    public function setReportingScope(string $reportingScope): static
    {
        if (!in_array($reportingScope, self::VALID_SCOPES, true)) {
            throw new \App\Exception\InvalidArgument\InvalidArgumentException(sprintf('Invalid reporting scope: %s', $reportingScope));
        }

        $this->reportingScope = $reportingScope;
        return $this;
    }

    public function getSubmittedAt(): ?DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;
        return $this;
    }

    public function getSubmittedBy(): ?User
    {
        return $this->submittedBy;
    }

    public function setSubmittedBy(?User $submittedBy): static
    {
        $this->submittedBy = $submittedBy;
        return $this;
    }

    public function getPayloadHash(): ?string
    {
        return $this->payloadHash;
    }

    public function setPayloadHash(?string $payloadHash): static
    {
        $this->payloadHash = $payloadHash;
        return $this;
    }

    public function getConfirmationNumber(): ?string
    {
        return $this->confirmationNumber;
    }

    public function setConfirmationNumber(?string $confirmationNumber): static
    {
        $this->confirmationNumber = $confirmationNumber;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    // ─── Domain helpers ───────────────────────────────────────────────────────

    /**
     * Returns true when this record has been marked as submitted to the authority.
     */
    public function isSubmitted(): bool
    {
        return $this->submittedAt !== null;
    }

    /**
     * Returns true when this record represents the current calendar year's submission.
     */
    public function isCurrentYear(): bool
    {
        if ($this->reportingDate === null) {
            return false;
        }

        return $this->reportingDate->format('Y') === (new DateTimeImmutable())->format('Y');
    }
}
