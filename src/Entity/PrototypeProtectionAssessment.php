<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PrototypeProtectionAssessmentRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * TISAX / VDA ISA 6.0 — Prototype-Protection Assessment (Kapitel 8).
 *
 * Covers VDA-ISA-6 Kapitel 8 sub-sections:
 *   8.1 Physical and environmental security (prototype zones)
 *   8.2 Organisational requirements
 *   8.3 Handling and transport
 *   8.4 Protection during trial operation (test drives, field tests)
 *   8.5 Events and shoots (photo / film / press events)
 *
 * Each sub-section is scored with a 4-value status and free-form notes.
 * Evidence documents can be attached via the shared {@see Document} M:M.
 *
 * Required TISAX label is one of the prototype-specific labels:
 *   - prototype_parts_components
 *   - prototype_vehicles
 *   - test_vehicles
 *   - events_and_shoots
 * Multiple labels may apply — stored as JSON list.
 */
#[ORM\Entity(repositoryClass: PrototypeProtectionAssessmentRepository::class)]
#[ORM\Table(name: 'prototype_protection_assessment')]
#[ORM\Index(name: 'idx_ppa_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_ppa_status', columns: ['status'])]
#[ORM\Index(name: 'idx_ppa_assessment_date', columns: ['assessment_date'])]
#[ORM\HasLifecycleCallbacks]
class PrototypeProtectionAssessment
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    public const RESULT_NOT_APPLICABLE = 'not_applicable';
    public const RESULT_NOT_MET = 'not_met';
    public const RESULT_PARTIAL = 'partial';
    public const RESULT_MET = 'met';
    public const RESULT_EXCEEDED = 'exceeded';

    public const LABEL_PROTOTYPE_PARTS = 'prototype_parts_components';
    public const LABEL_PROTOTYPE_VEHICLES = 'prototype_vehicles';
    public const LABEL_TEST_VEHICLES = 'test_vehicles';
    public const LABEL_EVENTS_AND_SHOOTS = 'events_and_shoots';

    public const SECTIONS = ['physical', 'organisation', 'handling', 'trial_operation', 'events'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'prototype_protection.validation.title_required')]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $scope = null;

    #[ORM\Column(length: 30, options: ['default' => self::STATUS_DRAFT])]
    #[Assert\Choice(choices: [
        self::STATUS_DRAFT,
        self::STATUS_IN_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_EXPIRED,
    ])]
    private string $status = self::STATUS_DRAFT;

    /** TISAX Assessment Level — AL2 for standard, AL3 for high-protection prototypes. */
    #[ORM\Column(length: 5, nullable: true)]
    #[Assert\Choice(choices: ['AL2', 'AL3'], message: 'prototype_protection.validation.level_invalid')]
    private ?string $tisaxLevel = null;

    /**
     * Required TISAX prototype-protection labels (1..n).
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $requiredLabels = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Supplier $supplier = null;

    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Location $location = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assessor = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $assessmentDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $nextAssessmentDue = null;

    /**
     * Overall conclusion across all sections.
     * Computed heuristic on save but may be overridden manually.
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $overallResult = null;

    // ── Section 8.1 Physical and environmental security ─────────────────
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $physicalResult = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $physicalNotes = null;

    // ── Section 8.2 Organisational requirements ─────────────────────────
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $organisationResult = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $organisationNotes = null;

    // ── Section 8.3 Handling and transport ──────────────────────────────
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $handlingResult = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $handlingNotes = null;

    // ── Section 8.4 Protection during trial operation ───────────────────
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $trialOperationResult = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $trialOperationNotes = null;

    // ── Section 8.5 Events and shoots ───────────────────────────────────
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $eventsResult = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $eventsNotes = null;

    /** Shared Evidence documents (Clause 7.5 / TISAX proof). */
    #[ORM\ManyToMany(targetEntity: Document::class)]
    #[ORM\JoinTable(name: 'prototype_protection_evidence')]
    private Collection $evidenceDocuments;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->evidenceDocuments = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): self { $this->tenant = $tenant; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): self { $this->title = $title; return $this; }

    public function getScope(): ?string { return $this->scope; }
    public function setScope(?string $scope): self { $this->scope = $scope; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getTisaxLevel(): ?string { return $this->tisaxLevel; }
    public function setTisaxLevel(?string $level): self { $this->tisaxLevel = $level; return $this; }

    /** @return list<string>|null */
    public function getRequiredLabels(): ?array { return $this->requiredLabels; }
    /** @param list<string>|null $labels */
    public function setRequiredLabels(?array $labels): self { $this->requiredLabels = $labels; return $this; }

    public function getSupplier(): ?Supplier { return $this->supplier; }
    public function setSupplier(?Supplier $supplier): self { $this->supplier = $supplier; return $this; }

    public function getLocation(): ?Location { return $this->location; }
    public function setLocation(?Location $location): self { $this->location = $location; return $this; }

    public function getAssessor(): ?User { return $this->assessor; }
    public function setAssessor(?User $assessor): self { $this->assessor = $assessor; return $this; }

    public function getAssessmentDate(): ?DateTimeInterface { return $this->assessmentDate; }
    public function setAssessmentDate(?DateTimeInterface $d): self { $this->assessmentDate = $d; return $this; }

    public function getNextAssessmentDue(): ?DateTimeInterface { return $this->nextAssessmentDue; }
    public function setNextAssessmentDue(?DateTimeInterface $d): self { $this->nextAssessmentDue = $d; return $this; }

    public function getOverallResult(): ?string { return $this->overallResult; }
    public function setOverallResult(?string $r): self { $this->overallResult = $r; return $this; }

    public function getPhysicalResult(): ?string { return $this->physicalResult; }
    public function setPhysicalResult(?string $r): self { $this->physicalResult = $r; return $this; }
    public function getPhysicalNotes(): ?string { return $this->physicalNotes; }
    public function setPhysicalNotes(?string $n): self { $this->physicalNotes = $n; return $this; }

    public function getOrganisationResult(): ?string { return $this->organisationResult; }
    public function setOrganisationResult(?string $r): self { $this->organisationResult = $r; return $this; }
    public function getOrganisationNotes(): ?string { return $this->organisationNotes; }
    public function setOrganisationNotes(?string $n): self { $this->organisationNotes = $n; return $this; }

    public function getHandlingResult(): ?string { return $this->handlingResult; }
    public function setHandlingResult(?string $r): self { $this->handlingResult = $r; return $this; }
    public function getHandlingNotes(): ?string { return $this->handlingNotes; }
    public function setHandlingNotes(?string $n): self { $this->handlingNotes = $n; return $this; }

    public function getTrialOperationResult(): ?string { return $this->trialOperationResult; }
    public function setTrialOperationResult(?string $r): self { $this->trialOperationResult = $r; return $this; }
    public function getTrialOperationNotes(): ?string { return $this->trialOperationNotes; }
    public function setTrialOperationNotes(?string $n): self { $this->trialOperationNotes = $n; return $this; }

    public function getEventsResult(): ?string { return $this->eventsResult; }
    public function setEventsResult(?string $r): self { $this->eventsResult = $r; return $this; }
    public function getEventsNotes(): ?string { return $this->eventsNotes; }
    public function setEventsNotes(?string $n): self { $this->eventsNotes = $n; return $this; }

    /** @return Collection<int, Document> */
    public function getEvidenceDocuments(): Collection { return $this->evidenceDocuments; }

    public function addEvidenceDocument(Document $doc): self
    {
        if (!$this->evidenceDocuments->contains($doc)) {
            $this->evidenceDocuments->add($doc);
        }
        return $this;
    }

    public function removeEvidenceDocument(Document $doc): self
    {
        $this->evidenceDocuments->removeElement($doc);
        return $this;
    }

    public function getCreatedAt(): ?DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): ?DateTimeInterface { return $this->updatedAt; }

    /**
     * Returns section results as a map keyed by short section name.
     *
     * @return array<string, array{result: ?string, notes: ?string}>
     */
    public function getSectionResults(): array
    {
        return [
            'physical' => ['result' => $this->physicalResult, 'notes' => $this->physicalNotes],
            'organisation' => ['result' => $this->organisationResult, 'notes' => $this->organisationNotes],
            'handling' => ['result' => $this->handlingResult, 'notes' => $this->handlingNotes],
            'trial_operation' => ['result' => $this->trialOperationResult, 'notes' => $this->trialOperationNotes],
            'events' => ['result' => $this->eventsResult, 'notes' => $this->eventsNotes],
        ];
    }

    /**
     * Heuristic overall result — worst section drives the conclusion.
     * `not_applicable` is excluded from the aggregate.
     */
    public function computeOverallResult(): ?string
    {
        $severity = [
            self::RESULT_NOT_MET => 4,
            self::RESULT_PARTIAL => 3,
            self::RESULT_MET => 2,
            self::RESULT_EXCEEDED => 1,
        ];

        $worst = null;
        $worstScore = 0;
        foreach ($this->getSectionResults() as $row) {
            $r = $row['result'];
            if ($r === null || $r === self::RESULT_NOT_APPLICABLE) {
                continue;
            }
            $score = $severity[$r] ?? 0;
            if ($score > $worstScore) {
                $worstScore = $score;
                $worst = $r;
            }
        }
        return $worst;
    }
}
