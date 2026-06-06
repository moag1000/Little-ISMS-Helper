<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RoadmapGroupRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Roadmap-Group — a sortable label that buckets {@see RoadmapTask} categories
 * in the capacity roadmap. One level only; carries no business logic.
 */
#[ORM\Entity(repositoryClass: RoadmapGroupRepository::class)]
#[ORM\Table(name: 'roadmap_groups')]
#[ORM\Index(name: 'idx_roadmap_group_tenant', columns: ['tenant_id'])]
class RoadmapGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $colorToken = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $icon = null;

    /** Optional Annex-A / ISMS domain for Cl. 9.3 PT roll-up. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $ismsDomain = null;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Team $defaultTeam = null;

    /** Default visibility preset for new tasks in this group: 'all' | 'team'. */
    #[ORM\Column(length: 10, options: ['default' => 'team'])]
    #[Assert\Choice(choices: ['all', 'team'])]
    private string $defaultVisibility = 'team';

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isSystemGroup = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function getColorToken(): ?string
    {
        return $this->colorToken;
    }

    public function setColorToken(?string $colorToken): static
    {
        $this->colorToken = $colorToken;
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;
        return $this;
    }

    public function getIsmsDomain(): ?string
    {
        return $this->ismsDomain;
    }

    public function setIsmsDomain(?string $ismsDomain): static
    {
        $this->ismsDomain = $ismsDomain;
        return $this;
    }

    public function getDefaultTeam(): ?Team
    {
        return $this->defaultTeam;
    }

    public function setDefaultTeam(?Team $defaultTeam): static
    {
        $this->defaultTeam = $defaultTeam;
        return $this;
    }

    public function getDefaultVisibility(): string
    {
        return $this->defaultVisibility;
    }

    public function setDefaultVisibility(string $defaultVisibility): static
    {
        $this->defaultVisibility = $defaultVisibility;
        return $this;
    }

    public function isSystemGroup(): bool
    {
        return $this->isSystemGroup;
    }

    public function setIsSystemGroup(bool $isSystemGroup): static
    {
        $this->isSystemGroup = $isSystemGroup;
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

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
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
}
