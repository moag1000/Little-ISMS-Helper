<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditFreezeRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit Freeze Entity
 *
 * Tamper-evident compliance snapshot frozen at a specific cut-off date
 * (Stichtag) for certification / surveillance audits.
 *
 * Captures SoA state, per-framework requirement fulfillments, risks and KPIs
 * at the chosen Stichtag and stores a SHA-256 hash of the payload. The hash
 * lets an auditor verify — weeks or months later — that the snapshot has not
 * been tampered with after the freeze.
 *
 * By-design immutable: there is no update / delete endpoint. A correction
 * produces a new freeze with a new name.
 *
 * @see docs/CM_JUNIOR_RESPONSE.md CM-8
 */
#[ORM\Entity(repositoryClass: AuditFreezeRepository::class)]
#[ORM\Table(name: 'audit_freeze')]
#[ORM\UniqueConstraint(
    name: 'uniq_audit_freeze_tenant_date_name',
    columns: ['tenant_id', 'stichtag', 'freeze_name']
)]
#[ORM\Index(name: 'idx_audit_freeze_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_audit_freeze_stichtag', columns: ['tenant_id', 'stichtag'])]
class AuditFreeze
{
    public const PURPOSE_CERTIFICATION = 'certification';
    public const PURPOSE_SURVEILLANCE = 'surveillance';
    public const PURPOSE_INTERNAL_AUDIT = 'internal_audit';
    public const PURPOSE_MANAGEMENT_REVIEW = 'management_review';
    public const PURPOSE_OTHER = 'other';

    public const PURPOSES = [
        self::PURPOSE_CERTIFICATION,
        self::PURPOSE_SURVEILLANCE,
        self::PURPOSE_INTERNAL_AUDIT,
        self::PURPOSE_MANAGEMENT_REVIEW,
        self::PURPOSE_OTHER,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(name: 'freeze_name', length: 200)]
    private string $freezeName;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $stichtag;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $createdBy;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $frameworkCodes = [];

    #[ORM\Column(length: 50)]
    private string $purpose = self::PURPOSE_SURVEILLANCE;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * Snapshot payload — see AuditFreezeSnapshotBuilder::build() for shape.
     *
     * @var array<string,mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $payloadJson = [];

    /**
     * SHA-256 over the canonical JSON encoding of payloadJson.
     * 64 hex chars. Used for tamper detection.
     */
    #[ORM\Column(length: 64)]
    private string $payloadSha256;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $pdfGeneratedAt = null;

    /**
     * Path of the generated PDF relative to %kernel.project_dir%/var/audit_freezes.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pdfPath = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->payloadSha256 = str_repeat('0', 64);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getFreezeName(): string
    {
        return $this->freezeName;
    }

    public function setFreezeName(string $freezeName): static
    {
        $this->freezeName = $freezeName;
        return $this;
    }

    public function getStichtag(): DateTimeImmutable
    {
        return $this->stichtag;
    }

    public function setStichtag(DateTimeInterface $stichtag): static
    {
        $this->stichtag = $stichtag instanceof DateTimeImmutable
            ? $stichtag
            : DateTimeImmutable::createFromInterface($stichtag);
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    /**
     * @return list<string>
     */
    public function getFrameworkCodes(): array
    {
        return $this->frameworkCodes;
    }

    /**
     * @param list<string> $frameworkCodes
     */
    public function setFrameworkCodes(array $frameworkCodes): static
    {
        // normalise: string, unique, values only
        $clean = [];
        foreach ($frameworkCodes as $code) {
            if (is_string($code) && $code !== '' && !in_array($code, $clean, true)) {
                $clean[] = $code;
            }
        }
        $this->frameworkCodes = $clean;
        return $this;
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function setPurpose(string $purpose): static
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid purpose "%s". Allowed: %s',
                $purpose,
                implode(', ', self::PURPOSES)
            ));
        }
        $this->purpose = $purpose;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    /**
     * @return array<string,mixed>
     */
    public function getPayloadJson(): array
    {
        return $this->payloadJson;
    }

    /**
     * @param array<string,mixed> $payloadJson
     */
    public function setPayloadJson(array $payloadJson): static
    {
        $this->payloadJson = $payloadJson;
        return $this;
    }

    public function getPayloadSha256(): string
    {
        return $this->payloadSha256;
    }

    public function setPayloadSha256(string $payloadSha256): static
    {
        $this->payloadSha256 = $payloadSha256;
        return $this;
    }

    public function getPdfGeneratedAt(): ?DateTimeImmutable
    {
        return $this->pdfGeneratedAt;
    }

    public function setPdfGeneratedAt(?DateTimeImmutable $pdfGeneratedAt): static
    {
        $this->pdfGeneratedAt = $pdfGeneratedAt;
        return $this;
    }

    public function getPdfPath(): ?string
    {
        return $this->pdfPath;
    }

    public function setPdfPath(?string $pdfPath): static
    {
        $this->pdfPath = $pdfPath;
        return $this;
    }

    public function hasPdf(): bool
    {
        return $this->pdfPath !== null && $this->pdfGeneratedAt !== null;
    }
}
