<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DepartmentRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Department / Organizational Unit (S18 B3).
 *
 * Foundation entity replacing freetext "Department" fields scattered across the
 * application. Initial wiring is for ProcessingActivity.responsibleDepartment
 * (GDPR Art. 30 VVT), with cross-framework reuse foreseen for:
 *  - Asset.owningDepartment (ISO 27001 Cl. 5.3 / A.5.9)
 *  - Risk.affectedDepartment (ISO 27005)
 *  - Incident.reportingDepartment (ISO 27035)
 *  - BusinessProcess.responsibleDepartment (ISO 22301 Cl. 8.2.2)
 *  - Control.responsibleDepartment (ISO 27001 A.5.1)
 *  - Training.targetDepartment (ISO 27001 A.6.3)
 *  - Audit.scopeDepartment (ISO 19011)
 *
 * Self-referential parent FK supports organizational hierarchies (department
 * → division → business unit). Tenant-scoped uniqueness on `name`.
 */
#[ORM\Entity(repositoryClass: DepartmentRepository::class)]
#[ORM\Table(name: 'department')]
#[ORM\Index(name: 'idx_department_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_dept_name_per_tenant', columns: ['tenant_id', 'name'])]
class Department
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $name = null;

    /**
     * Kostenstellen-Code / cost-center code (optional short identifier).
     */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $code = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Department $parent = null;

    /**
     * @var Collection<int, Department>
     */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $children;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->children = new ArrayCollection();
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;
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

    public function getParent(): ?Department
    {
        return $this->parent;
    }

    public function setParent(?Department $parent): static
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * @return Collection<int, Department>
     */
    public function getChildren(): Collection
    {
        return $this->children;
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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        if ($this->name === null) {
            return '';
        }
        return $this->code !== null && $this->code !== ''
            ? sprintf('%s (%s)', $this->name, $this->code)
            : $this->name;
    }
}
