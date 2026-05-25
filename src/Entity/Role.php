<?php

declare(strict_types=1);

namespace App\Entity;

use Stringable;
use DateTimeImmutable;
use App\Repository\RoleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: RoleRepository::class)]
#[ORM\Table(name: 'roles')]
#[ORM\Index(name: 'idx_role_status', columns: ['status'])]
#[UniqueEntity(fields: ['name'], message: 'role.validation.name_unique')]
class Role implements Stringable
{
    // Junior-ISB-Audit Phase-2 Lifecycle — RBAC core entities.
    // 3-stage lifecycle for a role: drafted → activated → archived.
    // Archive is irreversible from a 4-eyes perspective: archiving a role
    // removes it from user-bindings, which is a sensitive RBAC change.
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isSystemRole = false;

    /**
     * Junior-ISB-Audit Phase-2 Lifecycle — RBAC core entities.
     * Owned by `role_lifecycle` — never call setStatus() directly outside the
     * initial-marking bootstrap; route transitions through LifecycleService::transition().
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

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'customRoles')]
    private Collection $users;

    /**
     * @var Collection<int, Permission>
     */
    #[ORM\ManyToMany(targetEntity: Permission::class, inversedBy: 'roles')]
    #[ORM\JoinTable(name: 'role_permissions')]
    private Collection $permissions;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->permissions = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function isSystemRole(): bool
    {
        return $this->isSystemRole;
    }

    public function setIsSystemRole(bool $isSystemRole): static
    {
        $this->isSystemRole = $isSystemRole;
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
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->addCustomRole($this);
        }

        return $this;
    }

    public function removeUser(User $user): static
    {
        if ($this->users->removeElement($user)) {
            $user->removeCustomRole($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, Permission>
     */
    public function getPermissions(): Collection
    {
        return $this->permissions;
    }

    public function addPermission(Permission $permission): static
    {
        if (!$this->permissions->contains($permission)) {
            $this->permissions->add($permission);
        }

        return $this;
    }

    public function removePermission(Permission $permission): static
    {
        $this->permissions->removeElement($permission);
        return $this;
    }

    /**
     * Check if role has a specific permission
     */
    public function hasPermission(string $permissionName): bool
    {
        foreach ($this->permissions as $permission) {
            if ($permission->getName() === $permissionName) {
                return true;
            }
        }
        return false;
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
