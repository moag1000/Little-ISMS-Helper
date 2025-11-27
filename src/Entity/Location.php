<?php

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use App\Repository\LocationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Location Entity
 *
 * Centralized management of physical locations (buildings, rooms, gates, etc.)
 * Enables data reuse across PhysicalAccessLog, Asset, and other entities
 */
#[ORM\Entity(repositoryClass: LocationRepository::class)]
#[ORM\Index(columns: ['location_type'], name: 'idx_location_type')]
#[ORM\Index(columns: ['active'], name: 'idx_location_active')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Put(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['location:read']],
    denormalizationContext: ['groups' => ['location:write']]
)]
class Location
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['location:read', 'asset:read', 'physical_access:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['location:read', 'location:write', 'asset:read', 'physical_access:read'])]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(length: 100)]
    #[Groups(['location:read', 'location:write'])]
    #[Assert\Choice(choices: ['building', 'floor', 'room', 'area', 'datacenter', 'server_room', 'office', 'warehouse', 'gate', 'entrance', 'parking', 'outdoor', 'other'])]
    private ?string $locationType = 'room';

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['location:read', 'location:write'])]
    private ?string $code = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['location:read', 'location:write'])]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['location:read', 'location:write'])]
    private ?string $address = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['location:read', 'location:write'])]
    private ?string $city = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['location:read', 'location:write'])]
    private ?string $country = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['location:read', 'location:write'])]
    private ?string $postalCode = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'childLocations')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['location:read', 'location:write'])]
    private ?self $parentLocation = null;

    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parentLocation')]
    #[Groups(['location:read'])]
    private Collection $childLocations;

    #[ORM\Column(length: 50)]
    #[Groups(['location:read', 'location:write'])]
    #[Assert\Choice(choices: ['public', 'restricted', 'controlled', 'secure', 'high_security'])]
    private ?string $securityLevel = 'public';

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['location:read', 'location:write'])]
    private ?bool $requiresBadgeAccess = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['location:read', 'location:write'])]
    private ?bool $requiresEscort = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['location:read', 'location:write'])]
    private ?bool $cameraMonitored = false;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['location:read', 'location:write'])]
    private ?string $accessControlSystem = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['location:read', 'location:write'])]
    private ?string $responsiblePerson = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['location:read', 'location:write'])]
    private ?int $capacity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['location:read', 'location:write'])]
    private ?string $squareMeters = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['location:read', 'location:write'])]
    private ?bool $active = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['location:read', 'location:write'])]
    private ?string $notes = null;

    #[ORM\OneToMany(targetEntity: PhysicalAccessLog::class, mappedBy: 'locationEntity')]
    private Collection $accessLogs;

    #[ORM\OneToMany(targetEntity: Asset::class, mappedBy: 'physicalLocation')]
    private Collection $assets;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['location:read'])]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['location:read'])]
    private ?DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->childLocations = new ArrayCollection();
        $this->accessLogs = new ArrayCollection();
        $this->assets = new ArrayCollection();
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

    public function getLocationType(): ?string
    {
        return $this->locationType;
    }

    public function setLocationType(string $locationType): static
    {
        $this->locationType = $locationType;
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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;
        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;
        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): static
    {
        $this->country = $country;
        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): static
    {
        $this->postalCode = $postalCode;
        return $this;
    }

    public function getParentLocation(): ?self
    {
        return $this->parentLocation;
    }

    public function setParentLocation(?self $parentLocation): static
    {
        $this->parentLocation = $parentLocation;
        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getChildLocations(): Collection
    {
        return $this->childLocations;
    }

    public function addChildLocation(self $childLocation): static
    {
        if (!$this->childLocations->contains($childLocation)) {
            $this->childLocations->add($childLocation);
            $childLocation->setParentLocation($this);
        }

        return $this;
    }

    public function removeChildLocation(self $childLocation): static
    {
        if ($this->childLocations->removeElement($childLocation) && $childLocation->getParentLocation() === $this) {
            $childLocation->setParentLocation(null);
        }

        return $this;
    }

    public function getSecurityLevel(): ?string
    {
        return $this->securityLevel;
    }

    public function setSecurityLevel(string $securityLevel): static
    {
        $this->securityLevel = $securityLevel;
        return $this;
    }

    public function requiresBadgeAccess(): ?bool
    {
        return $this->requiresBadgeAccess;
    }

    public function setRequiresBadgeAccess(bool $requiresBadgeAccess): static
    {
        $this->requiresBadgeAccess = $requiresBadgeAccess;
        return $this;
    }

    public function requiresEscort(): ?bool
    {
        return $this->requiresEscort;
    }

    public function setRequiresEscort(bool $requiresEscort): static
    {
        $this->requiresEscort = $requiresEscort;
        return $this;
    }

    public function isCameraMonitored(): ?bool
    {
        return $this->cameraMonitored;
    }

    public function setCameraMonitored(bool $cameraMonitored): static
    {
        $this->cameraMonitored = $cameraMonitored;
        return $this;
    }

    public function getAccessControlSystem(): ?string
    {
        return $this->accessControlSystem;
    }

    public function setAccessControlSystem(?string $accessControlSystem): static
    {
        $this->accessControlSystem = $accessControlSystem;
        return $this;
    }

    public function getResponsiblePerson(): ?string
    {
        return $this->responsiblePerson;
    }

    public function setResponsiblePerson(?string $responsiblePerson): static
    {
        $this->responsiblePerson = $responsiblePerson;
        return $this;
    }

    public function getCapacity(): ?int
    {
        return $this->capacity;
    }

    public function setCapacity(?int $capacity): static
    {
        $this->capacity = $capacity;
        return $this;
    }

    public function getSquareMeters(): ?string
    {
        return $this->squareMeters;
    }

    public function setSquareMeters(?string $squareMeters): static
    {
        $this->squareMeters = $squareMeters;
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

    /**
     * @return Collection<int, PhysicalAccessLog>
     */
    public function getAccessLogs(): Collection
    {
        return $this->accessLogs;
    }

    /**
     * @return Collection<int, Asset>
     */
    public function getAssets(): Collection
    {
        return $this->assets;
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

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Get full location path (e.g., "Building A > Floor 2 > Room 201")
     */
    public function getFullPath(): string
    {
        $path = [$this->name];
        $parent = $this->parentLocation;

        while ($parent instanceof \App\Entity\Location) {
            array_unshift($path, $parent->getName());
            $parent = $parent->getParentLocation();
        }

        return implode(' > ', $path);
    }

    /**
     * Get display name with code if available
     */
    public function getDisplayName(): string
    {
        if ($this->code) {
            return sprintf('%s [%s]', $this->name, $this->code);
        }

        return $this->name;
    }

    /**
     * Check if this is a high security location
     */
    public function isHighSecurity(): bool
    {
        return in_array($this->securityLevel, ['secure', 'high_security'], true);
    }
}
