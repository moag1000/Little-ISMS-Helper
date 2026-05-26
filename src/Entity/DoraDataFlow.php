<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DoraDataFlowRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * DoraDataFlow — DORA Art. 28 Register of Information (RoI) RT_03 sub-table.
 *
 * Describes a data flow between the financial entity (tenant) and an ICT
 * third-party service provider (Supplier) for the purposes of the DORA
 * Register of Information per ESA Joint ITS on RoI:
 *
 *   - Data categories transmitted (PII, financial, health, etc.)
 *   - Direction (inbound / outbound / bidirectional)
 *   - Processing purpose
 *   - Security measures (encryption, tokenisation, pseudonymisation, ...)
 *   - Data volume description
 *   - Cross-border transfer indicator + receiving country (ISO 3166-1 alpha-2)
 *
 * Module-gated on `nis2_dora`. Multi-tenant via {@see Tenant}.
 *
 * References:
 *   - DORA Art. 28 — Register of Information on ICT Third-Party Providers
 *   - ESA Joint Implementing Technical Standard (ITS) on RoI — RT_03 sub-table
 *
 * @see \App\Service\Authority\DoraRoiXbrlExporter — emits RT_03 XBRL elements per flow
 */
#[ORM\Entity(repositoryClass: DoraDataFlowRepository::class)]
#[ORM\Table(name: 'dora_data_flow')]
#[ORM\Index(name: 'idx_dora_data_flow_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_dora_data_flow_supplier', columns: ['supplier_id'])]
#[ORM\Index(name: 'idx_dora_data_flow_direction', columns: ['direction'])]
#[ORM\HasLifecycleCallbacks]
class DoraDataFlow
{
    public const string DIRECTION_INBOUND = 'inbound';
    public const string DIRECTION_OUTBOUND = 'outbound';
    public const string DIRECTION_BIDIRECTIONAL = 'bidirectional';

    public const array DIRECTIONS = [
        self::DIRECTION_INBOUND,
        self::DIRECTION_OUTBOUND,
        self::DIRECTION_BIDIRECTIONAL,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'supplier_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'dora_data_flow.validation.supplier_required')]
    private ?Supplier $supplier = null;

    /**
     * Categories of data transmitted (free-form strings).
     *
     * Common values: 'PII', 'financial', 'health', 'authentication',
     * 'transactional', 'metadata', 'logs', 'backup'.
     *
     * @var array<int, string>
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\Count(min: 1, minMessage: 'dora_data_flow.validation.data_categories_required')]
    private array $dataCategories = [];

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(message: 'dora_data_flow.validation.direction_required')]
    #[Assert\Choice(choices: self::DIRECTIONS, message: 'dora_data_flow.validation.direction_invalid')]
    private ?string $direction = self::DIRECTION_OUTBOUND;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500, maxMessage: 'dora_data_flow.validation.processing_purpose_too_long')]
    private ?string $processingPurpose = null;

    /**
     * Security measures applied to the data flow.
     *
     * Common values: 'encryption_in_transit', 'encryption_at_rest',
     * 'tokenisation', 'pseudonymisation', 'anonymisation', 'mfa', 'vpn',
     * 'mtls', 'access_control'.
     *
     * @var array<int, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $securityMeasures = [];

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100, maxMessage: 'dora_data_flow.validation.data_volume_too_long')]
    private ?string $dataVolume = null;

    #[ORM\Column(name: 'cross_border', type: Types::BOOLEAN)]
    private bool $crossBorder = false;

    /**
     * Receiving country (ISO 3166-1 alpha-2) — only relevant when crossBorder = true.
     */
    #[ORM\Column(name: 'receiving_country', length: 2, nullable: true)]
    #[Assert\Length(exactly: 2, exactMessage: 'dora_data_flow.validation.receiving_country_format')]
    #[Assert\Regex(pattern: '/^[A-Z]{2}$/', message: 'dora_data_flow.validation.receiving_country_format')]
    private ?string $receivingCountry = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
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

    public function getSupplier(): ?Supplier
    {
        return $this->supplier;
    }

    public function setSupplier(?Supplier $supplier): static
    {
        $this->supplier = $supplier;
        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getDataCategories(): array
    {
        return $this->dataCategories;
    }

    /**
     * @param array<int, string> $dataCategories
     */
    public function setDataCategories(array $dataCategories): static
    {
        // Normalise to flat list of trimmed non-empty strings.
        $clean = [];
        foreach ($dataCategories as $cat) {
            $cat = trim((string) $cat);
            if ($cat !== '') {
                $clean[] = $cat;
            }
        }
        $this->dataCategories = array_values(array_unique($clean));
        return $this;
    }

    public function getDirection(): ?string
    {
        return $this->direction;
    }

    public function setDirection(?string $direction): static
    {
        $this->direction = $direction;
        return $this;
    }

    public function getProcessingPurpose(): ?string
    {
        return $this->processingPurpose;
    }

    public function setProcessingPurpose(?string $processingPurpose): static
    {
        $this->processingPurpose = $processingPurpose;
        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getSecurityMeasures(): array
    {
        return $this->securityMeasures;
    }

    /**
     * @param array<int, string> $securityMeasures
     */
    public function setSecurityMeasures(array $securityMeasures): static
    {
        $clean = [];
        foreach ($securityMeasures as $m) {
            $m = trim((string) $m);
            if ($m !== '') {
                $clean[] = $m;
            }
        }
        $this->securityMeasures = array_values(array_unique($clean));
        return $this;
    }

    public function getDataVolume(): ?string
    {
        return $this->dataVolume;
    }

    public function setDataVolume(?string $dataVolume): static
    {
        $this->dataVolume = $dataVolume;
        return $this;
    }

    public function isCrossBorder(): bool
    {
        return $this->crossBorder;
    }

    public function setCrossBorder(bool $crossBorder): static
    {
        $this->crossBorder = $crossBorder;
        // Defensive: clearing crossBorder also clears receivingCountry so the
        // pair stays self-consistent across edits.
        if ($crossBorder === false) {
            $this->receivingCountry = null;
        }
        return $this;
    }

    public function getReceivingCountry(): ?string
    {
        return $this->receivingCountry;
    }

    public function setReceivingCountry(?string $receivingCountry): static
    {
        $this->receivingCountry = $receivingCountry !== null
            ? strtoupper(trim($receivingCountry))
            : null;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Display-friendly summary used by index tables + select widgets.
     */
    public function getDisplayLabel(): string
    {
        $supplierName = $this->supplier?->getName() ?? 'Unknown supplier';
        $cats = empty($this->dataCategories) ? '?' : implode(', ', $this->dataCategories);

        return sprintf('%s · %s · %s', $supplierName, $this->direction ?? '?', $cats);
    }
}
