<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryBaselineRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * S2 / CM-6 (docs/CM_JUNIOR_RESPONSE.md): pre-configured ISMS starter
 * packs per industry so a new tenant does not start from zero.
 *
 * A baseline captures: required + recommended frameworks, preset asset
 * classes, preset risks, preset applicable Annex-A controls, and an
 * FTE-saving estimate for the onboarding. Content is framework-agnostic
 * and framework-specific — the applier matches against what's already
 * loaded in the tenant.
 *
 * Baselines are global (tenant_id NULL) and seeded via
 * App\Command\LoadIndustryBaseline*Command classes. A tenant can "apply"
 * a baseline through IndustryBaselineService — idempotent, audit-logged.
 */
#[ORM\Entity(repositoryClass: IndustryBaselineRepository::class)]
#[ORM\Table(name: 'industry_baseline')]
#[ORM\UniqueConstraint(name: 'uniq_industry_baseline_code', columns: ['code'])]
#[ORM\Index(name: 'idx_industry_baseline_industry', columns: ['industry'])]
class IndustryBaseline
{
    public const INDUSTRY_PRODUCTION = 'production';
    public const INDUSTRY_FINANCE = 'finance';
    public const INDUSTRY_KRITIS_HEALTH = 'kritis_health';
    public const INDUSTRY_AUTOMOTIVE = 'automotive';
    public const INDUSTRY_CLOUD = 'cloud';
    public const INDUSTRY_PUBLIC_SECTOR = 'public_sector';
    public const INDUSTRY_GENERIC = 'generic';

    public const INDUSTRIES = [
        self::INDUSTRY_PRODUCTION,
        self::INDUSTRY_FINANCE,
        self::INDUSTRY_KRITIS_HEALTH,
        self::INDUSTRY_AUTOMOTIVE,
        self::INDUSTRY_CLOUD,
        self::INDUSTRY_PUBLIC_SECTOR,
        self::INDUSTRY_GENERIC,
    ];

    public const SOURCE_COMMUNITY = 'community';
    public const SOURCE_CONSULTANT = 'consultant';
    public const SOURCE_BSI = 'bsi';
    public const SOURCE_ENISA = 'enisa';
    public const SOURCE_INTERNAL = 'internal';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $code;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 30)]
    private string $industry = self::INDUSTRY_GENERIC;

    #[ORM\Column(length: 30)]
    private string $source = self::SOURCE_COMMUNITY;

    /**
     * Framework codes that MUST be activated for this industry
     * (e.g. KRITIS Health needs NIS2 + ISO27001).
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $requiredFrameworks = [];

    /**
     * Framework codes recommended beyond the required set (TISAX for
     * Automotive optional vs. required, etc.).
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $recommendedFrameworks = [];

    /**
     * Preset risk templates applied on baseline load.
     * Each entry: { title, description, category, inherent_likelihood,
     * inherent_impact, treatment_strategy }.
     * @var list<array<string,mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $presetRisks = [];

    /**
     * Preset asset templates. Each entry: { name, asset_type,
     * confidentiality, integrity, availability, description }.
     * @var list<array<string,mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $presetAssets = [];

    /**
     * Annex-A control IDs (ISO 27001:2022) that should start as applicable=true
     * for this industry (the rest stays at the loader's default).
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $presetApplicableControls = [];

    /**
     * Rough FTE-day estimate saved by applying this baseline vs. building
     * from scratch. Informational — shown in the apply-preview dialog.
     */
    #[ORM\Column(type: Types::FLOAT)]
    private float $fteDaysSavedEstimate = 0.0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(length: 20)]
    private string $version = '1.0';

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getIndustry(): string
    {
        return $this->industry;
    }

    public function setIndustry(string $industry): static
    {
        $this->industry = $industry;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    /** @return list<string> */
    public function getRequiredFrameworks(): array
    {
        return $this->requiredFrameworks;
    }

    /** @param list<string> $frameworks */
    public function setRequiredFrameworks(array $frameworks): static
    {
        $this->requiredFrameworks = array_values($frameworks);
        return $this;
    }

    /** @return list<string> */
    public function getRecommendedFrameworks(): array
    {
        return $this->recommendedFrameworks;
    }

    /** @param list<string> $frameworks */
    public function setRecommendedFrameworks(array $frameworks): static
    {
        $this->recommendedFrameworks = array_values($frameworks);
        return $this;
    }

    /** @return list<array<string,mixed>> */
    public function getPresetRisks(): array
    {
        return $this->presetRisks;
    }

    /** @param list<array<string,mixed>> $risks */
    public function setPresetRisks(array $risks): static
    {
        $this->presetRisks = array_values($risks);
        return $this;
    }

    /** @return list<array<string,mixed>> */
    public function getPresetAssets(): array
    {
        return $this->presetAssets;
    }

    /** @param list<array<string,mixed>> $assets */
    public function setPresetAssets(array $assets): static
    {
        $this->presetAssets = array_values($assets);
        return $this;
    }

    /** @return list<string> */
    public function getPresetApplicableControls(): array
    {
        return $this->presetApplicableControls;
    }

    /** @param list<string> $controls */
    public function setPresetApplicableControls(array $controls): static
    {
        $this->presetApplicableControls = array_values($controls);
        return $this;
    }

    public function getFteDaysSavedEstimate(): float
    {
        return $this->fteDaysSavedEstimate;
    }

    public function setFteDaysSavedEstimate(float $estimate): static
    {
        $this->fteDaysSavedEstimate = $estimate;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): static
    {
        $this->version = $version;
        return $this;
    }
}
