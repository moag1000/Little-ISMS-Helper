<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ActionItemReferenceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Polymorphic provenance link (0..N) from an {@see ActionItem} to a source entity
 * or a compliance clause. Association, NOT a mirror — the referenced source keeps
 * its own source-of-truth.
 *
 * No DB foreign key on (refType, refId): the target can be any of the ~16 source
 * tables, so resolution happens through the SourceAdapterRegistry. (refType, refId)
 * is indexed; tenant is carried for scope queries.
 */
#[ORM\Entity(repositoryClass: ActionItemReferenceRepository::class)]
#[ORM\Table(name: 'action_item_references')]
#[ORM\Index(name: 'idx_air_target', columns: ['ref_type', 'ref_id'])]
#[ORM\UniqueConstraint(name: 'uniq_action_item_ref', columns: ['action_item_id', 'ref_type', 'ref_id'])]
class ActionItemReference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ActionItem::class, inversedBy: 'references')]
    #[ORM\JoinColumn(name: 'action_item_id', nullable: false, onDelete: 'CASCADE')]
    private ?ActionItem $actionItem = null;

    /** Source-adapter slug, or 'compliance_requirement' / 'clause'. */
    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    private ?string $refType = null;

    #[ORM\Column]
    private ?int $refId = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActionItem(): ?ActionItem
    {
        return $this->actionItem;
    }

    public function setActionItem(?ActionItem $actionItem): static
    {
        $this->actionItem = $actionItem;
        return $this;
    }

    public function getRefType(): ?string
    {
        return $this->refType;
    }

    public function setRefType(?string $refType): static
    {
        $this->refType = $refType;
        return $this;
    }

    public function getRefId(): ?int
    {
        return $this->refId;
    }

    public function setRefId(?int $refId): static
    {
        $this->refId = $refId;
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
}
