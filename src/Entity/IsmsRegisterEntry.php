<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RegisterType;
use App\Repository\IsmsRegisterEntryRepository;
use App\Service\OwnerResolver;
use App\Validator\Constraint\SameTenantOwner;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One entry of a norm register (Paket 9): an internal or external issue
 * (ISO 27001 4.1), an opportunity (6.1.1), a legal or contractual requirement
 * (4.2 b, A.5.31), an interface of the scope (4.3 c), an ISMS role (5.3), a
 * required competence (7.2) or a planned communication (7.4).
 *
 * The register type decides which optional fields are used and which records
 * an entry links to ({@see RegisterType}); links are generic
 * ({@see IsmsRegisterLink}), so a new register needs no new table.
 * Owner convention as everywhere: user, person, deputies — no free text.
 */
#[ORM\Entity(repositoryClass: IsmsRegisterEntryRepository::class)]
#[ORM\Table(name: 'isms_register_entry')]
#[ORM\Index(name: 'idx_isms_register_tenant_type', columns: ['tenant_id', 'register_type'])]
#[ORM\Index(name: 'idx_isms_register_review', columns: ['next_review_date'])]
#[ORM\HasLifecycleCallbacks]
class IsmsRegisterEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(name: 'register_type', length: 40, enumType: RegisterType::class)]
    private RegisterType $type;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'register.validation.title_required')]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Register-specific choice ({@see RegisterType::categories()}). */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $category = null;

    /** 1–5: influence of an issue, benefit of an opportunity. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 1, max: 5)]
    private ?int $rating = null;

    /** Paragraph of a law, certificate of a competence, audience of a communication. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $reference = null;

    /** Communication rhythm ({@see RegisterType::frequencies()}). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $frequency = null;

    /** Appointed on (role), in force since (legal requirement). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $effectiveFrom = null;

    /** Role holder for a role, responsible person for everything else. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[SameTenantOwner]
    private ?User $ownerUser = null;

    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[SameTenantOwner]
    private ?Person $ownerPerson = null;

    /** @var Collection<int, Person> */
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'isms_register_entry_deputy')]
    #[ORM\JoinColumn(name: 'entry_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[SameTenantOwner]
    private Collection $deputyPersons;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $nextReviewDate = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $lastReviewedAt = null;

    /** Set when the entry no longer applies; archived entries stay as evidence. */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $archivedAt = null;

    /** Where a proposal came from (e.g. `context.external_issues#3f2a…`) — keeps proposals from coming back. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $origin = null;

    /** @var Collection<int, IsmsRegisterLink> */
    #[ORM\OneToMany(targetEntity: IsmsRegisterLink::class, mappedBy: 'entry', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $links;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct(RegisterType $type = RegisterType::ISSUE_EXTERNAL)
    {
        $this->type = $type;
        $this->deputyPersons = new ArrayCollection();
        $this->links = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
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

    public function getType(): RegisterType
    {
        return $this->type;
    }

    public function setType(RegisterType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;
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

    public function setCategory(?string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getRating(): ?int
    {
        return $this->rating;
    }

    public function setRating(?int $rating): static
    {
        $this->rating = $rating;
        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;
        return $this;
    }

    public function getFrequency(): ?string
    {
        return $this->frequency;
    }

    public function setFrequency(?string $frequency): static
    {
        $this->frequency = $frequency;
        return $this;
    }

    public function getEffectiveFrom(): ?DateTimeImmutable
    {
        return $this->effectiveFrom;
    }

    public function setEffectiveFrom(?DateTimeInterface $effectiveFrom): static
    {
        $this->effectiveFrom = $effectiveFrom === null ? null : DateTimeImmutable::createFromInterface($effectiveFrom);
        return $this;
    }

    public function getOwnerUser(): ?User
    {
        return $this->ownerUser;
    }

    public function setOwnerUser(?User $ownerUser): static
    {
        $this->ownerUser = $ownerUser;
        return $this;
    }

    public function getOwnerPerson(): ?Person
    {
        return $this->ownerPerson;
    }

    public function setOwnerPerson(?Person $ownerPerson): static
    {
        $this->ownerPerson = $ownerPerson;
        return $this;
    }

    /** @return Collection<int, Person> */
    public function getDeputyPersons(): Collection
    {
        return $this->deputyPersons;
    }

    public function addDeputyPerson(Person $person): static
    {
        if (!$this->deputyPersons->contains($person)) {
            $this->deputyPersons->add($person);
        }
        return $this;
    }

    public function removeDeputyPerson(Person $person): static
    {
        $this->deputyPersons->removeElement($person);
        return $this;
    }

    /** Display name of the owner (user before person), null when nobody is set. */
    public function getEffectiveOwner(): ?string
    {
        return OwnerResolver::resolveEffective($this->ownerUser, $this->ownerPerson);
    }

    public function getNextReviewDate(): ?DateTimeImmutable
    {
        return $this->nextReviewDate;
    }

    public function setNextReviewDate(?DateTimeInterface $nextReviewDate): static
    {
        $this->nextReviewDate = $nextReviewDate === null ? null : DateTimeImmutable::createFromInterface($nextReviewDate);
        return $this;
    }

    public function isReviewOverdue(?DateTimeInterface $today = null): bool
    {
        $today ??= new DateTimeImmutable('today');
        return !$this->isArchived() && $this->nextReviewDate !== null && $this->nextReviewDate < $today;
    }

    public function getLastReviewedAt(): ?DateTimeImmutable
    {
        return $this->lastReviewedAt;
    }

    /** Confirms the entry is still current and moves the next review by the register's interval. */
    public function markReviewed(DateTimeImmutable $at): static
    {
        $this->lastReviewedAt = $at;
        $this->nextReviewDate = $at->setTime(0, 0)->modify(sprintf('+%d months', $this->type->reviewMonths()));
        return $this;
    }

    public function getArchivedAt(): ?DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    public function archive(DateTimeImmutable $at): static
    {
        $this->archivedAt = $at;
        return $this;
    }

    public function restore(): static
    {
        $this->archivedAt = null;
        return $this;
    }

    public function getOrigin(): ?string
    {
        return $this->origin;
    }

    public function setOrigin(?string $origin): static
    {
        $this->origin = $origin;
        return $this;
    }

    /** @return Collection<int, IsmsRegisterLink> */
    public function getLinks(): Collection
    {
        return $this->links;
    }

    /** @return list<int> ids linked under one link field */
    public function linkedIds(string $field): array
    {
        $ids = [];
        foreach ($this->links as $link) {
            if ($link->getField() === $field) {
                $ids[] = $link->getRefId();
            }
        }
        return $ids;
    }

    /**
     * Replaces the links of one field with the given ids.
     *
     * @param list<int> $ids
     */
    public function replaceLinks(string $field, array $ids): static
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach ($this->links->toArray() as $link) {
            if ($link->getField() !== $field) {
                continue;
            }
            $pos = array_search($link->getRefId(), $ids, true);
            if ($pos === false) {
                $this->links->removeElement($link);
            } else {
                unset($ids[$pos]);
            }
        }
        foreach ($ids as $id) {
            $this->links->add(new IsmsRegisterLink($this, $field, $id));
        }
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Last change (creation counts) — what the management review reads for 9.3.2 b. */
    public function getChangedAt(): DateTimeImmutable
    {
        return $this->updatedAt ?? $this->createdAt;
    }

    public function __toString(): string
    {
        return (string) $this->title;
    }
}
