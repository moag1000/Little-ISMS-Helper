<?php

namespace App\Entity;

use App\Entity\Tenant;
use App\Repository\ManagementReviewRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\User;

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

    #[ORM\ManyToMany(targetEntity: User::class)]
    private Collection $participants;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $changesRelevantToISMS = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $feedbackFromInterestedParties = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $auditResults = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $performanceEvaluation = null;

    // Bestehendes Feld bleibt für Abwärtskompatibilität erhalten
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


    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    // Neue, formularkompatible Felder (Option B)
    #[ORM\ManyToOne(targetEntity: User::class)]
    private ?User $reviewedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $nonconformitiesReview = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $incidentsReview = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $risksReview = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $objectivesReview = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contextChanges = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $improvementOpportunities = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resourcesNeeded = null;

public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->participants = new ArrayCollection();
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

    /**
     * @return Collection<int, User>
     */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    public function addParticipant(User $user): static
    {
        if (!$this->participants->contains($user)) {
            $this->participants->add($user);
        }
        return $this;
    }

    public function removeParticipant(User $user): static
    {
        $this->participants->removeElement($user);
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

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?User $reviewedBy): static
    {
        $this->reviewedBy = $reviewedBy;
        return $this;
    }

    public function getNonconformitiesReview(): ?string
    {
        return $this->nonconformitiesReview;
    }

    public function setNonconformitiesReview(?string $nonconformitiesReview): static
    {
        $this->nonconformitiesReview = $nonconformitiesReview;
        return $this;
    }

    public function getIncidentsReview(): ?string
    {
        return $this->incidentsReview;
    }

    public function setIncidentsReview(?string $incidentsReview): static
    {
        $this->incidentsReview = $incidentsReview;
        return $this;
    }

    public function getRisksReview(): ?string
    {
        return $this->risksReview;
    }

    public function setRisksReview(?string $risksReview): static
    {
        $this->risksReview = $risksReview;
        return $this;
    }

    public function getObjectivesReview(): ?string
    {
        return $this->objectivesReview;
    }

    public function setObjectivesReview(?string $objectivesReview): static
    {
        $this->objectivesReview = $objectivesReview;
        return $this;
    }

    public function getContextChanges(): ?string
    {
        return $this->contextChanges;
    }

    public function setContextChanges(?string $contextChanges): static
    {
        $this->contextChanges = $contextChanges;
        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;
        return $this;
    }

    public function getImprovementOpportunities(): ?string
    {
        return $this->improvementOpportunities;
    }

    public function setImprovementOpportunities(?string $improvementOpportunities): static
    {
        $this->improvementOpportunities = $improvementOpportunities;
        return $this;
    }

    public function getResourcesNeeded(): ?string
    {
        return $this->resourcesNeeded;
    }

    public function setResourcesNeeded(?string $resourcesNeeded): static
    {
        $this->resourcesNeeded = $resourcesNeeded;
        return $this;
    }
}
