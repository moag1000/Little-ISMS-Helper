<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use App\Repository\TenantRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[ORM\Index(name: 'idx_tenant_status', columns: ['status'])]
#[UniqueEntity(fields: ['code'], message: 'tenant.validation.code_unique')]
#[ORM\HasLifecycleCallbacks]
class Tenant
{
    // Junior-ISB-Audit Phase-2 Lifecycle — Tenant (security-critical, 30+ isActive callsites preserved via wrapper).
    // 5-stage lifecycle: draft → active ⇄ suspended → terminated → archived.
    // SUPER_ADMIN-gated, 4-eyes on suspend/reactivate/terminate.
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_TERMINATED = 'terminated';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $azureTenantId = null;

    /**
     * Junior-ISB-Audit Phase-2 Lifecycle — Tenant (security-critical, 30+ isActive callsites preserved via wrapper).
     *
     * Legacy boolean preserved for backward-compat with 30+ readers:
     *   - Repository::createQueryBuilder()->where('t.isActive = :active')
     *   - findBy(['isActive' => true])  (Doctrine hits the column directly, NOT the method)
     *   - Twig templates calling tenant.isActive
     *
     * Kept in lockstep with `status` via setStatus(): every status transition
     * also mutates isActive so the column-level Doctrine queries stay correct.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    /**
     * Junior-ISB-Audit Phase-2 Lifecycle — Tenant (security-critical, 30+ isActive callsites preserved via wrapper).
     * Owned by `tenant_lifecycle` — never call setStatus() directly outside
     * the initial-marking bootstrap; route transitions through
     * LifecycleService::transition().
     */
    #[ORM\Column(length: 30, options: ['default' => self::STATUS_DRAFT])]
    private string $status = self::STATUS_DRAFT;

    /**
     * Junior-ISB-Audit Phase-2 Lifecycle — Tenant (security-critical, 30+ isActive callsites preserved via wrapper).
     * Optimistic-lock guard for concurrent lifecycle transitions.
     */
    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer', options: ['default' => 0])]
    private int $lockVersion = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $settings = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null;

    // Phase 8L.F3 — E-Mail-Branding. null = Fallback-Kaskade greift
    // (Tenant → Ancestors → SystemSettings → Hardcoded).
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $emailFromName = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $emailFromAddress = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Url(requireTld: true)]
    #[Assert\Regex(pattern: '#^https://#', message: 'tenant.email.logo_url.https_only')]
    private ?string $emailLogoUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $emailFooterText = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $emailSupportAddress = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'tenant')]
    private Collection $users;

    // Corporate Structure fields
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'subsidiaries')]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'SET NULL')]
    private ?Tenant $parent = null;

    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    private Collection $subsidiaries;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isCorporateParent = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $corporateNotes = null;

    /**
     * BSI 200-2 phase model: tracks where the tenant stands in the
     * IT-Grundschutz adoption journey. Null until the tenant opts in.
     */
    public const BSI_PHASE_INITIATION = 'initiation';
    public const BSI_PHASE_ANALYSIS = 'analysis';
    public const BSI_PHASE_CONCEPT = 'concept';
    public const BSI_PHASE_IMPLEMENTATION = 'implementation';
    public const BSI_PHASE_CONTINUOUS = 'continuous';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $bsiPhase = null;

    /**
     * BSI IT-Grundschutz Vorgehensweise (assurance level):
     * - basis:    Basis-Absicherung — rapid, reduced scope
     * - standard: Standard-Absicherung — full IT-Grundschutz methodology (default)
     * - kern:     Kern-Absicherung — crown-jewel-focused, high-value asset subset
     *
     * This is the Vorgehensweise the tenant has chosen, distinct from the
     * requirement-tier values (basis/standard/hoch) used in AbsicherungsStufe.
     */
    #[ORM\Column(length: 20, options: ['default' => 'standard'])]
    private string $bsiAssuranceLevel = 'standard';

    /**
     * Phase 9.P1.7 — NIS2 classification per German BSIG §28:
     * - essential:     "besonders wichtige Einrichtung" (Annex I, 250+ MA / 50M€)
     * - important:     "wichtige Einrichtung"           (Annex II, 50+ MA / 10M€)
     * - not_regulated: below thresholds, out of NIS2 scope
     * - unknown:       classification not yet reviewed
     *
     * Always per-Rechtsperson (per BSIG §28) — hence one value per Tenant,
     * never aggregated across a holding tree.
     */
    public const NIS2_ESSENTIAL     = 'essential';
    public const NIS2_IMPORTANT     = 'important';
    public const NIS2_NOT_REGULATED = 'not_regulated';
    public const NIS2_UNKNOWN       = 'unknown';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $nis2Classification = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $nis2Sector = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $naceCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $legalForm = null;

    /**
     * Bucket-6a (DORA RoI Sprint 9) — Legal Entity Identifier per ISO 17442.
     * 20-character alphanumeric LEI as issued by a GLEIF-accredited LOU.
     * Required for DORA Art. 28 ROI XBRL export — wired into B_01.01.0020
     * (reporting-entity-LEI). Nullable to keep non-DORA-obligated tenants
     * working without forced migration of pre-existing rows.
     *
     * Format: [A-Z0-9]{18}\d{2} (18 LOU-prefix + 2 ISO 17442 checksum digits).
     */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    #[Assert\Regex(
        pattern: '/^[A-Z0-9]{18}\d{2}$/',
        message: 'tenant.validation.lei_code_format',
    )]
    private ?string $leiCode = null;

    /**
     * Bucket-6a (DORA RoI Sprint 9) — ISO 4217 reporting currency for DORA
     * Art. 28 RoI XBRL output (B_01.01.0040). EUR default; tenants reporting
     * in other currencies (CHF, GBP, USD) override here.
     */
    #[ORM\Column(length: 3, nullable: true, options: ['default' => 'EUR'])]
    #[Assert\Length(min: 3, max: 3)]
    #[Assert\Regex(
        pattern: '/^[A-Z]{3}$/',
        message: 'tenant.validation.reporting_currency_format',
    )]
    private ?string $reportingCurrency = 'EUR';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nis2ContactPoint = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nis2RegisteredAt = null;

    /**
     * DORA Art. 2 entity classification — determines whether this tenant is subject to DORA:
     * - none:                    Not subject to DORA (default — most tenants)
     * - financial_entity:        Bank / Versicherer / Investmentfirm per DORA Art. 2(2)
     * - critical_ict_third_party: Designated CTPP by ESAs per DORA Art. 31
     *
     * When 'none', all DORA-specific UI, routes, and AlvaHint rules are suppressed.
     * This flag is tenant-level (is this organisation DORA-obligated at all?).
     * Entity-level DORA relevance (which assets/suppliers are RoI-relevant) is
     * managed via Supplier.isDoraRelevant and Asset.isDoraRelevant.
     */
    public const DORA_NONE                     = 'none';
    public const DORA_FINANCIAL_ENTITY         = 'financial_entity';
    public const DORA_CRITICAL_ICT_THIRD_PARTY = 'critical_ict_third_party';

    #[ORM\Column(length: 40, nullable: false, options: ['default' => 'none'])]
    private string $doraEntityCategory = self::DORA_NONE;

    // Tier-1 Compliance Settings (locale + audit-window + DPO + TLP + supervisory authorities + retention policies).
    #[ORM\Column(length: 10, nullable: true, options: ['default' => 'de_DE'])]
    private ?string $locale = 'de_DE';

    #[ORM\Column(length: 50, nullable: true, options: ['default' => 'Europe/Berlin'])]
    private ?string $timezone = 'Europe/Berlin';

    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['default' => 1, 'comment' => 'Financial year start month 1-12'])]
    private ?int $financialYearStartMonth = 1;

    #[ORM\Column(length: 16, nullable: true, options: ['default' => 'amber', 'comment' => 'TLP default for incident sharing: clear|green|amber|red'])]
    private ?string $tlpDefault = 'amber';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dpoContactName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dpoContactEmail = null;

    // ── BDSG § 38 / GDPR Art. 37 — DPO appointment register (N-4) ────────────
    // Auditors routinely ask for proof of the DPO appointment: when, internal or
    // external, whether the supervisory authority was notified (Art. 37(7)), and
    // the deputy. Contact name/email alone do not evidence the appointment.
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dpoAppointmentDate = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $dpoIsExternal = false;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dpoAuthorityNotifiedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dpoDeputyName = null;

    // ── GDPR Art. 30(1)(a) — postal address of the controller (M-5) ──────────
    // A RoPA must carry the controller's contactable postal address, not just a
    // name; without it the register is formally incomplete for an authority.
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressStreet = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $addressPostalCode = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $addressCity = null;

    #[ORM\Column(length: 2, nullable: true, options: ['comment' => 'ISO 3166-1 alpha-2'])]
    private ?string $addressCountry = null;

    // ── GDPR Art. 27 — representative for non-EU controllers (M-5) ───────────
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $representativeName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $representativeContact = null;

    /** @var array<string, array{name: string, email: string, phone?: string, scope?: string}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Supervisory authorities map keyed by jurisdiction/role (BSI, BaFin, LDA, CSIRT-bund etc.)'])]
    private ?array $supervisoryAuthorities = null;

    /** @var array<string, array{retention_days: int, auto_delete: bool}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'GDPR retention policies keyed by data category (e.g. crm, contracts, audit_evidence)'])]
    private ?array $dataRetentionPolicies = null;

    // Tier-2 Operational Settings (risk methodology, matrix size, notifications, wizard maturity target, CSIRT endpoints, on-call rotation).
    #[ORM\Column(length: 32, nullable: true, options: ['default' => 'iso_27005', 'comment' => 'iso_27005|nist_800_30|fair|custom'])]
    private ?string $riskMethodology = 'iso_27005';

    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['default' => 5, 'comment' => 'Risk matrix size 3, 4, 5'])]
    private ?int $riskMatrixSize = 5;

    #[ORM\Column(length: 32, nullable: true, options: ['default' => 'baseline', 'comment' => 'Default maturity target across wizards: baseline|enhanced'])]
    private ?string $wizardMaturityTarget = 'baseline';

    /** @var array<string, array{email?: bool, slack?: bool, teams?: bool}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Notification preferences keyed by event type (incident, breach, audit_finding, training_overdue)'])]
    private ?array $notificationPreferences = null;

    /** @var array<string, array{url?: string, contact?: string, auth_type?: string}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'CSIRT endpoints for automated incident reporting (BSI, sectoral CSIRT, ENISA)'])]
    private ?array $csirtEndpoints = null;

    /** @var array<int, array{date: string, primary?: string, deputy?: string}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Crisis team on-call rotation entries (each: date, primary, deputy)'])]
    private ?array $crisisTeamOnCall = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['default' => 600, 'comment' => 'API requests/minute per tenant (default 600 = 10/sec)'])]
    private ?int $apiRateLimitPerMinute = 600;

    /** When true, all users in this tenant are required to login via SSO (local passwords locked). */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $ssoEnforced = false;

    public function isSsoEnforced(): bool { return $this->ssoEnforced; }
    public function setSsoEnforced(bool $v): static { $this->ssoEnforced = $v; return $this; }

    // ── F43 Trust-Center / Public-Posture-Page ────────────────────────────────
    // publicPostureEnabled: tenant opt-in gate (default off — no disclosure).
    // publicPostureToken: random 32+ char bearer used in /trust/{token} URL.
    // TENANT-DISCLOSURE-SAFE: exposes ONLY §4 compliance-posture data;
    // risk/asset/user/incident/DPO/findings NEVER leave the authenticated perimeter.

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $publicPostureEnabled = false;

    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $publicPostureToken = null;

    public function isPublicPostureEnabled(): bool { return $this->publicPostureEnabled; }
    public function setPublicPostureEnabled(bool $v): static { $this->publicPostureEnabled = $v; return $this; }

    public function getPublicPostureToken(): ?string { return $this->publicPostureToken; }
    public function setPublicPostureToken(?string $v): static { $this->publicPostureToken = $v; return $this; }

    public function getBsiPhase(): ?string
    {
        return $this->bsiPhase;
    }

    public function setBsiPhase(?string $bsiPhase): static
    {
        $this->bsiPhase = $bsiPhase;
        return $this;
    }

    public function getBsiAssuranceLevel(): string
    {
        return $this->bsiAssuranceLevel;
    }

    public function setBsiAssuranceLevel(string $level): static
    {
        if (!in_array($level, ['basis', 'standard', 'kern'], true)) {
            throw new \App\Exception\InvalidArgument\InvalidArgumentException('Invalid BSI assurance level: ' . $level);
        }
        $this->bsiAssuranceLevel = $level;
        return $this;
    }

    public function getNis2Classification(): ?string
    {
        return $this->nis2Classification;
    }

    public function setNis2Classification(?string $value): static
    {
        $this->nis2Classification = $value;
        return $this;
    }

    public function getNis2Sector(): ?string
    {
        return $this->nis2Sector;
    }

    public function setNis2Sector(?string $value): static
    {
        $this->nis2Sector = $value;
        return $this;
    }

    public function getNaceCode(): ?string
    {
        return $this->naceCode;
    }

    public function setNaceCode(?string $value): static
    {
        $this->naceCode = $value;
        return $this;
    }

    public function getLegalName(): ?string
    {
        return $this->legalName;
    }

    public function setLegalName(?string $value): static
    {
        $this->legalName = $value;
        return $this;
    }

    public function getLegalForm(): ?string
    {
        return $this->legalForm;
    }

    public function setLegalForm(?string $value): static
    {
        $this->legalForm = $value;
        return $this;
    }

    /**
     * Bucket-6a — ISO 17442 Legal Entity Identifier. Wired into DORA RoI
     * XBRL export (B_01.01.0020). Null when the tenant is not DORA-obligated.
     */
    public function getLeiCode(): ?string
    {
        return $this->leiCode;
    }

    public function setLeiCode(?string $value): static
    {
        $this->leiCode = $value !== null ? strtoupper(trim($value)) : null;
        return $this;
    }

    /**
     * Bucket-6a — ISO 4217 reporting currency. EUR default. Wired into DORA
     * RoI XBRL export (B_01.01.0040) and the iso4217:* unit measure.
     */
    public function getReportingCurrency(): ?string
    {
        return $this->reportingCurrency ?? 'EUR';
    }

    public function setReportingCurrency(?string $value): static
    {
        $this->reportingCurrency = $value !== null ? strtoupper(trim($value)) : null;
        return $this;
    }

    public function getNis2ContactPoint(): ?string
    {
        return $this->nis2ContactPoint;
    }

    public function setNis2ContactPoint(?string $value): static
    {
        $this->nis2ContactPoint = $value;
        return $this;
    }

    public function getNis2RegisteredAt(): ?\DateTimeImmutable
    {
        return $this->nis2RegisteredAt;
    }

    public function setNis2RegisteredAt(?\DateTimeImmutable $value): static
    {
        $this->nis2RegisteredAt = $value;
        return $this;
    }

    public function isNis2Regulated(): bool
    {
        return in_array($this->nis2Classification, [self::NIS2_ESSENTIAL, self::NIS2_IMPORTANT], true);
    }

    public function getDoraEntityCategory(): string
    {
        return $this->doraEntityCategory;
    }

    public function setDoraEntityCategory(string $doraEntityCategory): static
    {
        $this->doraEntityCategory = $doraEntityCategory;
        return $this;
    }

    /**
     * Returns true when this tenant is subject to DORA (any category other than 'none').
     */
    public function isDoraObligated(): bool
    {
        return $this->doraEntityCategory !== self::DORA_NONE;
    }

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->subsidiaries = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * Required by Symfony's DoctrineType EntityType when callers don't pass
     * an explicit `choice_label` callback. Without this, form-rendering
     * throws "Object of class App\Entity\Tenant could not be converted to
     * string" (DoctrineType.php:54).
     */
    public function __toString(): string
    {
        return $this->name ?? sprintf('Tenant#%d', $this->id ?? 0);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
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

    public function getAzureTenantId(): ?string
    {
        return $this->azureTenantId;
    }

    public function setAzureTenantId(?string $azureTenantId): static
    {
        $this->azureTenantId = $azureTenantId;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * Junior-ISB-Audit Phase-2 Lifecycle — Tenant (security-critical, 30+ isActive callsites preserved via wrapper).
     *
     * Direct setter preserved for backward-compat with 30+ readers. Mirrors
     * the boolean into `status` so the lifecycle marking stays consistent:
     *   true  → STATUS_ACTIVE (unless already in a non-active terminal state)
     *   false → STATUS_SUSPENDED (unless already terminated/archived)
     *
     * For new code prefer `LifecycleService::transition($tenant,
     * 'tenant_lifecycle', 'suspend'|'activate'|...)`.
     */
    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        // Mirror into status — but never resurrect a terminal record.
        if (!in_array($this->status, [self::STATUS_TERMINATED, self::STATUS_ARCHIVED], true)) {
            $this->status = $isActive ? self::STATUS_ACTIVE : self::STATUS_SUSPENDED;
        }

        return $this;
    }

    /**
     * Junior-ISB-Audit Phase-2 Lifecycle — Tenant (security-critical, 30+ isActive callsites preserved via wrapper).
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Junior-ISB-Audit Phase-2 Lifecycle — Tenant (security-critical, 30+ isActive callsites preserved via wrapper).
     *
     * Do NOT call directly outside the initial-marking bootstrap; route
     * transitions through LifecycleService::transition(). The
     * Symfony-Workflow marking-store calls this setter to apply state
     * changes; the boolean `isActive` mirror is kept in lockstep so the
     * 30+ legacy readers (including Doctrine `findBy(['isActive' => true])`)
     * stay correct.
     */
    public function setStatus(string $status): static
    {
        $this->status = $status;
        // Mirror status → isActive. Only `active` keeps the legacy boolean
        // true; every other place (draft / suspended / terminated /
        // archived) flips it to false so existing voter + repository
        // filters stay correct.
        $this->isActive = ($status === self::STATUS_ACTIVE);
        return $this;
    }

    /**
     * Junior-ISB-Audit Phase-2 Lifecycle — Tenant (security-critical, 30+ isActive callsites preserved via wrapper).
     */
    public function getLockVersion(): int
    {
        return $this->lockVersion;
    }

    public function getSettings(): ?array
    {
        return $this->settings;
    }

    public function setSettings(?array $settings): static
    {
        $this->settings = $settings;
        return $this;
    }

    // Phase 8L.F3 — E-Mail-Branding Getter/Setter
    public function getEmailFromName(): ?string { return $this->emailFromName; }
    public function setEmailFromName(?string $v): static { $this->emailFromName = $v; return $this; }

    public function getEmailFromAddress(): ?string { return $this->emailFromAddress; }
    public function setEmailFromAddress(?string $v): static { $this->emailFromAddress = $v; return $this; }

    public function getEmailLogoUrl(): ?string { return $this->emailLogoUrl; }
    public function setEmailLogoUrl(?string $v): static { $this->emailLogoUrl = $v; return $this; }

    public function getEmailFooterText(): ?string { return $this->emailFooterText; }
    public function setEmailFooterText(?string $v): static { $this->emailFooterText = $v; return $this; }

    public function getEmailSupportAddress(): ?string { return $this->emailSupportAddress; }
    public function setEmailSupportAddress(?string $v): static { $this->emailSupportAddress = $v; return $this; }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): static
    {
        $this->logoPath = $logoPath;
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
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->setTenant($this);
        }

        return $this;
    }

    public function removeUser(User $user): static
    {
        if ($this->users->removeElement($user) && $user->getTenant() === $this) {
            $user->setTenant(null);
        }

        return $this;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    // Corporate Structure methods
    public function getParent(): ?Tenant
    {
        return $this->parent;
    }

    public function setParent(?Tenant $parent): static
    {
        if ($parent instanceof Tenant) {
            if ($parent === $this) {
                // @intentional-assertion: programmer-error guard — cycle detection in entity graph
                throw new \LogicException('A tenant cannot be its own parent');
            }
            if ($parent->isChildOf($this)) {
                // @intentional-assertion: programmer-error guard — cycle detection in entity graph
                throw new \LogicException(sprintf(
                    'Setting parent would create a cycle: tenant "%s" is already a descendant of "%s"',
                    $parent->getCode() ?? '?',
                    $this->getCode() ?? '?'
                ));
            }
        }

        $this->parent = $parent;
        return $this;
    }

    /**
     * True when $this is a direct or indirect descendant of $candidate.
     */
    public function isChildOf(Tenant $candidate): bool
    {
        $current = $this->parent;
        while ($current instanceof Tenant) {
            if ($current === $candidate) {
                return true;
            }
            $current = $current->getParent();
        }
        return false;
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function getSubsidiaries(): Collection
    {
        return $this->subsidiaries;
    }

    public function addSubsidiary(Tenant $tenant): static
    {
        if (!$this->subsidiaries->contains($tenant)) {
            $this->subsidiaries->add($tenant);
            $tenant->setParent($this);
        }

        return $this;
    }

    public function removeSubsidiary(Tenant $tenant): static
    {
        if ($this->subsidiaries->removeElement($tenant) && $tenant->getParent() === $this) {
            $tenant->setParent(null);
        }

        return $this;
    }

    public function isCorporateParent(): bool
    {
        return $this->isCorporateParent;
    }

    public function setIsCorporateParent(bool $isCorporateParent): static
    {
        $this->isCorporateParent = $isCorporateParent;
        return $this;
    }

    public function getCorporateNotes(): ?string
    {
        return $this->corporateNotes;
    }

    public function setCorporateNotes(?string $corporateNotes): static
    {
        $this->corporateNotes = $corporateNotes;
        return $this;
    }

    /**
     * Check if this tenant is part of a corporate structure
     */
    public function isPartOfCorporateStructure(): bool
    {
        return $this->parent instanceof Tenant || $this->subsidiaries->count() > 0;
    }

    /**
     * Get the root parent (top of corporate hierarchy)
     */
    public function getRootParent(): Tenant
    {
        $current = $this;
        while ($current->getParent() instanceof Tenant) {
            $current = $current->getParent();
        }
        return $current;
    }

    /**
     * Get all subsidiaries recursively (including sub-subsidiaries)
     */
    public function getAllSubsidiaries(): array
    {
        $allSubsidiaries = [];
        foreach ($this->subsidiaries as $subsidiary) {
            $allSubsidiaries[] = $subsidiary;
            $allSubsidiaries = array_merge($allSubsidiaries, $subsidiary->getAllSubsidiaries());
        }
        return $allSubsidiaries;
    }

    /**
     * Get depth in corporate hierarchy (0 = root/parent, 1 = direct subsidiary, etc.)
     */
    public function getHierarchyDepth(): int
    {
        $depth = 0;
        $current = $this;
        while ($current->getParent() instanceof Tenant) {
            $depth++;
            $current = $current->getParent();
        }
        return $depth;
    }

    /**
     * Get all ancestors in the corporate hierarchy (parent, grandparent, etc.)
     * Returns array with immediate parent first, root parent last
     *
     * @return Tenant[] Array of ancestor tenants
     */
    public function getAllAncestors(): array
    {
        $ancestors = [];
        $current = $this->parent;

        while ($current instanceof Tenant) {
            $ancestors[] = $current;
            $current = $current->getParent();
        }

        return $ancestors;
    }

    // Tier-1 Compliance Settings — getters/setters

    public function getLocale(): ?string { return $this->locale; }
    public function setLocale(?string $locale): static { $this->locale = $locale; return $this; }

    public function getTimezone(): ?string { return $this->timezone; }
    public function setTimezone(?string $timezone): static { $this->timezone = $timezone; return $this; }

    public function getFinancialYearStartMonth(): ?int { return $this->financialYearStartMonth; }
    public function setFinancialYearStartMonth(?int $month): static { $this->financialYearStartMonth = $month; return $this; }

    public function getTlpDefault(): ?string { return $this->tlpDefault; }
    public function setTlpDefault(?string $tlp): static { $this->tlpDefault = $tlp; return $this; }

    public function getDpoContactName(): ?string { return $this->dpoContactName; }
    public function setDpoContactName(?string $name): static { $this->dpoContactName = $name; return $this; }

    public function getDpoContactEmail(): ?string { return $this->dpoContactEmail; }
    public function setDpoContactEmail(?string $email): static { $this->dpoContactEmail = $email; return $this; }

    public function getAddressStreet(): ?string { return $this->addressStreet; }
    public function setAddressStreet(?string $v): static { $this->addressStreet = $v; return $this; }
    public function getAddressPostalCode(): ?string { return $this->addressPostalCode; }
    public function setAddressPostalCode(?string $v): static { $this->addressPostalCode = $v; return $this; }
    public function getAddressCity(): ?string { return $this->addressCity; }
    public function setAddressCity(?string $v): static { $this->addressCity = $v; return $this; }
    public function getAddressCountry(): ?string { return $this->addressCountry; }
    public function setAddressCountry(?string $v): static { $this->addressCountry = $v; return $this; }
    public function getRepresentativeName(): ?string { return $this->representativeName; }
    public function setRepresentativeName(?string $v): static { $this->representativeName = $v; return $this; }
    public function getRepresentativeContact(): ?string { return $this->representativeContact; }
    public function setRepresentativeContact(?string $v): static { $this->representativeContact = $v; return $this; }

    public function getDpoAppointmentDate(): ?\DateTimeImmutable { return $this->dpoAppointmentDate; }
    public function setDpoAppointmentDate(?\DateTimeImmutable $v): static { $this->dpoAppointmentDate = $v; return $this; }
    public function isDpoExternal(): bool { return $this->dpoIsExternal; }
    public function setDpoIsExternal(bool $v): static { $this->dpoIsExternal = $v; return $this; }
    public function getDpoAuthorityNotifiedAt(): ?\DateTimeImmutable { return $this->dpoAuthorityNotifiedAt; }
    public function setDpoAuthorityNotifiedAt(?\DateTimeImmutable $v): static { $this->dpoAuthorityNotifiedAt = $v; return $this; }
    public function getDpoDeputyName(): ?string { return $this->dpoDeputyName; }
    public function setDpoDeputyName(?string $v): static { $this->dpoDeputyName = $v; return $this; }

    /** @return array<string, array{name: string, email: string, phone?: string, scope?: string}>|null */
    public function getSupervisoryAuthorities(): ?array { return $this->supervisoryAuthorities; }

    /** @param array<string, array{name: string, email: string, phone?: string, scope?: string}>|null $authorities */
    public function setSupervisoryAuthorities(?array $authorities): static { $this->supervisoryAuthorities = $authorities; return $this; }

    /** @return array<string, array{retention_days: int, auto_delete: bool}>|null */
    public function getDataRetentionPolicies(): ?array { return $this->dataRetentionPolicies; }

    /** @param array<string, array{retention_days: int, auto_delete: bool}>|null $policies */
    public function setDataRetentionPolicies(?array $policies): static { $this->dataRetentionPolicies = $policies; return $this; }

    public function getRiskMethodology(): ?string { return $this->riskMethodology; }
    public function setRiskMethodology(?string $m): static { $this->riskMethodology = $m; return $this; }

    public function getRiskMatrixSize(): ?int { return $this->riskMatrixSize; }
    public function setRiskMatrixSize(?int $size): static { $this->riskMatrixSize = $size; return $this; }

    public function getWizardMaturityTarget(): ?string { return $this->wizardMaturityTarget; }
    public function setWizardMaturityTarget(?string $target): static { $this->wizardMaturityTarget = $target; return $this; }

    /** @return array<string, array{email?: bool, slack?: bool, teams?: bool}>|null */
    public function getNotificationPreferences(): ?array { return $this->notificationPreferences; }

    /** @param array<string, array{email?: bool, slack?: bool, teams?: bool}>|null $prefs */
    public function setNotificationPreferences(?array $prefs): static { $this->notificationPreferences = $prefs; return $this; }

    /** @return array<string, array{url?: string, contact?: string, auth_type?: string}>|null */
    public function getCsirtEndpoints(): ?array { return $this->csirtEndpoints; }

    /** @param array<string, array{url?: string, contact?: string, auth_type?: string}>|null $endpoints */
    public function setCsirtEndpoints(?array $endpoints): static { $this->csirtEndpoints = $endpoints; return $this; }

    /** @return array<int, array{date: string, primary?: string, deputy?: string}>|null */
    public function getCrisisTeamOnCall(): ?array { return $this->crisisTeamOnCall; }

    /** @param array<int, array{date: string, primary?: string, deputy?: string}>|null $rotation */
    public function setCrisisTeamOnCall(?array $rotation): static { $this->crisisTeamOnCall = $rotation; return $this; }

    public function getApiRateLimitPerMinute(): ?int { return $this->apiRateLimitPerMinute; }
    public function setApiRateLimitPerMinute(?int $limit): static { $this->apiRateLimitPerMinute = $limit; return $this; }
}
