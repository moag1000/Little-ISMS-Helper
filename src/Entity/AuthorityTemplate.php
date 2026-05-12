<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuthorityTemplateRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * F26 — EU-Behörden-Notification-Template
 *
 * Stores pre-configured submission templates for German/EU supervisory authorities:
 * BSI-Meldestelle (NIS-2), BfDI (GDPR Art. 33), and 16 state DPAs (LfDI).
 *
 * Authority keys follow the pattern: bsi_meldestelle, bfdi, lfdi_bw, lfdi_by, etc.
 * The fieldMapping JSON maps DataBreach/Incident entity fields to authority form fields.
 */
#[ORM\Entity(repositoryClass: AuthorityTemplateRepository::class)]
#[ORM\Table(name: 'authority_template')]
#[ORM\Index(name: 'idx_authority_template_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_authority_template_key', columns: ['authority_key'])]
#[ORM\Index(name: 'idx_authority_template_entity_type', columns: ['entity_type'])]
#[ORM\HasLifecycleCallbacks]
class AuthorityTemplate
{
    public const AUTHORITY_BSI_MELDESTELLE = 'bsi_meldestelle';
    public const AUTHORITY_BFDI = 'bfdi';
    public const AUTHORITY_LFDI_BW = 'lfdi_bw';
    public const AUTHORITY_LFDI_BY = 'lfdi_by';
    public const AUTHORITY_LFDI_BER = 'lfdi_ber';
    public const AUTHORITY_LFDI_BRB = 'lfdi_brb';
    public const AUTHORITY_LFDI_HB = 'lfdi_hb';
    public const AUTHORITY_LFDI_HH = 'lfdi_hh';
    public const AUTHORITY_LFDI_HE = 'lfdi_he';
    public const AUTHORITY_LFDI_MV = 'lfdi_mv';
    public const AUTHORITY_LFDI_NI = 'lfdi_ni';
    public const AUTHORITY_LFDI_NRW = 'lfdi_nrw';
    public const AUTHORITY_LFDI_RLP = 'lfdi_rlp';
    public const AUTHORITY_LFDI_SL = 'lfdi_sl';
    public const AUTHORITY_LFDI_SN = 'lfdi_sn';
    public const AUTHORITY_LFDI_ST = 'lfdi_st';
    public const AUTHORITY_LFDI_SH = 'lfdi_sh';
    public const AUTHORITY_LFDI_TH = 'lfdi_th';

    public const ENTITY_TYPE_DATA_BREACH = 'data_breach';
    public const ENTITY_TYPE_INCIDENT = 'incident';

    public const VALID_AUTHORITY_KEYS = [
        self::AUTHORITY_BSI_MELDESTELLE,
        self::AUTHORITY_BFDI,
        self::AUTHORITY_LFDI_BW,
        self::AUTHORITY_LFDI_BY,
        self::AUTHORITY_LFDI_BER,
        self::AUTHORITY_LFDI_BRB,
        self::AUTHORITY_LFDI_HB,
        self::AUTHORITY_LFDI_HH,
        self::AUTHORITY_LFDI_HE,
        self::AUTHORITY_LFDI_MV,
        self::AUTHORITY_LFDI_NI,
        self::AUTHORITY_LFDI_NRW,
        self::AUTHORITY_LFDI_RLP,
        self::AUTHORITY_LFDI_SL,
        self::AUTHORITY_LFDI_SN,
        self::AUTHORITY_LFDI_ST,
        self::AUTHORITY_LFDI_SH,
        self::AUTHORITY_LFDI_TH,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    /** Authority identifier — one of AUTHORITY_* constants */
    #[ORM\Column(length: 50)]
    private string $authorityKey = self::AUTHORITY_BFDI;

    /** Which entity type this template applies to */
    #[ORM\Column(length: 30)]
    private string $entityType = self::ENTITY_TYPE_DATA_BREACH;

    /**
     * JSON mapping of source entity fields to authority form fields.
     * E.g.: {"breach_nature": "description", "affected_count": "affectedDataSubjects"}
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $fieldMapping = [];

    /** Introductory text / cover letter template (may contain %placeholders%) */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $headerTemplate = null;

    /** URL to the authority's online submission portal */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $submissionUrl = null;

    /** Contact email for submissions */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $submissionContactEmail = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
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

    public function getAuthorityKey(): string
    {
        return $this->authorityKey;
    }

    public function setAuthorityKey(string $authorityKey): static
    {
        $this->authorityKey = $authorityKey;
        return $this;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): static
    {
        $this->entityType = $entityType;
        return $this;
    }

    /** @return array<string, string> */
    public function getFieldMapping(): array
    {
        return $this->fieldMapping;
    }

    /** @param array<string, string> $fieldMapping */
    public function setFieldMapping(array $fieldMapping): static
    {
        $this->fieldMapping = $fieldMapping;
        return $this;
    }

    public function getHeaderTemplate(): ?string
    {
        return $this->headerTemplate;
    }

    public function setHeaderTemplate(?string $headerTemplate): static
    {
        $this->headerTemplate = $headerTemplate;
        return $this;
    }

    public function getSubmissionUrl(): ?string
    {
        return $this->submissionUrl;
    }

    public function setSubmissionUrl(?string $submissionUrl): static
    {
        $this->submissionUrl = $submissionUrl;
        return $this;
    }

    public function getSubmissionContactEmail(): ?string
    {
        return $this->submissionContactEmail;
    }

    public function setSubmissionContactEmail(?string $submissionContactEmail): static
    {
        $this->submissionContactEmail = $submissionContactEmail;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }
}
