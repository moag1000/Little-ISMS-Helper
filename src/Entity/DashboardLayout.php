<?php

namespace App\Entity;

use DateTimeImmutable;
use App\Repository\DashboardLayoutRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dashboard Layout Entity
 *
 * Stores user-specific dashboard widget configurations including:
 * - Widget visibility
 * - Widget order
 * - Widget sizes
 * - Layout preferences
 *
 * Supports multi-tenancy and per-user customization.
 */
#[ORM\Entity(repositoryClass: DashboardLayoutRepository::class)]
#[ORM\Table(name: 'dashboard_layouts')]
#[ORM\Index(name: 'idx_dashboard_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_dashboard_tenant', columns: ['tenant_id'])]
class DashboardLayout
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    /**
     * JSON structure:
     * {
     *   "widgets": {
     *     "stats-cards": { "visible": true, "order": 0, "size": "default" },
     *     "risk-chart": { "visible": true, "order": 1, "size": "large" },
     *     ...
     *   },
     *   "_widgetOrder": ["stats-cards", "risk-chart", ...],
     *   "layout": "grid-3-col",
     *   "theme": "default"
     * }
     */
    #[ORM\Column(type: Types::JSON)]
    private array $layoutConfig = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->layoutConfig = $this->getDefaultLayoutConfig();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
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

    public function getLayoutConfig(): array
    {
        return $this->layoutConfig;
    }

    public function setLayoutConfig(array $layoutConfig): static
    {
        $this->layoutConfig = $layoutConfig;
        $this->updatedAt = new DateTimeImmutable();
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

    public function setUpdatedAt(DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Get default layout configuration
     */
    private function getDefaultLayoutConfig(): array
    {
        return [
            'widgets' => [
                'stats-cards' => ['visible' => true, 'order' => 0, 'size' => 'default'],
                'risk-chart' => ['visible' => true, 'order' => 1, 'size' => 'large'],
                'compliance-chart' => ['visible' => true, 'order' => 2, 'size' => 'medium'],
                'recent-activity' => ['visible' => true, 'order' => 3, 'size' => 'medium'],
                'tasks-widget' => ['visible' => true, 'order' => 4, 'size' => 'small'],
            ],
            '_widgetOrder' => ['stats-cards', 'risk-chart', 'compliance-chart', 'recent-activity', 'tasks-widget'],
            'layout' => 'grid-3-col',
            'theme' => 'default',
        ];
    }

    /**
     * Merge user config with default config
     */
    public function mergeWithDefaults(): void
    {
        $defaults = $this->getDefaultLayoutConfig();

        // Ensure all default widgets exist
        foreach ($defaults['widgets'] as $widgetId => $widgetConfig) {
            if (!isset($this->layoutConfig['widgets'][$widgetId])) {
                $this->layoutConfig['widgets'][$widgetId] = $widgetConfig;
            }
        }

        // Ensure layout and theme exist
        $this->layoutConfig['layout'] ??= $defaults['layout'];
        $this->layoutConfig['theme'] ??= $defaults['theme'];
    }

    /**
     * Get widget configuration
     */
    public function getWidgetConfig(string $widgetId): ?array
    {
        return $this->layoutConfig['widgets'][$widgetId] ?? null;
    }

    /**
     * Update widget configuration
     */
    public function updateWidgetConfig(string $widgetId, array $config): static
    {
        if (!isset($this->layoutConfig['widgets'])) {
            $this->layoutConfig['widgets'] = [];
        }

        $this->layoutConfig['widgets'][$widgetId] = array_merge(
            $this->layoutConfig['widgets'][$widgetId] ?? [],
            $config
        );

        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }
}
