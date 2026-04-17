<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EntityTagRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Polymorphic entity ↔ tag join with soft-delete history (WS-5 / ENT-1, MINOR-2).
 *
 * - `entityClass` + `entityId` target any application entity (Asset, Control, …).
 * - `taggedFrom` is when the tag was applied; `taggedUntil` NULL means currently active.
 * - Uniqueness of active tags per (tag, entity_class, entity_id) is enforced by a
 *   persistent generated column `active_marker` (see migration Version20260417230000).
 * - Soft-delete: setting `taggedUntil` + `removalReason` instead of DB-row removal,
 *   so audit/history queries always see the full timeline.
 */
#[ORM\Entity(repositoryClass: EntityTagRepository::class)]
#[ORM\Table(name: 'entity_tag')]
#[ORM\Index(name: 'idx_entity_tag_entity', columns: ['entity_class', 'entity_id'])]
#[ORM\Index(name: 'idx_entity_tag_active', columns: ['tagged_until'])]
#[ORM\Index(name: 'idx_entity_tag_tag', columns: ['tag_id'])]
#[ORM\Index(name: 'idx_entity_tag_tagged_by', columns: ['tagged_by_id'])]
class EntityTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tag::class, inversedBy: 'entityTags')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tag $tag = null;

    #[ORM\Column(length: 150)]
    private string $entityClass;

    #[ORM\Column(type: Types::INTEGER)]
    private int $entityId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'tagged_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $taggedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $taggedFrom;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $taggedUntil = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $removalReason = null;

    public function __construct()
    {
        $this->taggedFrom = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTag(): ?Tag
    {
        return $this->tag;
    }

    public function setTag(Tag $tag): static
    {
        $this->tag = $tag;
        return $this;
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function setEntityClass(string $entityClass): static
    {
        $this->entityClass = $entityClass;
        return $this;
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function setEntityId(int $entityId): static
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getTaggedBy(): ?User
    {
        return $this->taggedBy;
    }

    public function setTaggedBy(?User $user): static
    {
        $this->taggedBy = $user;
        return $this;
    }

    public function getTaggedFrom(): DateTimeImmutable
    {
        return $this->taggedFrom;
    }

    public function setTaggedFrom(DateTimeImmutable $taggedFrom): static
    {
        $this->taggedFrom = $taggedFrom;
        return $this;
    }

    public function getTaggedUntil(): ?DateTimeImmutable
    {
        return $this->taggedUntil;
    }

    public function setTaggedUntil(?DateTimeImmutable $taggedUntil): static
    {
        $this->taggedUntil = $taggedUntil;
        return $this;
    }

    public function getRemovalReason(): ?string
    {
        return $this->removalReason;
    }

    public function setRemovalReason(?string $reason): static
    {
        $this->removalReason = $reason;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->taggedUntil === null;
    }
}
