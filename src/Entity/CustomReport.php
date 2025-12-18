<?php

namespace App\Entity;

use DateTimeImmutable;
use App\Repository\CustomReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Custom Report Entity
 *
 * Phase 7C: Stores user-created custom report configurations with drag & drop widgets.
 * Reports can be saved, shared with team members, and exported as templates.
 */
#[ORM\Entity(repositoryClass: CustomReportRepository::class)]
#[ORM\Table(name: 'custom_report')]
#[ORM\Index(name: 'idx_custom_report_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_custom_report_owner', columns: ['owner_id'])]
#[ORM\Index(name: 'idx_custom_report_shared', columns: ['is_shared'])]
#[ORM\Index(name: 'idx_custom_report_template', columns: ['is_template'])]
#[ORM\HasLifecycleCallbacks]
class CustomReport
{
    // Layout types
    public const LAYOUT_SINGLE = 'single';
    public const LAYOUT_TWO_COLUMN = 'two_column';
    public const LAYOUT_DASHBOARD = 'dashboard';
    public const LAYOUT_WIDE_NARROW = 'wide_narrow';
    public const LAYOUT_NARROW_WIDE = 'narrow_wide';

    // Report categories
    public const CATEGORY_RISK = 'risk';
    public const CATEGORY_COMPLIANCE = 'compliance';
    public const CATEGORY_BCM = 'bcm';
    public const CATEGORY_ASSET = 'asset';
    public const CATEGORY_AUDIT = 'audit';
    public const CATEGORY_INCIDENT = 'incident';
    public const CATEGORY_GENERAL = 'general';
    public const CATEGORY_EXECUTIVE = 'executive';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::CATEGORY_RISK,
        self::CATEGORY_COMPLIANCE,
        self::CATEGORY_BCM,
        self::CATEGORY_ASSET,
        self::CATEGORY_AUDIT,
        self::CATEGORY_INCIDENT,
        self::CATEGORY_GENERAL,
        self::CATEGORY_EXECUTIVE,
    ])]
    private string $category = self::CATEGORY_GENERAL;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        self::LAYOUT_SINGLE,
        self::LAYOUT_TWO_COLUMN,
        self::LAYOUT_DASHBOARD,
        self::LAYOUT_WIDE_NARROW,
        self::LAYOUT_NARROW_WIDE,
    ])]
    private string $layout = self::LAYOUT_DASHBOARD;

    /**
     * Widget configuration stored as JSON
     * Structure: [
     *   {
     *     "id": "widget-uuid",
     *     "type": "risk_count|risk_matrix|compliance_chart|...",
     *     "position": {"row": 0, "col": 0},
     *     "size": {"width": 1, "height": 1},
     *     "config": { widget-specific configuration },
     *     "title": "Custom Title" (optional)
     *   },
     *   ...
     * ]
     */
    #[ORM\Column(type: Types::JSON)]
    private array $widgets = [];

    /**
     * Filter configuration for the report
     * Structure: {
     *   "dateRange": "last_30_days|last_quarter|last_year|custom",
     *   "startDate": "2024-01-01",
     *   "endDate": "2024-12-31",
     *   "assets": [1, 2, 3],
     *   "categories": ["operational", "technical"],
     *   "owners": [1, 2],
     *   ...
     * }
     */
    #[ORM\Column(type: Types::JSON)]
    private array $filters = [];

    /**
     * Style configuration (colors, fonts, branding)
     * Structure: {
     *   "primaryColor": "#007bff",
     *   "headerLogo": true,
     *   "showGeneratedDate": true,
     *   "showFooter": true,
     *   "pageOrientation": "portrait|landscape"
     * }
     */
    #[ORM\Column(type: Types::JSON)]
    private array $styles = [];

    #[ORM\Column]
    private bool $isShared = false;

    #[ORM\Column]
    private bool $isTemplate = false;

    #[ORM\Column]
    private bool $isFavorite = false;

    #[ORM\Column(nullable: true)]
    private ?int $usageCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastUsedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false)]
    private ?User $owner = null;

    /**
     * Users this report is shared with (JSON array of user IDs)
     */
    #[ORM\Column(type: Types::JSON)]
    private array $sharedWith = [];

    #[ORM\Column]
    private ?int $tenantId = null;

    #[ORM\Column(nullable: true)]
    private ?int $version = 1;

    /**
     * Parent template ID if this report was created from a template
     */
    #[ORM\Column(nullable: true)]
    private ?int $parentTemplateId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->styles = [
            'primaryColor' => '#0d6efd',
            'headerLogo' => true,
            'showGeneratedDate' => true,
            'showFooter' => true,
            'pageOrientation' => 'portrait',
        ];
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getLayout(): string
    {
        return $this->layout;
    }

    public function setLayout(string $layout): static
    {
        $this->layout = $layout;
        return $this;
    }

    public function getWidgets(): array
    {
        return $this->widgets;
    }

    public function setWidgets(array $widgets): static
    {
        $this->widgets = $widgets;
        return $this;
    }

    public function addWidget(array $widget): static
    {
        $this->widgets[] = $widget;
        return $this;
    }

    public function removeWidget(string $widgetId): static
    {
        $this->widgets = array_filter(
            $this->widgets,
            fn(array $w): bool => ($w['id'] ?? '') !== $widgetId
        );
        return $this;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function setFilters(array $filters): static
    {
        $this->filters = $filters;
        return $this;
    }

    public function getStyles(): array
    {
        return $this->styles;
    }

    public function setStyles(array $styles): static
    {
        $this->styles = $styles;
        return $this;
    }

    public function isShared(): bool
    {
        return $this->isShared;
    }

    public function setIsShared(bool $isShared): static
    {
        $this->isShared = $isShared;
        return $this;
    }

    public function isTemplate(): bool
    {
        return $this->isTemplate;
    }

    public function setIsTemplate(bool $isTemplate): static
    {
        $this->isTemplate = $isTemplate;
        return $this;
    }

    public function isFavorite(): bool
    {
        return $this->isFavorite;
    }

    public function setIsFavorite(bool $isFavorite): static
    {
        $this->isFavorite = $isFavorite;
        return $this;
    }

    public function getUsageCount(): ?int
    {
        return $this->usageCount;
    }

    public function setUsageCount(?int $usageCount): static
    {
        $this->usageCount = $usageCount;
        return $this;
    }

    public function incrementUsageCount(): static
    {
        $this->usageCount = ($this->usageCount ?? 0) + 1;
        $this->lastUsedAt = new DateTimeImmutable();
        return $this;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?DateTimeImmutable $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;
        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;
        return $this;
    }

    public function getSharedWith(): array
    {
        return $this->sharedWith;
    }

    public function setSharedWith(array $sharedWith): static
    {
        $this->sharedWith = $sharedWith;
        return $this;
    }

    public function addSharedUser(int $userId): static
    {
        if (!in_array($userId, $this->sharedWith, true)) {
            $this->sharedWith[] = $userId;
        }
        return $this;
    }

    public function removeSharedUser(int $userId): static
    {
        $this->sharedWith = array_filter(
            $this->sharedWith,
            fn(int $id): bool => $id !== $userId
        );
        return $this;
    }

    public function isSharedWithUser(int $userId): bool
    {
        return in_array($userId, $this->sharedWith, true);
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function setTenantId(int $tenantId): static
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    public function getVersion(): ?int
    {
        return $this->version;
    }

    public function setVersion(?int $version): static
    {
        $this->version = $version;
        return $this;
    }

    public function incrementVersion(): static
    {
        $this->version = ($this->version ?? 0) + 1;
        return $this;
    }

    public function getParentTemplateId(): ?int
    {
        return $this->parentTemplateId;
    }

    public function setParentTemplateId(?int $parentTemplateId): static
    {
        $this->parentTemplateId = $parentTemplateId;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Get available layout types
     */
    public static function getLayouts(): array
    {
        return [
            self::LAYOUT_SINGLE => 'Single Column',
            self::LAYOUT_TWO_COLUMN => 'Two Columns',
            self::LAYOUT_DASHBOARD => 'Dashboard Grid',
            self::LAYOUT_WIDE_NARROW => 'Wide + Narrow',
            self::LAYOUT_NARROW_WIDE => 'Narrow + Wide',
        ];
    }

    /**
     * Get available categories
     */
    public static function getCategories(): array
    {
        return [
            self::CATEGORY_GENERAL => 'General',
            self::CATEGORY_EXECUTIVE => 'Executive',
            self::CATEGORY_RISK => 'Risk Management',
            self::CATEGORY_COMPLIANCE => 'Compliance',
            self::CATEGORY_BCM => 'Business Continuity',
            self::CATEGORY_ASSET => 'Asset Management',
            self::CATEGORY_AUDIT => 'Audit',
            self::CATEGORY_INCIDENT => 'Incident Management',
        ];
    }

    /**
     * Clone this report configuration (for creating from template)
     */
    public function cloneAsNew(User $newOwner): static
    {
        $clone = new self();
        $clone->setName($this->name . ' (Copy)');
        $clone->setDescription($this->description);
        $clone->setCategory($this->category);
        $clone->setLayout($this->layout);
        $clone->setWidgets($this->widgets);
        $clone->setFilters($this->filters);
        $clone->setStyles($this->styles);
        $clone->setOwner($newOwner);
        $clone->setTenantId($this->tenantId);
        $clone->setParentTemplateId($this->id);
        $clone->setIsShared(false);
        $clone->setIsTemplate(false);
        $clone->setVersion(1);

        return $clone;
    }

    /**
     * Check if user can access this report
     */
    public function canAccess(User $user): bool
    {
        // Owner always has access
        if ($this->owner && $this->owner->getId() === $user->getId()) {
            return true;
        }

        // Shared report
        if ($this->isShared && $this->isSharedWithUser($user->getId())) {
            return true;
        }

        // Public template
        if ($this->isTemplate && $this->isShared) {
            return true;
        }

        return false;
    }
}
