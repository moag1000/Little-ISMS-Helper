<?php

namespace App\Entity;

use DateTimeImmutable;
use App\Entity\Tenant;
use App\Repository\CrisisTeamRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Crisis Team Entity for BSI IT-Grundschutz 200-4 Compliance (Kapitel 4.3)
 * Krisenstab-Management (Crisis Management Team)
 */
#[ORM\Entity(repositoryClass: CrisisTeamRepository::class)]
#[ORM\Table(name: 'crisis_teams')]
#[ORM\Index(columns: ['team_name'], name: 'idx_crisis_team_name')]
#[ORM\Index(columns: ['is_active'], name: 'idx_crisis_team_active')]
class CrisisTeam
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Team name/identifier
     */
    #[ORM\Column(length: 255)]
    private ?string $teamName = null;

    /**
     * Team description and purpose
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Team type
     * - operational: Operational crisis team
     * - strategic: Strategic crisis management
     * - technical: Technical incident response
     * - communication: Crisis communication team
     */
    #[ORM\Column(length: 30)]
    private ?string $teamType = 'operational';

    /**
     * Is this team currently active?
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    /**
     * Team leader/coordinator
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $teamLeader = null;

    /**
     * Deputy team leader
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $deputyLeader = null;

    /**
     * Team members with roles
     * Format: [{'user_id': 123, 'name': 'John Doe', 'role': 'Security Expert', 'contact': '+49...', 'responsibilities': 'IT Security Analysis'}]
     */
    #[ORM\Column(type: Types::JSON)]
    private array $members = [];

    /**
     * Primary contact phone number
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $primaryPhone = null;

    /**
     * Primary contact email
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $primaryEmail = null;

    /**
     * Emergency contact details
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $emergencyContacts = [];

    /**
     * Meeting location (physical)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $meetingLocation = null;

    /**
     * Alternative/backup meeting location
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $backupMeetingLocation = null;

    /**
     * Virtual meeting URL (e.g., Teams, Zoom)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $virtualMeetingUrl = null;

    /**
     * Alert/activation procedures
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $alertProcedures = null;

    /**
     * Decision-making authority and escalation
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $decisionAuthority = null;

    /**
     * Communication protocols
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $communicationProtocols = null;

    /**
     * Resources and tools available
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $availableResources = [];

    /**
     * Training and exercise schedule
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $trainingSchedule = null;

    /**
     * Last activation date
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastActivatedAt = null;

    /**
     * Last training/exercise date
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastTrainingAt = null;

    /**
     * Next scheduled training date
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $nextTrainingAt = null;

    /**
     * Related business continuity plans
     *
     * @var Collection<int, BusinessContinuityPlan>
     */
    #[ORM\ManyToMany(targetEntity: BusinessContinuityPlan::class)]
    #[ORM\JoinTable(name: 'crisis_team_bcp')]
    private Collection $businessContinuityPlans;

    /**
     * Documentation and procedures
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $documentation = [];

    /**
     * Notes and additional information
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

public function __construct()
    {
        $this->businessContinuityPlans = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeamName(): ?string
    {
        return $this->teamName;
    }

    public function setTeamName(string $teamName): static
    {
        $this->teamName = $teamName;
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

    public function getTeamType(): ?string
    {
        return $this->teamType;
    }

    public function setTeamType(string $teamType): static
    {
        $this->teamType = $teamType;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getTeamLeader(): ?User
    {
        return $this->teamLeader;
    }

    public function setTeamLeader(?User $user): static
    {
        $this->teamLeader = $user;
        return $this;
    }

    public function getDeputyLeader(): ?User
    {
        return $this->deputyLeader;
    }

    public function setDeputyLeader(?User $user): static
    {
        $this->deputyLeader = $user;
        return $this;
    }

    public function getMembers(): array
    {
        return $this->members;
    }

    public function setMembers(array $members): static
    {
        $this->members = $members;
        return $this;
    }

    public function addMember(array $member): static
    {
        $this->members[] = $member;
        return $this;
    }

    public function removeMember(int $index): static
    {
        if (isset($this->members[$index])) {
            unset($this->members[$index]);
            $this->members = array_values($this->members);
        }
        return $this;
    }

    public function getMemberCount(): int
    {
        return count($this->members);
    }

    public function getPrimaryPhone(): ?string
    {
        return $this->primaryPhone;
    }

    public function setPrimaryPhone(?string $primaryPhone): static
    {
        $this->primaryPhone = $primaryPhone;
        return $this;
    }

    public function getPrimaryEmail(): ?string
    {
        return $this->primaryEmail;
    }

    public function setPrimaryEmail(?string $primaryEmail): static
    {
        $this->primaryEmail = $primaryEmail;
        return $this;
    }

    public function getEmergencyContacts(): ?array
    {
        return $this->emergencyContacts;
    }

    public function setEmergencyContacts(?array $emergencyContacts): static
    {
        $this->emergencyContacts = $emergencyContacts;
        return $this;
    }

    public function getMeetingLocation(): ?string
    {
        return $this->meetingLocation;
    }

    public function setMeetingLocation(?string $meetingLocation): static
    {
        $this->meetingLocation = $meetingLocation;
        return $this;
    }

    public function getBackupMeetingLocation(): ?string
    {
        return $this->backupMeetingLocation;
    }

    public function setBackupMeetingLocation(?string $backupMeetingLocation): static
    {
        $this->backupMeetingLocation = $backupMeetingLocation;
        return $this;
    }

    public function getVirtualMeetingUrl(): ?string
    {
        return $this->virtualMeetingUrl;
    }

    public function setVirtualMeetingUrl(?string $virtualMeetingUrl): static
    {
        $this->virtualMeetingUrl = $virtualMeetingUrl;
        return $this;
    }

    public function getAlertProcedures(): ?string
    {
        return $this->alertProcedures;
    }

    public function setAlertProcedures(?string $alertProcedures): static
    {
        $this->alertProcedures = $alertProcedures;
        return $this;
    }

    public function getDecisionAuthority(): ?string
    {
        return $this->decisionAuthority;
    }

    public function setDecisionAuthority(?string $decisionAuthority): static
    {
        $this->decisionAuthority = $decisionAuthority;
        return $this;
    }

    public function getCommunicationProtocols(): ?string
    {
        return $this->communicationProtocols;
    }

    public function setCommunicationProtocols(?string $communicationProtocols): static
    {
        $this->communicationProtocols = $communicationProtocols;
        return $this;
    }

    public function getAvailableResources(): ?array
    {
        return $this->availableResources;
    }

    public function setAvailableResources(?array $availableResources): static
    {
        $this->availableResources = $availableResources;
        return $this;
    }

    public function getTrainingSchedule(): ?string
    {
        return $this->trainingSchedule;
    }

    public function setTrainingSchedule(?string $trainingSchedule): static
    {
        $this->trainingSchedule = $trainingSchedule;
        return $this;
    }

    public function getLastActivatedAt(): ?DateTimeImmutable
    {
        return $this->lastActivatedAt;
    }

    public function setLastActivatedAt(?DateTimeImmutable $lastActivatedAt): static
    {
        $this->lastActivatedAt = $lastActivatedAt;
        return $this;
    }

    public function getLastTrainingAt(): ?DateTimeImmutable
    {
        return $this->lastTrainingAt;
    }

    public function setLastTrainingAt(?DateTimeImmutable $lastTrainingAt): static
    {
        $this->lastTrainingAt = $lastTrainingAt;
        return $this;
    }

    public function getNextTrainingAt(): ?DateTimeImmutable
    {
        return $this->nextTrainingAt;
    }

    public function setNextTrainingAt(?DateTimeImmutable $nextTrainingAt): static
    {
        $this->nextTrainingAt = $nextTrainingAt;
        return $this;
    }

    /**
     * @return Collection<int, BusinessContinuityPlan>
     */
    public function getBusinessContinuityPlans(): Collection
    {
        return $this->businessContinuityPlans;
    }

    public function addBusinessContinuityPlan(BusinessContinuityPlan $businessContinuityPlan): static
    {
        if (!$this->businessContinuityPlans->contains($businessContinuityPlan)) {
            $this->businessContinuityPlans->add($businessContinuityPlan);
        }

        return $this;
    }

    public function removeBusinessContinuityPlan(BusinessContinuityPlan $businessContinuityPlan): static
    {
        $this->businessContinuityPlans->removeElement($businessContinuityPlan);
        return $this;
    }

    public function getDocumentation(): ?array
    {
        return $this->documentation;
    }

    public function setDocumentation(?array $documentation): static
    {
        $this->documentation = $documentation;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Check if training is overdue
     */
    public function isTrainingOverdue(): bool
    {
        if (!$this->nextTrainingAt instanceof DateTimeImmutable) {
            return false;
        }

        return $this->nextTrainingAt < new DateTimeImmutable();
    }

    /**
     * Get days since last training
     */
    public function getDaysSinceLastTraining(): ?int
    {
        if (!$this->lastTrainingAt instanceof DateTimeImmutable) {
            return null;
        }

        $now = new DateTimeImmutable();
        $diff = $this->lastTrainingAt->diff($now);

        return $diff->days;
    }

    /**
     * Get team type display name
     */
    public function getTeamTypeDisplayName(): string
    {
        return match($this->teamType) {
            'operational' => 'Operativer Krisenstab',
            'strategic' => 'Strategischer Krisenstab',
            'technical' => 'Technischer Krisenstab',
            'communication' => 'Krisenkommunikation',
            default => 'Unbekannt',
        };
    }

    /**
     * Check if team is properly configured
     */
    public function isProperlyConfigured(): bool
    {
        return $this->teamLeader instanceof User
            && $this->members !== []
            && $this->primaryPhone !== null
            && $this->primaryEmail !== null;
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
