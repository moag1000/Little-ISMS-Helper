<?php

declare(strict_types=1);

namespace App\Entity\Authority;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Authority\Nis2RegistrationProfileRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * F29 — NIS-2 BSI-Portal Registrierungsprofil
 *
 * Stores the mandatory data set for yearly BSI-Portal re-registration
 * under NIS-2 (§ 33 BSIG). One profile per tenant; updated annually.
 *
 * The BSI Meldung-Portal (active since 2026-01) requires operators of
 * essential and important facilities to register and re-register yearly.
 * This entity collects all Pflichtfelder and can export a BSI-Portal-spec
 * JSON via Nis2BsiRegistrationService::exportToJson().
 *
 * Module gate: nis2_dora
 * ISO 27001: Clause 6.1 — information security objectives + planning
 * NIS-2: Art. 27 — registration obligations
 */
#[ORM\Entity(repositoryClass: Nis2RegistrationProfileRepository::class)]
#[ORM\Table(name: 'nis2_registration_profile')]
#[ORM\UniqueConstraint(name: 'uniq_nis2_profile_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_nis2_profile_next_due_at', columns: ['next_due_at'])]
#[ORM\HasLifecycleCallbacks]
class Nis2RegistrationProfile
{
    /** ENISA NIS-2 entity categories */
    public const string CATEGORY_ESSENTIAL = 'essential';
    public const string CATEGORY_IMPORTANT = 'important';

    public const array VALID_CATEGORIES = [
        self::CATEGORY_ESSENTIAL,
        self::CATEGORY_IMPORTANT,
    ];

    /** ENISA NIS-2 sector taxonomy (Annex I + II) */
    public const string SECTOR_ENERGY = 'energy';
    public const string SECTOR_TRANSPORT = 'transport';
    public const string SECTOR_BANKING = 'banking';
    public const string SECTOR_FINANCIAL_MARKET = 'financial_market';
    public const string SECTOR_HEALTH = 'health';
    public const string SECTOR_DRINKING_WATER = 'drinking_water';
    public const string SECTOR_WASTE_WATER = 'waste_water';
    public const string SECTOR_DIGITAL_INFRASTRUCTURE = 'digital_infrastructure';
    public const string SECTOR_ICT_SERVICE_MANAGEMENT = 'ict_service_management';
    public const string SECTOR_PUBLIC_ADMINISTRATION = 'public_administration';
    public const string SECTOR_SPACE = 'space';
    public const string SECTOR_POSTAL_COURIER = 'postal_courier';
    public const string SECTOR_WASTE_MANAGEMENT = 'waste_management';
    public const string SECTOR_CHEMICALS = 'chemicals';
    public const string SECTOR_FOOD = 'food';
    public const string SECTOR_MANUFACTURING = 'manufacturing';
    public const string SECTOR_DIGITAL_PROVIDERS = 'digital_providers';
    public const string SECTOR_RESEARCH = 'research';
    public const string SECTOR_OTHER = 'other';

    public const array VALID_SECTORS = [
        self::SECTOR_ENERGY,
        self::SECTOR_TRANSPORT,
        self::SECTOR_BANKING,
        self::SECTOR_FINANCIAL_MARKET,
        self::SECTOR_HEALTH,
        self::SECTOR_DRINKING_WATER,
        self::SECTOR_WASTE_WATER,
        self::SECTOR_DIGITAL_INFRASTRUCTURE,
        self::SECTOR_ICT_SERVICE_MANAGEMENT,
        self::SECTOR_PUBLIC_ADMINISTRATION,
        self::SECTOR_SPACE,
        self::SECTOR_POSTAL_COURIER,
        self::SECTOR_WASTE_MANAGEMENT,
        self::SECTOR_CHEMICALS,
        self::SECTOR_FOOD,
        self::SECTOR_MANUFACTURING,
        self::SECTOR_DIGITAL_PROVIDERS,
        self::SECTOR_RESEARCH,
        self::SECTOR_OTHER,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $organizationLegalName = '';

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $organizationLegalForm = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $commercialRegisterCity = '';

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $commercialRegisterNumber = '';

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $vatId = null;

    /** @var array<int, string> NACE codes e.g. ["J62.01", "J62.02"] */
    #[ORM\Column(type: Types::JSON)]
    private array $naceCodes = [];

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $nis2Sector = '';

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $nis2EntityCategory = self::CATEGORY_IMPORTANT;

    #[ORM\Column(type: Types::INTEGER)]
    private int $affectedHeadcount = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $affectedAnnualTurnoverEur = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $ictDependencyDescription = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'incident_reporting_contact_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $incidentReportingContact = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'security_responsible_contact_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $securityResponsibleContact = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'backup_security_contact_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $backupSecurityContact = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastReportedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $nextDueAt;

    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $portalConfirmationNumber = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->nextDueAt = new DateTimeImmutable('+1 year');
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getOrganizationLegalName(): string
    {
        return $this->organizationLegalName;
    }

    public function setOrganizationLegalName(string $organizationLegalName): static
    {
        $this->organizationLegalName = $organizationLegalName;
        return $this;
    }

    public function getOrganizationLegalForm(): string
    {
        return $this->organizationLegalForm;
    }

    public function setOrganizationLegalForm(string $organizationLegalForm): static
    {
        $this->organizationLegalForm = $organizationLegalForm;
        return $this;
    }

    public function getCommercialRegisterCity(): string
    {
        return $this->commercialRegisterCity;
    }

    public function setCommercialRegisterCity(string $commercialRegisterCity): static
    {
        $this->commercialRegisterCity = $commercialRegisterCity;
        return $this;
    }

    public function getCommercialRegisterNumber(): string
    {
        return $this->commercialRegisterNumber;
    }

    public function setCommercialRegisterNumber(string $commercialRegisterNumber): static
    {
        $this->commercialRegisterNumber = $commercialRegisterNumber;
        return $this;
    }

    public function getVatId(): ?string
    {
        return $this->vatId;
    }

    public function setVatId(?string $vatId): static
    {
        $this->vatId = $vatId;
        return $this;
    }

    /** @return array<int, string> */
    public function getNaceCodes(): array
    {
        return $this->naceCodes;
    }

    /** @param array<int, string> $naceCodes */
    public function setNaceCodes(array $naceCodes): static
    {
        $this->naceCodes = $naceCodes;
        return $this;
    }

    public function getNis2Sector(): string
    {
        return $this->nis2Sector;
    }

    public function setNis2Sector(string $nis2Sector): static
    {
        $this->nis2Sector = $nis2Sector;
        return $this;
    }

    public function getNis2EntityCategory(): string
    {
        return $this->nis2EntityCategory;
    }

    public function setNis2EntityCategory(string $nis2EntityCategory): static
    {
        $this->nis2EntityCategory = $nis2EntityCategory;
        return $this;
    }

    public function getAffectedHeadcount(): int
    {
        return $this->affectedHeadcount;
    }

    public function setAffectedHeadcount(int $affectedHeadcount): static
    {
        $this->affectedHeadcount = $affectedHeadcount;
        return $this;
    }

    public function getAffectedAnnualTurnoverEur(): ?string
    {
        return $this->affectedAnnualTurnoverEur;
    }

    public function setAffectedAnnualTurnoverEur(?string $affectedAnnualTurnoverEur): static
    {
        $this->affectedAnnualTurnoverEur = $affectedAnnualTurnoverEur;
        return $this;
    }

    public function getIctDependencyDescription(): string
    {
        return $this->ictDependencyDescription;
    }

    public function setIctDependencyDescription(string $ictDependencyDescription): static
    {
        $this->ictDependencyDescription = $ictDependencyDescription;
        return $this;
    }

    public function getIncidentReportingContact(): ?User
    {
        return $this->incidentReportingContact;
    }

    public function setIncidentReportingContact(?User $incidentReportingContact): static
    {
        $this->incidentReportingContact = $incidentReportingContact;
        return $this;
    }

    public function getSecurityResponsibleContact(): ?User
    {
        return $this->securityResponsibleContact;
    }

    public function setSecurityResponsibleContact(?User $securityResponsibleContact): static
    {
        $this->securityResponsibleContact = $securityResponsibleContact;
        return $this;
    }

    public function getBackupSecurityContact(): ?User
    {
        return $this->backupSecurityContact;
    }

    public function setBackupSecurityContact(?User $backupSecurityContact): static
    {
        $this->backupSecurityContact = $backupSecurityContact;
        return $this;
    }

    public function getLastReportedAt(): ?DateTimeImmutable
    {
        return $this->lastReportedAt;
    }

    public function setLastReportedAt(?DateTimeImmutable $lastReportedAt): static
    {
        $this->lastReportedAt = $lastReportedAt;
        return $this;
    }

    public function getNextDueAt(): DateTimeImmutable
    {
        return $this->nextDueAt;
    }

    public function setNextDueAt(DateTimeImmutable $nextDueAt): static
    {
        $this->nextDueAt = $nextDueAt;
        return $this;
    }

    public function getPortalConfirmationNumber(): ?string
    {
        return $this->portalConfirmationNumber;
    }

    public function setPortalConfirmationNumber(?string $portalConfirmationNumber): static
    {
        $this->portalConfirmationNumber = $portalConfirmationNumber;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Returns true if this profile is overdue (nextDueAt is in the past).
     */
    public function isOverdue(): bool
    {
        return $this->nextDueAt < new DateTimeImmutable();
    }

    /**
     * Returns true if the registration is due within the given number of days.
     */
    public function isDueSoon(int $days = 30): bool
    {
        $threshold = new DateTimeImmutable(sprintf('+%d days', $days));
        return $this->nextDueAt <= $threshold && !$this->isOverdue();
    }
}
