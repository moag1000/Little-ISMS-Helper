<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Generic link from a register entry to another record (pattern of
 * {@see ActionItemReference}). `field` names the link kind of
 * {@see \App\Service\Register\RegisterLinkTypes} (`risk`, `supplier`,
 * `competence_entry`, …); the kind knows the target class. Child row: it
 * inherits the tenant of its entry.
 */
#[ORM\Entity]
#[ORM\Table(name: 'isms_register_link')]
#[ORM\Index(name: 'idx_isms_register_link_target', columns: ['field', 'ref_id'])]
#[ORM\UniqueConstraint(name: 'uniq_isms_register_link', columns: ['entry_id', 'field', 'ref_id'])]
class IsmsRegisterLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: IsmsRegisterEntry::class, inversedBy: 'links')]
    #[ORM\JoinColumn(name: 'entry_id', nullable: false, onDelete: 'CASCADE')]
    private IsmsRegisterEntry $entry;

    #[ORM\Column(length: 40)]
    private string $field;

    #[ORM\Column]
    private int $refId;

    public function __construct(IsmsRegisterEntry $entry, string $field, int $refId)
    {
        $this->entry = $entry;
        $this->field = $field;
        $this->refId = $refId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntry(): IsmsRegisterEntry
    {
        return $this->entry;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getRefId(): int
    {
        return $this->refId;
    }
}
