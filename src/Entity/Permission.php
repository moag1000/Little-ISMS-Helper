<?php

declare(strict_types=1);

namespace App\Entity;

use Stringable;
use DateTimeImmutable;
use App\Repository\PermissionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: PermissionRepository::class)]
#[ORM\Table(name: 'permissions')]
#[ORM\Index(name: 'idx_permission_status', columns: ['status'])]
#[UniqueEntity(fields: ['name'], message: 'permission.validation.name_unique')]
class Permission implements Stringable
{
    // Junior-ISB-Audit Phase-2 Lifecycle — RBAC core entities.
    // 4-stage lifecycle for a permission: drafted → activated → deprecated → archived.
    // Deprecation flags a permission "discouraged from new assignments" without
    // breaking existing role bindings — archival removes it from operational use.
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DEPRECATED = 'deprecated';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    private ?string $category = null; // e.g., 'risk', 'asset', 'incident', 'user', 'report'

    #[ORM\Column(length: 50)]
    private ?string $action = null; // e.g., 'view', 'create', 'edit', 'delete', 'approve', 'export'

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $module = null; // matches config/modules.yaml key, e.g. 'risks', 'privacy', 'audits'

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $frameworkReference = null; // e.g. 'ISO 27001 Cl. 6.1.2', 'GDPR Art. 33 + 34'

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isSystemPermission = false;

    /**
     * Junior-ISB-Audit Phase-2 Lifecycle — RBAC core entities.
     * Owned by `permission_lifecycle` — never call setStatus() directly outside
     * the initial-marking bootstrap; route transitions through LifecycleService::transition().
     */
    #[ORM\Column(length: 30, options: ['default' => self::STATUS_DRAFT])]
    private string $status = self::STATUS_DRAFT;

    /**
     * Junior-ISB-Audit Phase-2 Lifecycle — RBAC core entities.
     * Optimistic-lock guard for concurrent lifecycle transitions.
     */
    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer', options: ['default' => 0])]
    private int $lockVersion = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    /**
     * @var Collection<int, Role>
     */
    #[ORM\ManyToMany(targetEntity: Role::class, mappedBy: 'permissions')]
    private Collection $roles;

    public function __construct()
    {
        $this->roles = new ArrayCollection();
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

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;
        return $this;
    }

    public function isSystemPermission(): bool
    {
        return $this->isSystemPermission;
    }

    public function setIsSystemPermission(bool $isSystemPermission): static
    {
        $this->isSystemPermission = $isSystemPermission;
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

    /**
     * @return Collection<int, Role>
     */
    public function getRoles(): Collection
    {
        return $this->roles;
    }

    public function getModule(): ?string
    {
        return $this->module;
    }

    public function setModule(?string $module): static
    {
        $this->module = $module;
        return $this;
    }

    public function getFrameworkReference(): ?string
    {
        return $this->frameworkReference;
    }

    public function setFrameworkReference(?string $frameworkReference): static
    {
        $this->frameworkReference = $frameworkReference;
        return $this;
    }

    public function addRole(Role $role): static
    {
        if (!$this->roles->contains($role)) {
            $this->roles->add($role);
            $role->addPermission($this);
        }

        return $this;
    }

    public function removeRole(Role $role): static
    {
        if ($this->roles->removeElement($role)) {
            $role->removePermission($this);
        }

        return $this;
    }

    /**
     * Junior-ISB-Audit Phase-2 Lifecycle — RBAC core entities.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Junior-ISB-Audit Phase-2 Lifecycle — RBAC core entities.
     * Do NOT call directly outside the initial-marking bootstrap; route
     * transitions through LifecycleService::transition().
     */
    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Junior-ISB-Audit Phase-2 Lifecycle — RBAC core entities.
     */
    public function getLockVersion(): int
    {
        return $this->lockVersion;
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
