<?php

namespace App\Entity;

use App\Repository\ManagementReviewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ManagementReviewRepository::class)]
class ManagementReview
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $reviewDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $participants = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $changesRelevantToISMS = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $feedbackFromInterestedParties = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $auditResults = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $performanceEvaluation = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $nonConformitiesStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $correctiveActionsStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $previousReviewActions = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $opportunitiesForImprovement = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resourceNeeds = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $decisions = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $actionItems = null;

    #[ORM\Column(length: 50)]
    private ?string $status = 'planned';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getReviewDate(): ?\DateTimeInterface
    {
        return $this->reviewDate;
    }

    public function setReviewDate(\DateTimeInterface $reviewDate): static
    {
        $this->reviewDate = $reviewDate;
        return $this;
    }

    public function getParticipants(): ?string
    {
        return $this->participants;
    }

    public function setParticipants(?string $participants): static
    {
        $this->participants = $participants;
        return $this;
    }

    public function getChangesRelevantToISMS(): ?string
    {
        return $this->changesRelevantToISMS;
    }

    public function setChangesRelevantToISMS(?string $changesRelevantToISMS): static
    {
        $this->changesRelevantToISMS = $changesRelevantToISMS;
        return $this;
    }

    public function getFeedbackFromInterestedParties(): ?string
    {
        return $this->feedbackFromInterestedParties;
    }

    public function setFeedbackFromInterestedParties(?string $feedbackFromInterestedParties): static
    {
        $this->feedbackFromInterestedParties = $feedbackFromInterestedParties;
        return $this;
    }

    public function getAuditResults(): ?string
    {
        return $this->auditResults;
    }

    public function setAuditResults(?string $auditResults): static
    {
        $this->auditResults = $auditResults;
        return $this;
    }

    public function getPerformanceEvaluation(): ?string
    {
        return $this->performanceEvaluation;
    }

    public function setPerformanceEvaluation(?string $performanceEvaluation): static
    {
        $this->performanceEvaluation = $performanceEvaluation;
        return $this;
    }

    public function getNonConformitiesStatus(): ?string
    {
        return $this->nonConformitiesStatus;
    }

    public function setNonConformitiesStatus(?string $nonConformitiesStatus): static
    {
        $this->nonConformitiesStatus = $nonConformitiesStatus;
        return $this;
    }

    public function getCorrectiveActionsStatus(): ?string
    {
        return $this->correctiveActionsStatus;
    }

    public function setCorrectiveActionsStatus(?string $correctiveActionsStatus): static
    {
        $this->correctiveActionsStatus = $correctiveActionsStatus;
        return $this;
    }

    public function getPreviousReviewActions(): ?string
    {
        return $this->previousReviewActions;
    }

    public function setPreviousReviewActions(?string $previousReviewActions): static
    {
        $this->previousReviewActions = $previousReviewActions;
        return $this;
    }

    public function getOpportunitiesForImprovement(): ?string
    {
        return $this->opportunitiesForImprovement;
    }

    public function setOpportunitiesForImprovement(?string $opportunitiesForImprovement): static
    {
        $this->opportunitiesForImprovement = $opportunitiesForImprovement;
        return $this;
    }

    public function getResourceNeeds(): ?string
    {
        return $this->resourceNeeds;
    }

    public function setResourceNeeds(?string $resourceNeeds): static
    {
        $this->resourceNeeds = $resourceNeeds;
        return $this;
    }

    public function getDecisions(): ?string
    {
        return $this->decisions;
    }

    public function setDecisions(?string $decisions): static
    {
        $this->decisions = $decisions;
        return $this;
    }

    public function getActionItems(): ?string
    {
        return $this->actionItems;
    }

    public function setActionItems(?string $actionItems): static
    {
        $this->actionItems = $actionItems;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
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
}
