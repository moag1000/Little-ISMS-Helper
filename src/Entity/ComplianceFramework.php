<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
use App\Repository\ComplianceFrameworkRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ComplianceFrameworkRepository::class)]
class ComplianceFramework
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    // Kept public to preserve backward-compat with existing
    // $framework->id direct reads (controllers, repositories,
    // commands — see callers audit). Getter below exposes it
    // under the getId() alias Twig/Symfony expect.
    public ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    #[ORM\Column(length: 100, unique: true)]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    private ?string $version = null;

    #[ORM\Column(length: 100)]
    private ?string $applicableIndustry = null;

    #[ORM\Column(length: 100)]
    private ?string $regulatoryBody = null;

    #[ORM\Column]
    private ?bool $mandatory = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $scopeDescription = null;

    #[ORM\Column]
    private ?bool $active = true;

    /**
     * M-04: Lifecycle state — controls how the framework is surfaced:
     *  - active      → current, appears in pickers and dashboards (default)
     *  - deprecated  → still usable but flagged for replacement
     *  - superseded  → read-only, succeeded by another framework (see predecessor/successor)
     *  - draft       → not yet fit for certification audits
     */
    public const LIFECYCLE_ACTIVE = 'active';
    public const LIFECYCLE_DEPRECATED = 'deprecated';
    public const LIFECYCLE_SUPERSEDED = 'superseded';
    public const LIFECYCLE_DRAFT = 'draft';

    #[ORM\Column(length: 20)]
    private string $lifecycleState = self::LIFECYCLE_ACTIVE;

    /**
     * M-04: Forward link to the framework that supersedes this one.
     * Mapping source for upgrade wizards (e.g. ISO 27001:2013 → 2022).
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'successor_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $successor = null;

    /**
     * Required ISMS modules for this framework
     * @var array<string>
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $requiredModules = [];

    /**
     * @var Collection<int, ComplianceRequirement>
     *
     * Kept public for backward-compat with callers that read
     * $framework->requirements directly (PortfolioReportService,
     * ComplianceAnalyticsService, several commands). getRequirements()
     * stays as the canonical method path.
     */
    #[ORM\OneToMany(targetEntity: ComplianceRequirement::class, mappedBy: 'framework', cascade: ['persist', 'remove'])]
    public Collection $requirements;

    /**
     * @return Collection<int, ComplianceRequirement>
     */
    public function getRequirements(): Collection
    {
        return $this->requirements;
    }

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->requirements = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getLifecycleState(): string
    {
        return $this->lifecycleState;
    }

    public function setLifecycleState(string $state): static
    {
        $this->lifecycleState = $state;
        return $this;
    }

    public function getSuccessor(): ?self
    {
        return $this->successor;
    }

    public function setSuccessor(?self $successor): static
    {
        $this->successor = $successor;
        return $this;
    }

    public function isOperational(): bool
    {
        return $this->active === true
            && in_array($this->lifecycleState, [self::LIFECYCLE_ACTIVE, self::LIFECYCLE_DEPRECATED], true);
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
        return $this;
    }

    public function getName(): ?string
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

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(string $version): static
    {
        $this->version = $version;
        return $this;
    }

    public function getApplicableIndustry(): ?string
    {
        return $this->applicableIndustry;
    }

    public function setApplicableIndustry(string $applicableIndustry): static
    {
        $this->applicableIndustry = $applicableIndustry;
        return $this;
    }

    public function getRegulatoryBody(): ?string
    {
        return $this->regulatoryBody;
    }

    public function setRegulatoryBody(string $regulatoryBody): static
    {
        $this->regulatoryBody = $regulatoryBody;
        return $this;
    }

    public function isMandatory(): ?bool
    {
        return $this->mandatory;
    }

    public function setMandatory(bool $mandatory): static
    {
        $this->mandatory = $mandatory;
        return $this;
    }

    public function getScopeDescription(): ?string
    {
        return $this->scopeDescription;
    }

    public function setScopeDescription(?string $scopeDescription): static
    {
        $this->scopeDescription = $scopeDescription;
        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        return $this;
    }

    public function addRequirement(ComplianceRequirement $complianceRequirement): static
    {
        if (!$this->requirements->contains($complianceRequirement)) {
            $this->requirements->add($complianceRequirement);
            $complianceRequirement->setFramework($this);
        }

        return $this;
    }

    public function removeRequirement(ComplianceRequirement $complianceRequirement): static
    {
        if ($this->requirements->removeElement($complianceRequirement) && $complianceRequirement->getFramework() === $this) {
            $complianceRequirement->setFramework(null);
        }

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

    /**
     * @return array<string>
     */
    public function getRequiredModules(): array
    {
        return $this->requiredModules ?? [];
    }

    /**
     * @param array<string> $requiredModules
     */
    public function setRequiredModules(?array $requiredModules): static
    {
        $this->requiredModules = $requiredModules;
        return $this;
    }

    /**
     * Add a required module
     */
    public function addRequiredModule(string $moduleKey): static
    {
        if (!in_array($moduleKey, $this->requiredModules ?? [])) {
            $this->requiredModules[] = $moduleKey;
        }
        return $this;
    }

    /**
     * Remove a required module
     */
    public function removeRequiredModule(string $moduleKey): static
    {
        $this->requiredModules = array_diff($this->requiredModules ?? [], [$moduleKey]);
        return $this;
    }

    /**
     * Check if a module is required
     */
    public function requiresModule(string $moduleKey): bool
    {
        return in_array($moduleKey, $this->requiredModules ?? []);
    }
}
