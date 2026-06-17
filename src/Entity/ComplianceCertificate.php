<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ComplianceCertificateRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * ComplianceCertificate
 *
 * Represents an external compliance certificate (e.g. ISO 27001, SOC 2, TISAX)
 * held by a tenant. A single certificate record can bulk-fulfil multiple
 * compliance controls in the coverage resolution pipeline.
 *
 * Multi-Tenancy: Each record is scoped to exactly one tenant via `tenant_id`.
 */
#[ORM\Entity(repositoryClass: ComplianceCertificateRepository::class)]
#[ORM\Table(name: 'compliance_certificate')]
#[ORM\Index(name: 'idx_cert_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_cert_framework', columns: ['framework_code'])]
#[ORM\Index(name: 'idx_cert_status', columns: ['status'])]
class ComplianceCertificate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The tenant this certificate belongs to.
     */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    /**
     * Framework code this certificate covers, e.g. "iso27001", "soc2", "tisax".
     */
    #[ORM\Column(length: 100)]
    private string $frameworkCode = '';

    /**
     * Name of the certification body that issued the certificate.
     */
    #[ORM\Column(length: 255)]
    private string $certBody = '';

    /**
     * Certificate number / registration number issued by the cert body.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $certNumber = null;

    /**
     * Human-readable scope description as printed on the certificate.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $scopeText = null;

    /**
     * Machine-readable scope tags for coverage-rule matching (e.g. ["cloud", "de"]).
     *
     * @var array<int|string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $scopeTags = [];

    /**
     * Certificate class / level (e.g. "TISAX-3", "SOC2-TypeII").
     * Maps to DB column `class` (reserved word — property uses certClass).
     */
    #[ORM\Column(name: 'class', length: 100, nullable: true)]
    private ?string $certClass = null;

    /**
     * Date the certificate was issued.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $issueDate = null;

    /**
     * Date the certificate expires / is valid until.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $validUntil = null;

    /**
     * Name of the certified legal entity / scope holder.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $holder = null;

    /**
     * Lifecycle status of this certificate record.
     * Allowed: active, expired, revoked, pending.
     */
    #[ORM\Column(length: 50)]
    private string $status = 'active';

    /**
     * Optional link to the uploaded certificate document.
     */
    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $certificateDocument = null;

    /**
     * How this certificate record was created: "manual" | "upload" | "api".
     */
    #[ORM\Column(length: 50)]
    private string $extractionSource = 'manual';

    /**
     * Confidence score (0.0–1.0) when the record was created by automated
     * extraction (OCR/AI). Null for manual entries.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $extractionConfidence = null;

    /**
     * User who uploaded or created this certificate record.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    /**
     * Timestamp when this record was created.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    // ── Getters / Setters ────────────────────────────────────────────────────

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

    public function getFrameworkCode(): string
    {
        return $this->frameworkCode;
    }

    public function setFrameworkCode(string $frameworkCode): static
    {
        $this->frameworkCode = $frameworkCode;
        return $this;
    }

    public function getCertBody(): string
    {
        return $this->certBody;
    }

    public function setCertBody(string $certBody): static
    {
        $this->certBody = $certBody;
        return $this;
    }

    public function getCertNumber(): ?string
    {
        return $this->certNumber;
    }

    public function setCertNumber(?string $certNumber): static
    {
        $this->certNumber = $certNumber;
        return $this;
    }

    public function getScopeText(): ?string
    {
        return $this->scopeText;
    }

    public function setScopeText(?string $scopeText): static
    {
        $this->scopeText = $scopeText;
        return $this;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getScopeTags(): array
    {
        return $this->scopeTags;
    }

    /**
     * @param array<int|string, mixed> $scopeTags
     */
    public function setScopeTags(array $scopeTags): static
    {
        $this->scopeTags = $scopeTags;
        return $this;
    }

    public function getCertClass(): ?string
    {
        return $this->certClass;
    }

    public function setCertClass(?string $certClass): static
    {
        $this->certClass = $certClass;
        return $this;
    }

    public function getIssueDate(): ?DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function setIssueDate(?DateTimeImmutable $issueDate): static
    {
        $this->issueDate = $issueDate;
        return $this;
    }

    public function getValidUntil(): ?DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function setValidUntil(?DateTimeImmutable $validUntil): static
    {
        $this->validUntil = $validUntil;
        return $this;
    }

    public function getHolder(): ?string
    {
        return $this->holder;
    }

    public function setHolder(?string $holder): static
    {
        $this->holder = $holder;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCertificateDocument(): ?Document
    {
        return $this->certificateDocument;
    }

    public function setCertificateDocument(?Document $certificateDocument): static
    {
        $this->certificateDocument = $certificateDocument;
        return $this;
    }

    public function getExtractionSource(): string
    {
        return $this->extractionSource;
    }

    public function setExtractionSource(string $extractionSource): static
    {
        $this->extractionSource = $extractionSource;
        return $this;
    }

    public function getExtractionConfidence(): ?float
    {
        return $this->extractionConfidence;
    }

    public function setExtractionConfidence(?float $extractionConfidence): static
    {
        $this->extractionConfidence = $extractionConfidence;
        return $this;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    // ── Domain logic ─────────────────────────────────────────────────────────

    /**
     * Returns true when the certificate has a validUntil date that lies before $now.
     */
    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->validUntil !== null && $this->validUntil < $now;
    }
}
