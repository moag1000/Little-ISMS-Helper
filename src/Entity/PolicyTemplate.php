<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PolicyTemplateRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * System-shared catalogue of policy recipes used by the Policy-Wizard.
 *
 * Holds the canonical definition of a policy / programme document
 * (translation keys for title and body, required substitution
 * variables, linked controls / Bausteine / DORA articles, default
 * review interval and approval chain). PolicyTemplate rows are NOT
 * tenant-scoped; subsidiary tenants discover their applicable
 * templates via the framework-inheritance machinery (see
 * `05-architecture.md` §7.2).
 */
#[ORM\Entity(repositoryClass: PolicyTemplateRepository::class)]
#[ORM\Table(name: 'policy_template')]
#[ORM\UniqueConstraint(name: 'uq_policy_template_key', columns: ['key_name'])]
#[ORM\Index(name: 'idx_policy_template_standard', columns: ['standard'])]
#[ORM\Index(name: 'idx_policy_template_topic', columns: ['topic'])]
#[ORM\Index(name: 'idx_policy_template_active', columns: ['is_active'])]
#[ORM\Index(name: 'idx_policy_template_bsi_tier', columns: ['bsi_tier'])]
class PolicyTemplate
{
    /**
     * BSI IT-Grundschutz Vorgehensweise (200-2 Kap. 2). Drives the
     * Policy-Wizard W5-A tier filter — `basis_only` ships only Basis-
     * Pflicht-Set, `up_to_standard` adds Standard-Absicherung-Bausteine,
     * `kern_full` ships everything including high-effort Kern-Absicherung.
     *
     * Null = applies to every tier (e.g. ISO / DORA / BCM templates that
     * carry no BSI tier semantics).
     */
    public const string BSI_TIER_BASIS = 'basis';
    public const string BSI_TIER_STANDARD = 'standard';
    public const string BSI_TIER_KERN = 'kern';

    /** @var list<string> */
    public const array BSI_TIERS = [
        self::BSI_TIER_BASIS,
        self::BSI_TIER_STANDARD,
        self::BSI_TIER_KERN,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Unique business key, e.g. `iso27001.access_control`.
     * Stored under column `key_name` because `key` is reserved in MySQL.
     */
    #[ORM\Column(name: 'key_name', length: 191, unique: true)]
    private ?string $key = null;

    /**
     * Standard discriminator. Allowed values:
     * iso27001 | bsi | dora | bcm22301 | bsi200-4
     */
    #[ORM\Column(length: 32)]
    private ?string $standard = null;

    /**
     * Topic key inside the standard, e.g. `access_control`.
     */
    #[ORM\Column(length: 100)]
    private ?string $topic = null;

    /**
     * Document type. Allowed values:
     * policy | programme | plan | procedure | methodology
     */
    #[ORM\Column(length: 32)]
    private ?string $documentType = null;

    /**
     * Norm reference (e.g. `A.5.15` / `OPS.1.1.3` / `Art. 9.4`).
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $normRef = null;

    #[ORM\Column(length: 191)]
    private ?string $titleTranslationKey = null;

    #[ORM\Column(length: 191)]
    private ?string $bodyTranslationKey = null;

    /**
     * List of {key, type, label_t_key, required} dictionaries.
     *
     * @var array<int, array<string, mixed>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $requiredVariables = null;

    /**
     * Annex A control refs covered by this policy, e.g. ['A.5.15'].
     *
     * @var array<int, string>|null
     */
    #[ORM\Column(name: 'linked_annex_a_controls', type: Types::JSON, nullable: true)]
    private ?array $linkedAnnexAControls = null;

    /**
     * BSI Bausteine covered, e.g. ['ORP.4'].
     *
     * @var array<int, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $linkedBausteine = null;

    /**
     * Specific BSI Baustein anchor IDs (incl. Anforderung suffix), e.g.
     * `ISMS.1.A4`, `ORP.4.A1` etc. Distinct from {@see $linkedBausteine}
     * which only carries Baustein-level IDs (`ORP.4`). Used by the W5-A
     * BSI seed to record the Anhang A "Mandatiert von" anchors and to
     * drive the BSI-specific gap report. Null = no BSI anchor.
     *
     * @var array<int, string>|null
     */
    #[ORM\Column(name: 'linked_bsi_bausteine', type: Types::JSON, nullable: true)]
    private ?array $linkedBsiBausteine = null;

    /**
     * BSI Vorgehensweise (Basis / Standard / Kern). Null = template
     * applies regardless of tier (e.g. ISO/DORA/BCM templates).
     */
    #[ORM\Column(name: 'bsi_tier', type: Types::STRING, length: 16, nullable: true)]
    private ?string $bsiTier = null;

    /**
     * DORA articles covered, e.g. ['Art. 9.4'].
     *
     * @var array<int, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $linkedDoraArticles = null;

    /**
     * Business-functions affected by this policy (P1 Risk-Owner). Drives
     * the `function_owner_review` workflow step. Example: ['HR','IT_OPERATIONS'].
     *
     * @var array<int, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $affectedFunctions = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 12])]
    private int $reviewIntervalMonths = 12;

    /**
     * Ordered list of role keys, e.g. ['ROLE_CISO','ROLE_TOP_MGMT'].
     *
     * @var array<int, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $approvalChain = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $climateChangeWording = false;

    /**
     * P1 DPO: whether a DPO cross-check section is required for this
     * template (drives the dpo_cross_check workflow gate).
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $dpoSectionRequired = false;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'superseded_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $supersededBy = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function setKey(string $key): static
    {
        $this->key = $key;
        return $this;
    }

    public function getStandard(): ?string
    {
        return $this->standard;
    }

    public function setStandard(string $standard): static
    {
        $this->standard = $standard;
        return $this;
    }

    public function getTopic(): ?string
    {
        return $this->topic;
    }

    public function setTopic(string $topic): static
    {
        $this->topic = $topic;
        return $this;
    }

    public function getDocumentType(): ?string
    {
        return $this->documentType;
    }

    public function setDocumentType(string $documentType): static
    {
        $this->documentType = $documentType;
        return $this;
    }

    public function getNormRef(): ?string
    {
        return $this->normRef;
    }

    public function setNormRef(?string $normRef): static
    {
        $this->normRef = $normRef;
        return $this;
    }

    public function getTitleTranslationKey(): ?string
    {
        return $this->titleTranslationKey;
    }

    public function setTitleTranslationKey(string $titleTranslationKey): static
    {
        $this->titleTranslationKey = $titleTranslationKey;
        return $this;
    }

    public function getBodyTranslationKey(): ?string
    {
        return $this->bodyTranslationKey;
    }

    public function setBodyTranslationKey(string $bodyTranslationKey): static
    {
        $this->bodyTranslationKey = $bodyTranslationKey;
        return $this;
    }

    /** @return array<int, array<string, mixed>>|null */
    public function getRequiredVariables(): ?array
    {
        return $this->requiredVariables;
    }

    /** @param array<int, array<string, mixed>>|null $requiredVariables */
    public function setRequiredVariables(?array $requiredVariables): static
    {
        $this->requiredVariables = $requiredVariables;
        return $this;
    }

    /** @return array<int, string>|null */
    public function getLinkedAnnexAControls(): ?array
    {
        return $this->linkedAnnexAControls;
    }

    /** @param array<int, string>|null $linkedAnnexAControls */
    public function setLinkedAnnexAControls(?array $linkedAnnexAControls): static
    {
        $this->linkedAnnexAControls = $linkedAnnexAControls;
        return $this;
    }

    /** @return array<int, string>|null */
    public function getLinkedBausteine(): ?array
    {
        return $this->linkedBausteine;
    }

    /** @param array<int, string>|null $linkedBausteine */
    public function setLinkedBausteine(?array $linkedBausteine): static
    {
        $this->linkedBausteine = $linkedBausteine;
        return $this;
    }

    /** @return array<int, string>|null */
    public function getLinkedBsiBausteine(): ?array
    {
        return $this->linkedBsiBausteine;
    }

    /** @param array<int, string>|null $linkedBsiBausteine */
    public function setLinkedBsiBausteine(?array $linkedBsiBausteine): static
    {
        $this->linkedBsiBausteine = $linkedBsiBausteine;
        return $this;
    }

    public function getBsiTier(): ?string
    {
        return $this->bsiTier;
    }

    /**
     * @throws \InvalidArgumentException when $bsiTier is non-null and not
     *                                   one of the BSI_TIER_* constants.
     */
    public function setBsiTier(?string $bsiTier): static
    {
        if ($bsiTier !== null && !in_array($bsiTier, self::BSI_TIERS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown BSI tier "%s". Allowed: %s, or null.',
                $bsiTier,
                implode(', ', self::BSI_TIERS),
            ));
        }
        $this->bsiTier = $bsiTier;
        return $this;
    }

    /** @return array<int, string>|null */
    public function getLinkedDoraArticles(): ?array
    {
        return $this->linkedDoraArticles;
    }

    /** @param array<int, string>|null $linkedDoraArticles */
    public function setLinkedDoraArticles(?array $linkedDoraArticles): static
    {
        $this->linkedDoraArticles = $linkedDoraArticles;
        return $this;
    }

    /** @return array<int, string>|null */
    public function getAffectedFunctions(): ?array
    {
        return $this->affectedFunctions;
    }

    /** @param array<int, string>|null $affectedFunctions */
    public function setAffectedFunctions(?array $affectedFunctions): static
    {
        $this->affectedFunctions = $affectedFunctions;
        return $this;
    }

    public function getReviewIntervalMonths(): int
    {
        return $this->reviewIntervalMonths;
    }

    public function setReviewIntervalMonths(int $reviewIntervalMonths): static
    {
        $this->reviewIntervalMonths = $reviewIntervalMonths;
        return $this;
    }

    /** @return array<int, string>|null */
    public function getApprovalChain(): ?array
    {
        return $this->approvalChain;
    }

    /** @param array<int, string>|null $approvalChain */
    public function setApprovalChain(?array $approvalChain): static
    {
        $this->approvalChain = $approvalChain;
        return $this;
    }

    public function isClimateChangeWording(): bool
    {
        return $this->climateChangeWording;
    }

    public function setClimateChangeWording(bool $climateChangeWording): static
    {
        $this->climateChangeWording = $climateChangeWording;
        return $this;
    }

    public function isDpoSectionRequired(): bool
    {
        return $this->dpoSectionRequired;
    }

    public function setDpoSectionRequired(bool $dpoSectionRequired): static
    {
        $this->dpoSectionRequired = $dpoSectionRequired;
        return $this;
    }

    public function getSupersededBy(): ?self
    {
        return $this->supersededBy;
    }

    public function setSupersededBy(?self $supersededBy): static
    {
        $this->supersededBy = $supersededBy;
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

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): static
    {
        $this->version = $version;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): static
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
}
