<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SoaSnapshotRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Statement-of-Applicability point-in-time snapshot.
 *
 * Closes the persona-walkthrough gap surfaced by ISB +
 * Auditor-External: "kein Point-in-Time-Export Bundle wie zum
 * Stichtag X; nur Live-Export." Snapshots freeze the SoA state at
 * a chosen `asOfDate` so the certification bundle can be exported
 * deterministically against the audit cut-off — even if controls,
 * approvers or evidence documents change after that date.
 *
 * Immutability contract:
 *   - Once persisted, no UPDATE is permitted (no setters that mutate
 *     payload, asOfDate, checksum or createdBy after creation).
 *   - The `payload` JSON column is the canonical record; the
 *     `checksumSha256` is computed deterministically from the JSON
 *     so any tampering is detectable.
 *   - Audit-Event `soa_snapshot_created` is emitted on creation.
 *
 * Multi-tenancy: tenant_id is mandatory and indexed together with
 * asOfDate (`idx_soa_snapshot_tenant_asof`) for fast lookup of
 * "snapshot for tenant X as-of date Y".
 */
#[ORM\Entity(repositoryClass: SoaSnapshotRepository::class)]
#[ORM\Table(name: 'soa_snapshot')]
#[ORM\Index(name: 'idx_soa_snapshot_tenant_asof', columns: ['tenant_id', 'as_of_date'])]
#[ORM\Index(name: 'idx_soa_snapshot_created_at', columns: ['created_at'])]
class SoaSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * Effective as-of date the snapshot represents. Date only (no
     * time component) — the audit cut-off is by convention end-of-day
     * in the tenant's local TZ. Resolution logic (which Document
     * version was current, which approval was the latest one) treats
     * `asOfDate` as the inclusive upper bound.
     */
    #[ORM\Column(name: 'as_of_date', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $asOfDate;

    /**
     * Free-text purpose. Examples: "External audit 2026-06-15 (TÜV)",
     * "Quarterly internal review Q2/2026", "Pre-cert dry-run". Surfaces
     * in the snapshot list + cert-bundle metadata so auditors can
     * trace why this freeze was taken.
     */
    #[ORM\Column(name: 'purpose', type: Types::STRING, length: 255, nullable: true)]
    private ?string $purpose = null;

    /**
     * Frozen SoA state as a structured map. Schema:
     *   {
     *     "controls": {
     *       "<control_id>": {
     *         "control_id": "5.15",
     *         "name": "...",
     *         "category": "A.5",
     *         "status": "implemented",
     *         "applicable": true,
     *         "evidence_documents": [
     *           {
     *             "document_id": 42,
     *             "filename": "...",
     *             "version": 3,
     *             "supersedes_chain": [41, 40],
     *             "uploaded_at": "2026-01-15T..."
     *           }
     *         ],
     *         "approved_by_user_id": 7,
     *         "approved_by_email": "ciso@example.com",
     *         "approved_at": "2026-04-30T...",
     *         "approval_workflow_instance_id": 12
     *       }
     *     },
     *     "tenant_name": "...",
     *     "control_count": 93,
     *     "snapshot_engine_version": "1"
     *   }
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'payload', type: Types::JSON)]
    private array $payload = [];

    /**
     * SHA-256 of the canonical JSON encoding of `payload`. Computed
     * once at creation; never re-computed (immutability). Auditors
     * can re-hash the JSON at export-time to verify integrity.
     */
    #[ORM\Column(name: 'checksum_sha256', type: Types::STRING, length: 64)]
    private string $checksumSha256;

    #[ORM\Column(name: 'notes', type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->asOfDate = new DateTimeImmutable();
        $this->checksumSha256 = '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    /**
     * Set tenant. Allowed only before persist (immutability). The
     * service layer enforces single-write semantics; this setter
     * exists for the constructor wiring and unit-tests.
     */
    public function setTenant(Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
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

    public function getAsOfDate(): DateTimeImmutable
    {
        return $this->asOfDate;
    }

    public function setAsOfDate(DateTimeImmutable $asOfDate): static
    {
        $this->asOfDate = $asOfDate;
        return $this;
    }

    public function getPurpose(): ?string
    {
        return $this->purpose;
    }

    public function setPurpose(?string $purpose): static
    {
        $this->purpose = $purpose;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): static
    {
        $this->payload = $payload;
        return $this;
    }

    public function getChecksumSha256(): string
    {
        return $this->checksumSha256;
    }

    public function setChecksumSha256(string $checksumSha256): static
    {
        $this->checksumSha256 = $checksumSha256;
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
     * Convenience: number of controls captured in this snapshot.
     */
    public function getControlCount(): int
    {
        $controls = $this->payload['controls'] ?? [];
        return is_array($controls) ? count($controls) : 0;
    }
}
