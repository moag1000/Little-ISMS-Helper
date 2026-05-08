<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentControlLinkRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Policy-Wizard W3 — explicit link from a generated Document to the
 * Control(s) it covers (§8.1 of `docs/plans/policy-wizard/05-architecture.md`).
 *
 * The Control entity already maintains a `evidenceDocuments` ManyToMany
 * for the inverse query, but auditors want a first-class link table
 * with provenance metadata: which source produced the link
 * (manual / wizard / inheritance) and what evidence type the document
 * represents (policy / procedure / record). This entity is the
 * authoritative record; the ManyToMany on Control is the read-side
 * projection.
 */
#[ORM\Entity(repositoryClass: DocumentControlLinkRepository::class)]
#[ORM\Table(name: 'document_control_link')]
#[ORM\UniqueConstraint(name: 'uq_dcl_document_control', columns: ['document_id', 'control_id'])]
#[ORM\Index(name: 'idx_dcl_document', columns: ['document_id'])]
#[ORM\Index(name: 'idx_dcl_control', columns: ['control_id'])]
#[ORM\Index(name: 'idx_dcl_source', columns: ['source'])]
class DocumentControlLink
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_POLICY_WIZARD = 'policy_wizard';
    public const SOURCE_INHERITANCE = 'inheritance';

    public const SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_POLICY_WIZARD,
        self::SOURCE_INHERITANCE,
    ];

    public const EVIDENCE_POLICY = 'policy_document';
    public const EVIDENCE_PROCEDURE = 'procedure_document';
    public const EVIDENCE_RECORD = 'record';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Document $document = null;

    #[ORM\ManyToOne(targetEntity: Control::class)]
    #[ORM\JoinColumn(name: 'control_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Control $control = null;

    /**
     * Provenance discriminator: where did this link originate?
     */
    #[ORM\Column(length: 32, options: ['default' => self::SOURCE_MANUAL])]
    private string $source = self::SOURCE_MANUAL;

    /**
     * Evidence type from the auditor's lens. Default `policy_document`
     * because the policy-wizard pipeline produces governance papers.
     */
    #[ORM\Column(length: 50, options: ['default' => self::EVIDENCE_POLICY])]
    private string $evidenceType = self::EVIDENCE_POLICY;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        ?Document $document = null,
        ?Control $control = null,
        string $source = self::SOURCE_MANUAL,
        string $evidenceType = self::EVIDENCE_POLICY,
    ) {
        $this->document = $document;
        $this->control = $control;
        $this->source = $source;
        $this->evidenceType = $evidenceType;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(?Document $document): static
    {
        $this->document = $document;
        return $this;
    }

    public function getControl(): ?Control
    {
        return $this->control;
    }

    public function setControl(?Control $control): static
    {
        $this->control = $control;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getEvidenceType(): string
    {
        return $this->evidenceType;
    }

    public function setEvidenceType(string $evidenceType): static
    {
        $this->evidenceType = $evidenceType;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
