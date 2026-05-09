<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryPresetBundleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Policy-Wizard W4-B — Industry-Preset bundle (first-class entity).
 *
 * Captures sector-specific defaults that pre-fill the Policy-Wizard's
 * Step 1 + Step 4 (Annex-A applicability). Driven by the
 * Senior-Consultant review (P3) and Phase 4-C §3 W4 — four bundles
 * ship in v1: Healthcare, Public-Sector / KRITIS, B2C-SaaS,
 * OT / IEC 62443.
 *
 * Bundles are global (no `tenant_id` — they are catalogue rows,
 * not tenant data). A user picks a bundle in Step 1, the
 * {@see \App\Service\PolicyWizard\PresetBundleApplier} merges the
 * bundle defaults into the run's `inputs` snapshot, and the user
 * may still adjust everything before pressing "Continue".
 *
 * Spec: docs/plans/policy-wizard/05-architecture.md §6 + §7,
 *       docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md §3 W4.
 */
#[ORM\Entity(repositoryClass: IndustryPresetBundleRepository::class)]
#[ORM\Table(name: 'industry_preset_bundle')]
#[ORM\UniqueConstraint(name: 'uq_industry_preset_bundle_key', columns: ['bundle_key'])]
#[ORM\Index(name: 'idx_industry_preset_bundle_active', columns: ['is_active'])]
class IndustryPresetBundle
{
    public const KEY_HEALTHCARE = 'healthcare';
    public const KEY_PUBLIC_SECTOR = 'public_sector';
    public const KEY_B2C_SAAS = 'b2c_saas';
    public const KEY_OT_IEC62443 = 'ot_iec62443';
    /**
     * Junior-ISB-friendly fallback bundle: no industry assumptions —
     * the user fills Step 4+5 manually. Pre-selects only the mandatory
     * ISO 27001 baseline. Added per Junior-Implementer-Persona feedback.
     */
    public const KEY_CUSTOM_GENERAL = 'custom_general';

    public const ALLOWED_KEYS = [
        self::KEY_HEALTHCARE,
        self::KEY_PUBLIC_SECTOR,
        self::KEY_B2C_SAAS,
        self::KEY_OT_IEC62443,
        self::KEY_CUSTOM_GENERAL,
    ];

    public const STANDARD_ISO27001 = 'iso27001';
    public const STANDARD_ISO_DORA = 'iso27001+dora';
    public const STANDARD_ISO_GDPR = 'iso27001+gdpr';
    public const STANDARD_ISO_BSI = 'iso27001+bsi';
    public const STANDARD_ISO_ALL = 'iso27001+all';

    public const ALLOWED_STANDARDS = [
        self::STANDARD_ISO27001,
        self::STANDARD_ISO_DORA,
        self::STANDARD_ISO_GDPR,
        self::STANDARD_ISO_BSI,
        self::STANDARD_ISO_ALL,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Stable identifier exposed in URLs / select boxes (e.g. 'healthcare').
     * Stored as `bundle_key` to avoid the SQL reserved word `key`.
     */
    #[ORM\Column(name: 'bundle_key', length: 50)]
    private string $key;

    #[ORM\Column(length: 200)]
    private string $label;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Standards-mix descriptor — drives the default `standardsAdopted`
     * combination Step 1 will pre-tick.
     */
    #[ORM\Column(length: 32)]
    private string $standard = self::STANDARD_ISO27001;

    /**
     * The standards keys that get pre-selected in Step 1 (mirrors
     * {@see \App\Service\PolicyWizard\Step\WelcomeStandardsStep::ALLOWED_STANDARDS}).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $preselectedStandards = [];

    /**
     * Default risk-appetite tier (1 = very conservative, 5 = aggressive).
     * Pre-fills Step 4 Risk-Classification.
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 3])]
    private int $defaultRiskAppetiteTier = 3;

    /**
     * Number of data-classification levels (3 or 4).
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 3])]
    private int $defaultDataClassificationLevels = 3;

    /**
     * Default RPO in hours for the operational baseline.
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 24])]
    private int $defaultBackupRpoHours = 24;

    /**
     * Default patch SLA for critical vulnerabilities, in hours.
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 72])]
    private int $defaultPatchSlaCriticalHours = 72;

    /**
     * Annex-A control-id → 'applicable'|'not_applicable'|'auto'
     * (auto = leave loader default). Step 4 uses the override map to
     * pre-tick the SoA checkboxes for sector-specific must-haves.
     *
     * @var array<string, string>
     */
    #[ORM\Column(name: 'annex_a_applicability_overrides', type: Types::JSON)]
    private array $annexAApplicabilityOverrides = [];

    /**
     * topic-key → list of role keys. Refines
     * {@see \App\Service\PolicyWizard\PolicyAudienceResolver}'s default
     * audience map for industry-specific reading audiences.
     *
     * @var array<string, list<string>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $topicAudienceOverrides = [];

    /**
     * Whether DPO / privacy sections are auto-enabled when this bundle
     * applies (e.g. Healthcare flips this true).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $dpoSectionsAutoEnabled = false;

    /**
     * Free-text regulatory references shown in the Step 1 preview, e.g.
     * ['§ 22 BDSG', '§ 203 StGB'].
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $regulatoryReferences = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): static
    {
        $this->key = $key;
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;
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

    public function getStandard(): string
    {
        return $this->standard;
    }

    public function setStandard(string $standard): static
    {
        $this->standard = $standard;
        return $this;
    }

    /** @return list<string> */
    public function getPreselectedStandards(): array
    {
        return $this->preselectedStandards;
    }

    /** @param list<string> $preselectedStandards */
    public function setPreselectedStandards(array $preselectedStandards): static
    {
        $this->preselectedStandards = array_values($preselectedStandards);
        return $this;
    }

    public function getDefaultRiskAppetiteTier(): int
    {
        return $this->defaultRiskAppetiteTier;
    }

    public function setDefaultRiskAppetiteTier(int $defaultRiskAppetiteTier): static
    {
        $this->defaultRiskAppetiteTier = $defaultRiskAppetiteTier;
        return $this;
    }

    public function getDefaultDataClassificationLevels(): int
    {
        return $this->defaultDataClassificationLevels;
    }

    public function setDefaultDataClassificationLevels(int $defaultDataClassificationLevels): static
    {
        $this->defaultDataClassificationLevels = $defaultDataClassificationLevels;
        return $this;
    }

    public function getDefaultBackupRpoHours(): int
    {
        return $this->defaultBackupRpoHours;
    }

    public function setDefaultBackupRpoHours(int $defaultBackupRpoHours): static
    {
        $this->defaultBackupRpoHours = $defaultBackupRpoHours;
        return $this;
    }

    public function getDefaultPatchSlaCriticalHours(): int
    {
        return $this->defaultPatchSlaCriticalHours;
    }

    public function setDefaultPatchSlaCriticalHours(int $defaultPatchSlaCriticalHours): static
    {
        $this->defaultPatchSlaCriticalHours = $defaultPatchSlaCriticalHours;
        return $this;
    }

    /** @return array<string, string> */
    public function getAnnexAApplicabilityOverrides(): array
    {
        return $this->annexAApplicabilityOverrides;
    }

    /** @param array<string, string> $annexAApplicabilityOverrides */
    public function setAnnexAApplicabilityOverrides(array $annexAApplicabilityOverrides): static
    {
        $this->annexAApplicabilityOverrides = $annexAApplicabilityOverrides;
        return $this;
    }

    /** @return array<string, list<string>> */
    public function getTopicAudienceOverrides(): array
    {
        return $this->topicAudienceOverrides;
    }

    /** @param array<string, list<string>> $topicAudienceOverrides */
    public function setTopicAudienceOverrides(array $topicAudienceOverrides): static
    {
        $this->topicAudienceOverrides = $topicAudienceOverrides;
        return $this;
    }

    public function isDpoSectionsAutoEnabled(): bool
    {
        return $this->dpoSectionsAutoEnabled;
    }

    public function setDpoSectionsAutoEnabled(bool $dpoSectionsAutoEnabled): static
    {
        $this->dpoSectionsAutoEnabled = $dpoSectionsAutoEnabled;
        return $this;
    }

    /** @return list<string> */
    public function getRegulatoryReferences(): array
    {
        return $this->regulatoryReferences;
    }

    /** @param list<string> $regulatoryReferences */
    public function setRegulatoryReferences(array $regulatoryReferences): static
    {
        $this->regulatoryReferences = array_values($regulatoryReferences);
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

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): static
    {
        $this->version = $version;
        return $this;
    }
}
