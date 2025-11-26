<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Repository\PhysicalAccessLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PhysicalAccessLogRepository::class)]
#[ORM\Index(columns: ['access_time'], name: 'idx_physical_access_time')]
#[ORM\Index(columns: ['access_type'], name: 'idx_physical_access_type')]
#[ORM\Index(columns: ['location'], name: 'idx_physical_location')]
#[ORM\Index(columns: ['person_name'], name: 'idx_physical_person')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['physical_access:read']],
    denormalizationContext: ['groups' => ['physical_access:write']]
)]
class PhysicalAccessLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['physical_access:read'])]
    private ?int $id = null;

    // New relationships for data reuse
    #[ORM\ManyToOne(targetEntity: Person::class, inversedBy: 'accessLogs')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['physical_access:read', 'physical_access:write'])]
    private ?Person $person = null;

    #[ORM\ManyToOne(targetEntity: Location::class, inversedBy: 'accessLogs')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['physical_access:read', 'physical_access:write'])]
    private ?Location $locationEntity = null;

    // Legacy fields - kept for backward compatibility
    // @deprecated Use $person instead
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['physical_access:read', 'physical_access:write'])]
    private ?string $personName = null;

    // @deprecated Use $person->getBadgeId() instead
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['physical_access:read', 'physical_access:write'])]
    private ?string $badgeId = null;

    // @deprecated Use $locationEntity instead
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['physical_access:read', 'physical_access:write'])]
    private ?string $location = null;

    #[ORM\Column(length: 50)]
    #[Groups(['physical_access:read', 'physical_access:write'])]
    #[Assert\Choice(choices: ['entry', 'exit', 'denied', 'forced_entry'])]
    private ?string $accessType = 'entry';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['physical_access:read', 'physical_access:write'])]
    #[Assert\NotBlank]
    private ?\DateTimeInterface $accessTime = null;

    #[ORM\Column(length: 50)]
    #[Groups(['physical_access:read', 'physical_access:write'])]
    #[Assert\Choice(choices: ['badge', 'biometric', 'pin', 'key', 'escort', 'override', 'other'])]
    private ?string $authenticationMethod = 'badge';

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['physical_access:read', 'physical_access:write'])]
    private ?string $purpose = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['physical_access:read', 'physical_access:write'])]
    private ?string $escortedBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['physical_access:read', 'physical_access:write'])]
    private ?string $company = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['physical_access:read', 'physical_access:write'])]
    private ?bool $authorized = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['physical_access:read', 'physical_access:write'])]
    private ?string $notes = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['physical_access:read', 'physical_access:write'])]
    private ?string $alertLevel = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['physical_access:read', 'physical_access:write'])]
    private ?bool $afterHours = false;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['physical_access:read', 'physical_access:write'])]
    private ?string $doorOrGate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['physical_access:read'])]
    private ?\DateTimeInterface $exitTime = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['physical_access:read'])]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['physical_access:read'])]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->accessTime = new \DateTimeImmutable();
    }

    // Getters and Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPersonName(): ?string
    {
        return $this->personName;
    }

    public function setPersonName(string $personName): static
    {
        $this->personName = $personName;
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

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(string $location): static
    {
        $this->location = $location;
        return $this;
    }

    public function getAccessType(): ?string
    {
        return $this->accessType;
    }

    public function setAccessType(string $accessType): static
    {
        $this->accessType = $accessType;
        return $this;
    }

    public function getAccessTime(): ?\DateTimeInterface
    {
        return $this->accessTime;
    }

    public function setAccessTime(\DateTimeInterface $accessTime): static
    {
        $this->accessTime = $accessTime;
        return $this;
    }

    public function getAuthenticationMethod(): ?string
    {
        return $this->authenticationMethod;
    }

    public function setAuthenticationMethod(string $authenticationMethod): static
    {
        $this->authenticationMethod = $authenticationMethod;
        return $this;
    }

    public function getPurpose(): ?string
    {
        return $this->purpose;
    }

    public function setPurpose(?string $purpose): static
    {
        $this->purpose = $purpose;
        return $this;
    }

    public function getEscortedBy(): ?string
    {
        return $this->escortedBy;
    }

    public function setEscortedBy(?string $escortedBy): static
    {
        $this->escortedBy = $escortedBy;
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

    public function isAuthorized(): ?bool
    {
        return $this->authorized;
    }

    public function setAuthorized(bool $authorized): static
    {
        $this->authorized = $authorized;
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

    public function getAlertLevel(): ?string
    {
        return $this->alertLevel;
    }

    public function setAlertLevel(?string $alertLevel): static
    {
        $this->alertLevel = $alertLevel;
        return $this;
    }

    public function isAfterHours(): ?bool
    {
        return $this->afterHours;
    }

    public function setAfterHours(bool $afterHours): static
    {
        $this->afterHours = $afterHours;
        return $this;
    }

    public function getDoorOrGate(): ?string
    {
        return $this->doorOrGate;
    }

    public function setDoorOrGate(?string $doorOrGate): static
    {
        $this->doorOrGate = $doorOrGate;
        return $this;
    }

    public function getExitTime(): ?\DateTimeInterface
    {
        return $this->exitTime;
    }

    public function setExitTime(?\DateTimeInterface $exitTime): static
    {
        $this->exitTime = $exitTime;
        return $this;
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getPerson(): ?Person
    {
        return $this->person;
    }

    public function setPerson(?Person $person): static
    {
        $this->person = $person;

        // Sync legacy field for backward compatibility
        if ($person) {
            $this->personName = $person->getFullName();
            $this->badgeId = $person->getBadgeId();
            $this->company = $person->getCompany();
        }

        return $this;
    }

    public function getLocationEntity(): ?Location
    {
        return $this->locationEntity;
    }

    public function setLocationEntity(?Location $locationEntity): static
    {
        $this->locationEntity = $locationEntity;

        // Sync legacy field for backward compatibility
        if ($locationEntity) {
            $this->location = $locationEntity->getName();
        }

        return $this;
    }

    /**
     * Get effective person name (from Person entity or legacy field)
     */
    public function getEffectivePersonName(): ?string
    {
        return $this->person?->getFullName() ?? $this->personName;
    }

    /**
     * Get effective location name (from Location entity or legacy field)
     */
    public function getEffectiveLocation(): ?string
    {
        return $this->locationEntity?->getName() ?? $this->location;
    }
}
