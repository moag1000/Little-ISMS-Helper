<?php

namespace App\Entity;

use App\Repository\InterestedPartyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Interested Party Entity for ISO 27001 Chapter 4.2
 *
 * Understanding the needs and expectations of interested parties
 */
#[ORM\Entity(repositoryClass: InterestedPartyRepository::class)]
#[ORM\Index(columns: ['party_type'], name: 'idx_party_type')]
#[ORM\Index(columns: ['importance'], name: 'idx_party_importance')]
class InterestedParty
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Party name is required')]
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
    private ?string $partyType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $contactPerson = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $phone = null;

    /**
     * Importance/Influence: critical, high, medium, low
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['critical', 'high', 'medium', 'low'])]
    private ?string $importance = 'medium';

    /**
     * Requirements and expectations from this party
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Requirements must be documented')]
    private ?string $requirements = null;

    /**
     * Legal/Regulatory/Contractual requirements (JSON)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $legalRequirements = null;

    /**
     * How their requirements are addressed
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $howAddressed = null;

    /**
     * Communication frequency and method
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Choice(choices: ['daily', 'weekly', 'monthly', 'quarterly', 'annually', 'as_needed'])]
    private ?string $communicationFrequency = 'as_needed';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $communicationMethod = null;

    /**
     * Last communication date
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastCommunication = null;

    /**
     * Next planned communication
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $nextCommunication = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $feedback = null;

    /**
     * Satisfaction level (1-5)
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 1, max: 5)]
    private ?int $satisfactionLevel = null;

    /**
     * Issues/Concerns
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $issues = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
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

    public function getPartyType(): ?string
    {
        return $this->partyType;
    }

    public function setPartyType(string $partyType): static
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

    public function setImportance(string $importance): static
    {
        $this->importance = $importance;
        return $this;
    }

    public function getRequirements(): ?string
    {
        return $this->requirements;
    }

    public function setRequirements(string $requirements): static
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

    public function getLastCommunication(): ?\DateTimeInterface
    {
        return $this->lastCommunication;
    }

    public function setLastCommunication(?\DateTimeInterface $lastCommunication): static
    {
        $this->lastCommunication = $lastCommunication;
        return $this;
    }

    public function getNextCommunication(): ?\DateTimeInterface
    {
        return $this->nextCommunication;
    }

    public function setNextCommunication(?\DateTimeInterface $nextCommunication): static
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
     * Check if communication is overdue
     */
    public function isCommunicationOverdue(): bool
    {
        if (!$this->nextCommunication) {
            return false;
        }

        return $this->nextCommunication < new \DateTime();
    }

    /**
     * Get communication status
     */
    public function getCommunicationStatus(): string
    {
        if ($this->lastCommunication === null) {
            return 'never_communicated';
        }

        if ($this->isCommunicationOverdue()) {
            return 'overdue';
        }

        if ($this->nextCommunication) {
            $sevenDaysFromNow = new \DateTime('+7 days');
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
        if ($this->lastCommunication) {
            $daysSinceLastComm = (new \DateTime())->diff($this->lastCommunication)->days;
            if ($daysSinceLastComm <= 30) $score += 30;
            elseif ($daysSinceLastComm <= 90) $score += 20;
            elseif ($daysSinceLastComm <= 180) $score += 10;
        }

        // No outstanding issues (20%)
        if (empty($this->issues)) {
            $score += 20;
        }

        return (int)$score;
    }
}
