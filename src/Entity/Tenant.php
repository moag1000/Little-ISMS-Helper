<?php

namespace App\Entity;

use App\Enum\GovernanceModel;
use App\Repository\TenantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[UniqueEntity(fields: ['code'], message: 'This tenant code is already in use')]
#[ORM\HasLifecycleCallbacks]
class Tenant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $azureTenantId = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $settings = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'tenant', targetEntity: User::class)]
    private Collection $users;

    // Corporate Structure fields
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'subsidiaries')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Tenant $parent = null;

    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $subsidiaries;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: GovernanceModel::class, nullable: true)]
    private ?GovernanceModel $governanceModel = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isCorporateParent = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $corporateNotes = null;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->subsidiaries = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getAzureTenantId(): ?string
    {
        return $this->azureTenantId;
    }

    public function setAzureTenantId(?string $azureTenantId): static
    {
        $this->azureTenantId = $azureTenantId;
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

    public function getSettings(): ?array
    {
        return $this->settings;
    }

    public function setSettings(?array $settings): static
    {
        $this->settings = $settings;
        return $this;
    }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): static
    {
        $this->logoPath = $logoPath;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
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
            $user->setTenant($this);
        }

        return $this;
    }

    public function removeUser(User $user): static
    {
        if ($this->users->removeElement($user)) {
            if ($user->getTenant() === $this) {
                $user->setTenant(null);
            }
        }

        return $this;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Corporate Structure methods
    public function getParent(): ?Tenant
    {
        return $this->parent;
    }

    public function setParent(?Tenant $parent): static
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function getSubsidiaries(): Collection
    {
        return $this->subsidiaries;
    }

    public function addSubsidiary(Tenant $subsidiary): static
    {
        if (!$this->subsidiaries->contains($subsidiary)) {
            $this->subsidiaries->add($subsidiary);
            $subsidiary->setParent($this);
        }

        return $this;
    }

    public function removeSubsidiary(Tenant $subsidiary): static
    {
        if ($this->subsidiaries->removeElement($subsidiary)) {
            if ($subsidiary->getParent() === $this) {
                $subsidiary->setParent(null);
            }
        }

        return $this;
    }

    public function getGovernanceModel(): ?GovernanceModel
    {
        return $this->governanceModel;
    }

    public function setGovernanceModel(?GovernanceModel $governanceModel): static
    {
        $this->governanceModel = $governanceModel;
        return $this;
    }

    public function isCorporateParent(): bool
    {
        return $this->isCorporateParent;
    }

    public function setIsCorporateParent(bool $isCorporateParent): static
    {
        $this->isCorporateParent = $isCorporateParent;
        return $this;
    }

    public function getCorporateNotes(): ?string
    {
        return $this->corporateNotes;
    }

    public function setCorporateNotes(?string $corporateNotes): static
    {
        $this->corporateNotes = $corporateNotes;
        return $this;
    }

    /**
     * Check if this tenant is part of a corporate structure
     */
    public function isPartOfCorporateStructure(): bool
    {
        return $this->parent !== null || $this->subsidiaries->count() > 0;
    }

    /**
     * Get the root parent (top of corporate hierarchy)
     */
    public function getRootParent(): Tenant
    {
        $current = $this;
        while ($current->getParent() !== null) {
            $current = $current->getParent();
        }
        return $current;
    }

    /**
     * Get all subsidiaries recursively (including sub-subsidiaries)
     */
    public function getAllSubsidiaries(): array
    {
        $allSubsidiaries = [];
        foreach ($this->subsidiaries as $subsidiary) {
            $allSubsidiaries[] = $subsidiary;
            $allSubsidiaries = array_merge($allSubsidiaries, $subsidiary->getAllSubsidiaries());
        }
        return $allSubsidiaries;
    }

    /**
     * Get depth in corporate hierarchy (0 = root/parent, 1 = direct subsidiary, etc.)
     */
    public function getHierarchyDepth(): int
    {
        $depth = 0;
        $current = $this;
        while ($current->getParent() !== null) {
            $depth++;
            $current = $current->getParent();
        }
        return $depth;
    }
}
