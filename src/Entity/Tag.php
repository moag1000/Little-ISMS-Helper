<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TagRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Polymorphic tag master record (WS-5 / ENT-1).
 *
 * A tag can be of type:
 *  - framework: relates to a ComplianceFramework (e.g. NIS2, DORA, ISO27001).
 *  - classification / custom: freely defined.
 *
 * Tenant scope: tenant_id NULL = global (system-wide), else tenant-specific.
 */
#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tag')]
#[ORM\Index(name: 'idx_tag_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_tag_type', columns: ['type'])]
#[ORM\Index(name: 'idx_tag_framework_code', columns: ['framework_code'])]
#[ORM\UniqueConstraint(name: 'uniq_tag_tenant_name', columns: ['tenant_id', 'name'])]
class Tag
{
    public const TYPE_FRAMEWORK = 'framework';
    public const TYPE_CLASSIFICATION = 'classification';
    public const TYPE_CUSTOM = 'custom';

    public const TYPES = [
        self::TYPE_FRAMEWORK,
        self::TYPE_CLASSIFICATION,
        self::TYPE_CUSTOM,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 100)]
    private string $name;

    #[ORM\Column(length: 30, options: ['default' => self::TYPE_FRAMEWORK])]
    #[Assert\Choice(choices: self::TYPES)]
    private string $type = self::TYPE_FRAMEWORK;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $frameworkCode = null;

    #[ORM\Column(length: 20, options: ['default' => 'secondary'])]
    private string $color = 'secondary';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, EntityTag>
     */
    #[ORM\OneToMany(targetEntity: EntityTag::class, mappedBy: 'tag', cascade: ['remove'])]
    private Collection $entityTags;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->entityTags = new ArrayCollection();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getFrameworkCode(): ?string
    {
        return $this->frameworkCode;
    }

    public function setFrameworkCode(?string $frameworkCode): static
    {
        $this->frameworkCode = $frameworkCode;
        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;
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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, EntityTag>
     */
    public function getEntityTags(): Collection
    {
        return $this->entityTags;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
