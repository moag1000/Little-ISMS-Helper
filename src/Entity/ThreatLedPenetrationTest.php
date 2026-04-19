<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ThreatLedPenetrationTestRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * DORA Art. 26 Threat-Led Penetration Test (TLPT).
 * Tracks an engagement from scoping through execution to the final report
 * and links any resulting findings to the existing AuditFinding pipeline.
 */
#[ORM\Entity(repositoryClass: ThreatLedPenetrationTestRepository::class)]
#[ORM\Table(name: 'threat_led_penetration_test')]
#[ORM\Index(name: 'idx_tlpt_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_tlpt_status', columns: ['status'])]
#[ORM\Index(name: 'idx_tlpt_planned_date', columns: ['planned_start_date'])]
class ThreatLedPenetrationTest
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_SCOPING = 'scoping';
    public const STATUS_RED_TEAM = 'red_team';
    public const STATUS_REPORTING = 'reporting';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PROVIDER_INTERNAL = 'internal';
    public const PROVIDER_EXTERNAL = 'external';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 50)]
    private ?string $engagementNumber = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $scope = null;

    /** Threat intelligence basis (scenarios, TTPs) referenced in scoping. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $threatIntelligenceBasis = null;

    #[ORM\Column(length: 20)]
    private string $providerType = self::PROVIDER_EXTERNAL;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $testProvider = null;

    /** ISO-3166 alpha-2 codes for the jurisdictions covered (DORA RTS Art. 3). */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $jurisdictionCodes = null;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_PLANNED;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $plannedStartDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $plannedEndDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $actualStartDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $actualEndDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $executiveSummary = null;

    /** Findings produced by the engagement — feed the AuditFinding workflow. */
    #[ORM\ManyToMany(targetEntity: AuditFinding::class)]
    #[ORM\JoinTable(
        name: 'tlpt_finding',
        joinColumns: [new ORM\JoinColumn(onDelete: 'CASCADE')],
        inverseJoinColumns: [new ORM\JoinColumn(onDelete: 'CASCADE')]
    )]
    private Collection $findings;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->findings = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getEngagementNumber(): ?string
    {
        return $this->engagementNumber;
    }

    public function setEngagementNumber(?string $engagementNumber): static
    {
        $this->engagementNumber = $engagementNumber;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(?string $scope): static
    {
        $this->scope = $scope;
        return $this;
    }

    public function getThreatIntelligenceBasis(): ?string
    {
        return $this->threatIntelligenceBasis;
    }

    public function setThreatIntelligenceBasis(?string $basis): static
    {
        $this->threatIntelligenceBasis = $basis;
        return $this;
    }

    public function getProviderType(): string
    {
        return $this->providerType;
    }

    public function setProviderType(string $providerType): static
    {
        $this->providerType = $providerType;
        return $this;
    }

    public function getTestProvider(): ?string
    {
        return $this->testProvider;
    }

    public function setTestProvider(?string $provider): static
    {
        $this->testProvider = $provider;
        return $this;
    }

    /** @return array<int, string>|null */
    public function getJurisdictionCodes(): ?array
    {
        return $this->jurisdictionCodes;
    }

    /** @param array<int, string>|null $codes */
    public function setJurisdictionCodes(?array $codes): static
    {
        $this->jurisdictionCodes = $codes;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getPlannedStartDate(): ?DateTimeInterface
    {
        return $this->plannedStartDate;
    }

    public function setPlannedStartDate(?DateTimeInterface $date): static
    {
        $this->plannedStartDate = $date;
        return $this;
    }

    public function getPlannedEndDate(): ?DateTimeInterface
    {
        return $this->plannedEndDate;
    }

    public function setPlannedEndDate(?DateTimeInterface $date): static
    {
        $this->plannedEndDate = $date;
        return $this;
    }

    public function getActualStartDate(): ?DateTimeInterface
    {
        return $this->actualStartDate;
    }

    public function setActualStartDate(?DateTimeInterface $date): static
    {
        $this->actualStartDate = $date;
        return $this;
    }

    public function getActualEndDate(): ?DateTimeInterface
    {
        return $this->actualEndDate;
    }

    public function setActualEndDate(?DateTimeInterface $date): static
    {
        $this->actualEndDate = $date;
        return $this;
    }

    public function getExecutiveSummary(): ?string
    {
        return $this->executiveSummary;
    }

    public function setExecutiveSummary(?string $summary): static
    {
        $this->executiveSummary = $summary;
        return $this;
    }

    /** @return Collection<int, AuditFinding> */
    public function getFindings(): Collection
    {
        return $this->findings;
    }

    public function addFinding(AuditFinding $finding): static
    {
        if (!$this->findings->contains($finding)) {
            $this->findings->add($finding);
        }
        return $this;
    }

    public function removeFinding(AuditFinding $finding): static
    {
        $this->findings->removeElement($finding);
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
