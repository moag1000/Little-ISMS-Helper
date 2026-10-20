<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
use DateTime;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;

use App\Attribute\RequiresModule;
use App\Repository\InterestedPartyRepository;
use App\State\TenantAwareStateProcessor;
use App\Entity\Tenant;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use App\Service\OwnerResolver;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Validator\Constraint\SameTenantOwner;

/**
 * Interested Party Entity for ISO 27001 Chapter 4.2
 *
 * Understanding the needs and expectations of interested parties
 */
#[RequiresModule('interested_parties')]
#[ApiResource(
    operations: [
        new Get(security: "is_granted('API_VIEW', object)"),
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(securityPostDenormalize: "is_granted('API_CREATE', object)"),
        new Put(security: "is_granted('API_EDIT', object)"),
        new Delete(security: "is_granted('API_DELETE', object)"),
    ],
    processor: TenantAwareStateProcessor::class
)]
#[ORM\Entity(repositoryClass: InterestedPartyRepository::class)]
#[ORM\Table(name: 'interested_party')]
#[ORM\Index(name: 'idx_party_type', columns: ['party_type'])]
#[ORM\Index(name: 'idx_party_importance', columns: ['importance'])]
#[ORM\Index(name: 'idx_interested_party_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_interested_party_status', columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
class InterestedParty
{
    // Junior-ISB-Audit-2026-05-22 S-01: Lifecycle places (ISO 27001 Cl. 4.2 + 9.3.2 c).
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['interested_party:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['interested_party:read'])]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'interested_party.validation.name_required')]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?string $name = null;

    /**
     * Type of interested party:
     * - customer, shareholder, employee, regulator, supplier, partner, public, media, etc.
     */
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        'customer', 'shareholder', 'employee', 'regulator', 'supplier',
        'partner', 'public', 'media', 'government', 'competitor', 'other'
    ])]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?string $partyType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?string $contactPerson = null;

    /**
     * Pattern A dual-state: preferred structured contact. Falls back to string contactPerson.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'contact_person_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    #[SameTenantOwner]
    private ?User $contactPersonUser = null;

    /**
     * Person-based contact: for contacts without a system login.
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'contact_person_ref_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    #[SameTenantOwner]
    private ?Person $contactPersonRef = null;

    /**
     * Deputies / Vertretung — n additional Persons sharing the contact role.
     *
     * @var Collection<int, Person>
     */
    #[Groups(['interested_party:read', 'interested_party:write'])]
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'interested_party_contact_deputy')]
    #[ORM\JoinColumn(name: 'interested_party_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[SameTenantOwner]
    private Collection $contactDeputyPersons;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?string $email = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?string $phone = null;

    /**
     * Importance/Influence: critical, high, medium, low
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['critical', 'high', 'medium', 'low'])]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?string $importance = 'medium';

    /**
     * Requirements and expectations from this party
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'interested_party.validation.requirements_required')]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?string $requirements = null;

    /**
     * Legal/Regulatory/Contractual requirements (JSON)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?array $legalRequirements = null;

    /**
     * How their requirements are addressed
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?string $howAddressed = null;

    /**
     * Communication frequency and method
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Choice(choices: ['daily', 'weekly', 'monthly', 'quarterly', 'annually', 'as_needed'])]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?string $communicationFrequency = 'as_needed';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?string $communicationMethod = null;

    /**
     * Last communication date
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?DateTimeInterface $lastCommunication = null;

    /**
     * Next planned communication
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?DateTimeInterface $nextCommunication = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?string $feedback = null;

    /**
     * Satisfaction level (1-5)
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 1, max: 5)]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?int $satisfactionLevel = null;

    /**
     * Issues/Concerns
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['interested_party:read', 'interested_party:write'])]
    private ?string $issues = null;

    /**
     * Junior-ISB-Audit-2026-05-22 S-01: Lifecycle status (ISO 27001 Cl. 4.2 + 9.3.2 c).
     * Owned by `interested_party_lifecycle` — never call setStatus() directly
     * outside the initial-marking bootstrap; route transitions through
     * LifecycleService::transition().
     */
    #[ORM\Column(length: 30, options: ['default' => self::STATUS_DRAFT])]
    #[Groups(['interested_party:read'])]
    private string $status = self::STATUS_DRAFT;

    /**
     * Junior-ISB-Audit-2026-05-22 S-01: Optimistic-lock guard for concurrent
     * lifecycle transitions (HTTP 409 via OptimisticLockException).
     */
    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer', options: ['default' => 0])]
    private int $lockVersion = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['interested_party:read'])]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['interested_party:read'])]
    private ?DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->contactDeputyPersons = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new DateTimeImmutable();
        if (!$this->createdAt instanceof DateTimeInterface) {
            $this->createdAt = new DateTimeImmutable();
        }
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

    public function getPartyType(): ?string
    {
        return $this->partyType;
    }

    public function setPartyType(?string $partyType): static
    {
        $this->partyType = $partyType;
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

    public function getContactPerson(): ?string
    {
        return $this->contactPerson;
    }

    public function setContactPerson(?string $contactPerson): static
    {
        $this->contactPerson = $contactPerson;
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

    public function getImportance(): ?string
    {
        return $this->importance;
    }

    public function setImportance(?string $importance): static
    {
        $this->importance = $importance;
        return $this;
    }

    public function getRequirements(): ?string
    {
        return $this->requirements;
    }

    public function setRequirements(?string $requirements): static
    {
        $this->requirements = $requirements;
        return $this;
    }

    public function getLegalRequirements(): ?array
    {
        return $this->legalRequirements;
    }

    public function setLegalRequirements(?array $legalRequirements): static
    {
        $this->legalRequirements = $legalRequirements;
        return $this;
    }

    public function getHowAddressed(): ?string
    {
        return $this->howAddressed;
    }

    public function setHowAddressed(?string $howAddressed): static
    {
        $this->howAddressed = $howAddressed;
        return $this;
    }

    public function getCommunicationFrequency(): ?string
    {
        return $this->communicationFrequency;
    }

    public function setCommunicationFrequency(?string $communicationFrequency): static
    {
        $this->communicationFrequency = $communicationFrequency;
        return $this;
    }

    public function getCommunicationMethod(): ?string
    {
        return $this->communicationMethod;
    }

    public function setCommunicationMethod(?string $communicationMethod): static
    {
        $this->communicationMethod = $communicationMethod;
        return $this;
    }

    public function getLastCommunication(): ?DateTimeInterface
    {
        return $this->lastCommunication;
    }

    public function setLastCommunication(?DateTimeInterface $lastCommunication): static
    {
        $this->lastCommunication = $lastCommunication;
        return $this;
    }

    public function getNextCommunication(): ?DateTimeInterface
    {
        return $this->nextCommunication;
    }

    public function setNextCommunication(?DateTimeInterface $nextCommunication): static
    {
        $this->nextCommunication = $nextCommunication;
        return $this;
    }

    public function getFeedback(): ?string
    {
        return $this->feedback;
    }

    public function setFeedback(?string $feedback): static
    {
        $this->feedback = $feedback;
        return $this;
    }

    public function getSatisfactionLevel(): ?int
    {
        return $this->satisfactionLevel;
    }

    public function setSatisfactionLevel(?int $satisfactionLevel): static
    {
        $this->satisfactionLevel = $satisfactionLevel;
        return $this;
    }

    public function getIssues(): ?string
    {
        return $this->issues;
    }

    public function setIssues(?string $issues): static
    {
        $this->issues = $issues;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?DateTimeInterface $createdAt): static
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
     * Junior-ISB-Audit-2026-05-22 S-01: marking-store accessor for the
     * `interested_party_lifecycle` state-machine (ISO 27001 Cl. 4.2 + 9.3.2 c).
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Junior-ISB-Audit-2026-05-22 S-01: marking-store mutator. Only the
     * Symfony Workflow component / LifecycleService should invoke this in
     * production code — call-sites that need a transition must go through
     * LifecycleService::transition().
     */
    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Junior-ISB-Audit-2026-05-22 S-01: Optimistic-lock version exposed
     * for the LifecycleService HTTP 409 path.
     */
    public function getLockVersion(): int
    {
        return $this->lockVersion;
    }

    /**
     * Check if communication is overdue
     */
    public function isCommunicationOverdue(): bool
    {
        if (!$this->nextCommunication instanceof DateTimeInterface) {
            return false;
        }

        return $this->nextCommunication < new DateTime();
    }

    /**
     * Get communication status
     */
    public function getCommunicationStatus(): string
    {
        if (!$this->lastCommunication instanceof DateTimeInterface) {
            return 'never_communicated';
        }

        if ($this->isCommunicationOverdue()) {
            return 'overdue';
        }

        if ($this->nextCommunication instanceof DateTimeInterface) {
            $sevenDaysFromNow = new DateTime('+7 days');
            if ($this->nextCommunication < $sevenDaysFromNow) {
                return 'due_soon';
            }
        }

        return 'current';
    }

    /**
     * Get engagement score (based on satisfaction and communication)
     */
    public function getEngagementScore(): int
    {
        $score = 0;

        // Satisfaction level (50%)
        if ($this->satisfactionLevel) {
            $score += ($this->satisfactionLevel / 5) * 50;
        }

        // Communication recency (30%)
        if ($this->lastCommunication instanceof DateTimeInterface) {
            $daysSinceLastComm = new DateTime()->diff($this->lastCommunication)->days;
            if ($daysSinceLastComm <= 30) {
                $score += 30;
            } elseif ($daysSinceLastComm <= 90) {
                $score += 20;
            } elseif ($daysSinceLastComm <= 180) {
                $score += 10;
            }
        }

        // No outstanding issues (20%)
        if (in_array($this->issues, [null, '', '0'], true)) {
            $score += 20;
        }

        return (int)$score;
    }

    public function getContactPersonUser(): ?User
    {
        return $this->contactPersonUser;
    }

    public function setContactPersonUser(?User $contactPersonUser): static
    {
        $this->contactPersonUser = $contactPersonUser;
        return $this;
    }

    public function getContactPersonRef(): ?Person
    {
        return $this->contactPersonRef;
    }

    public function setContactPersonRef(?Person $contactPersonRef): static
    {
        $this->contactPersonRef = $contactPersonRef;
        return $this;
    }

    /** @return Collection<int, Person> */
    public function getContactDeputyPersons(): Collection
    {
        return $this->contactDeputyPersons;
    }

    public function addContactDeputyPerson(Person $person): static
    {
        if (!$this->contactDeputyPersons->contains($person)) {
            $this->contactDeputyPersons->add($person);
        }
        return $this;
    }

    public function removeContactDeputyPerson(Person $person): static
    {
        $this->contactDeputyPersons->removeElement($person);
        return $this;
    }

    /**
     * Effective contactPerson: prefer contactPersonUser.fullName,
     * then contactPersonRef (Person), fall back to legacy string.
     */
    public function getEffectiveContactPerson(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->contactPersonUser,
            $this->contactPersonRef,
            $this->contactPerson,
        );
    }

    /**
     * Full contact-person roster: primary + every deputy.
     *
     * @return list<string>
     */
    public function getAllContactPersons(): array
    {
        return OwnerResolver::resolveAll(
            $this->contactPersonUser,
            $this->contactPersonRef,
            $this->contactPerson,
            $this->contactDeputyPersons,
        );
    }
}
