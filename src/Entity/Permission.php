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
#[UniqueEntity(fields: ['name'], message: 'This permission name is already in use')]
class Permission implements Stringable
{
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

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
