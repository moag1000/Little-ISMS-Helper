<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CommentRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit V3 C7 — Generic ISMS Comment.
 *
 * Polymorphic comment-thread entity attachable to any ISMS object via
 * (entity_type, entity_id). Drives the isms-comment Aurora pattern.
 *
 * Initial consumers: Risk, AuditFinding, Document. Show-templates pull
 * comments via CommentRepository::findThread($entityType, $entityId).
 */
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comments')]
#[ORM\Index(name: 'idx_comments_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_comments_entity', columns: ['entity_type', 'entity_id'])]
class Comment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    /**
     * Polymorphic target: short-name of the entity class
     * (e.g. 'Risk', 'AuditFinding', 'Document').
     */
    #[ORM\Column(length: 50)]
    private string $entityType = '';

    #[ORM\Column]
    private int $entityId = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $author = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(Tenant $tenant): static { $this->tenant = $tenant; return $this; }

    public function getEntityType(): string { return $this->entityType; }
    public function setEntityType(string $entityType): static { $this->entityType = $entityType; return $this; }

    public function getEntityId(): int { return $this->entityId; }
    public function setEntityId(int $entityId): static { $this->entityId = $entityId; return $this; }

    public function getAuthor(): ?User { return $this->author; }
    public function setAuthor(User $author): static { $this->author = $author; return $this; }

    public function getBody(): string { return $this->body; }
    public function setBody(string $body): static
    {
        $this->body = $body;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): ?DateTimeInterface { return $this->updatedAt; }
}
