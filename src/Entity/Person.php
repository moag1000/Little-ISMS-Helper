<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use App\Repository\PersonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Person Entity
 *
 * Centralized management of all persons (employees, contractors, visitors, etc.)
 * Enables data reuse across PhysicalAccessLog and other entities
 */
#[ORM\Entity(repositoryClass: PersonRepository::class)]
#[ORM\Index(columns: ['person_type'], name: 'idx_person_type')]
#[ORM\Index(columns: ['badge_id'], name: 'idx_person_badge')]
#[ORM\Index(columns: ['company'], name: 'idx_person_company')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Put(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['person:read']],
    denormalizationContext: ['groups' => ['person:write']]
)]
class Person
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['person:read', 'physical_access:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['person:read', 'person:write', 'physical_access:read'])]
    #[Assert\NotBlank]
    private ?string $fullName = null;

    #[ORM\Column(length: 50)]
    #[Groups(['person:read', 'person:write'])]
    #[Assert\Choice(choices: ['employee', 'contractor', 'visitor', 'vendor', 'auditor', 'consultant', 'other'])]
    private ?string $personType = 'visitor';

    #[ORM\Column(length: 100, nullable: true, unique: true)]
    #[Groups(['person:read', 'person:write', 'physical_access:read'])]
    private ?string $badgeId = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['person:read', 'person:write', 'physical_access:read'])]
    private ?string $company = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['person:read', 'person:write'])]
    private ?string $email = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['person:read', 'person:write'])]
    private ?string $phone = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['person:read', 'person:write'])]
    private ?string $department = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['person:read', 'person:write'])]
    private ?string $jobTitle = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['person:read'])]
    private ?User $linkedUser = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['person:read', 'person:write'])]
    private ?bool $active = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['person:read', 'person:write'])]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['person:read', 'person:write'])]
    private ?\DateTimeInterface $accessValidFrom = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['person:read', 'person:write'])]
    private ?\DateTimeInterface $accessValidUntil = null;

    #[ORM\OneToMany(targetEntity: PhysicalAccessLog::class, mappedBy: 'person')]
    private Collection $accessLogs;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['person:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['person:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->accessLogs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = $fullName;
        return $this;
    }

    public function getPersonType(): ?string
    {
        return $this->personType;
    }

    public function setPersonType(string $personType): static
    {
        $this->personType = $personType;
        return $this;
    }

    public function getBadgeId(): ?string
    {
        return $this->badgeId;
    }

    public function setBadgeId(?string $badgeId): static
    {
        $this->badgeId = $badgeId;
        return $this;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(?string $company): static
    {
        $this->company = $company;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;
        return $this;
    }

    public function getDepartment(): ?string
    {
        return $this->department;
    }

    public function setDepartment(?string $department): static
    {
        $this->department = $department;
        return $this;
    }

    public function getJobTitle(): ?string
    {
        return $this->jobTitle;
    }

    public function setJobTitle(?string $jobTitle): static
    {
        $this->jobTitle = $jobTitle;
        return $this;
    }

    public function getLinkedUser(): ?User
    {
        return $this->linkedUser;
    }

    public function setLinkedUser(?User $linkedUser): static
    {
        $this->linkedUser = $linkedUser;
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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function getAccessValidFrom(): ?\DateTimeInterface
    {
        return $this->accessValidFrom;
    }

    public function setAccessValidFrom(?\DateTimeInterface $accessValidFrom): static
    {
        $this->accessValidFrom = $accessValidFrom;
        return $this;
    }

    public function getAccessValidUntil(): ?\DateTimeInterface
    {
        return $this->accessValidUntil;
    }

    public function setAccessValidUntil(?\DateTimeInterface $accessValidUntil): static
    {
        $this->accessValidUntil = $accessValidUntil;
        return $this;
    }

    /**
     * @return Collection<int, PhysicalAccessLog>
     */
    public function getAccessLogs(): Collection
    {
        return $this->accessLogs;
    }

    public function addAccessLog(PhysicalAccessLog $accessLog): static
    {
        if (!$this->accessLogs->contains($accessLog)) {
            $this->accessLogs->add($accessLog);
            $accessLog->setPerson($this);
        }

        return $this;
    }

    public function removeAccessLog(PhysicalAccessLog $accessLog): static
    {
        if ($this->accessLogs->removeElement($accessLog)) {
            if ($accessLog->getPerson() === $this) {
                $accessLog->setPerson(null);
            }
        }

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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Check if access is currently valid
     */
    public function hasValidAccess(): bool
    {
        $now = new \DateTime();

        if (!$this->active) {
            return false;
        }

        if ($this->accessValidFrom && $this->accessValidFrom > $now) {
            return false;
        }

        if ($this->accessValidUntil && $this->accessValidUntil < $now) {
            return false;
        }

        return true;
    }

    /**
     * Get display name with company if available
     */
    public function getDisplayName(): string
    {
        if ($this->company) {
            return sprintf('%s (%s)', $this->fullName, $this->company);
        }

        return $this->fullName;
    }
}
