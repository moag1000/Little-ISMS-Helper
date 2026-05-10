<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TenantBrandingRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-tenant branding for PDF export of generated policy documents
 * (Sprint W7). Carries letterhead HTML, logo path, primary/secondary
 * colors, and font family. 1:1 with Tenant.
 */
#[ORM\Entity(repositoryClass: TenantBrandingRepository::class)]
#[ORM\Table(name: 'tenant_branding')]
#[ORM\UniqueConstraint(name: 'uq_tenant_branding_tenant', columns: ['tenant_id'])]
class TenantBranding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $headerHtml = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $footerHtml = null;

    #[ORM\Column(length: 16, options: ['default' => '#0d6efd'])]
    private string $primaryColor = '#0d6efd';

    #[ORM\Column(length: 16, options: ['default' => '#6c757d'])]
    private string $secondaryColor = '#6c757d';

    #[ORM\Column(length: 64, options: ['default' => 'Inter'])]
    private string $fontFamily = 'Inter';

    // ------------------------------------------------------------------
    // Policy-Doc Style Configuration (Sprint policy-style-admin)
    //
    // Per-tenant overrides for the `_fa_policy_doc.html.twig` macro that
    // renders generated policy documents (cover / TOC / history / body /
    // signature). Defaults match the design-system reference; tenants
    // can re-skin without touching code via /admin/policy-style.
    // ------------------------------------------------------------------

    #[ORM\Column(length: 64, options: ['default' => 'Inter'])]
    private string $policyDocFontFamily = 'Inter';

    /** "minimal" | "branded" | "auditor-formal" | "engineering" */
    #[ORM\Column(length: 32, options: ['default' => 'branded'])]
    private string $policyDocCoverPattern = 'branded';

    #[ORM\Column(options: ['default' => true])]
    private bool $policyDocWatermarkEnabled = true;

    /** 0.0 – 1.0 (UI exposes 0–100 %). */
    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0.08])]
    private float $policyDocWatermarkOpacity = 0.08;

    /** 1 – 6 signature blocks on the signature page. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 3])]
    private int $policyDocSignatureLines = 3;

    #[ORM\Column(options: ['default' => true])]
    private bool $policyDocShowToc = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $policyDocShowHistory = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $policyDocShowAnnexARefs = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $policyDocFooterText = null;

    /** "small" | "medium" | "large" */
    #[ORM\Column(length: 16, options: ['default' => 'medium'])]
    private string $policyDocCoverLogoSize = 'medium';

    /** "compact" | "standard" | "wide" */
    #[ORM\Column(length: 16, options: ['default' => 'standard'])]
    private string $policyDocPageMargin = 'standard';

    /**
     * Optional advanced CSS override (paste-only; rendered via
     * `|raw` inside the policy-doc <style> block). Restricted to
     * ROLE_ADMIN at the form layer.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $policyDocCustomCss = null;
    // Report-Doc Style Configuration (Sprint report-style-admin)
    //
    // Per-tenant overrides for the `_fa_report_doc.html.twig` macro
    // (sister-agent W7-parallel) that renders generated reports using
    // a 5-slot template (cover / exec-summary / data / appendix /
    // footer) with audience-aware layouts (Vorstand / Auditor /
    // Aufsicht / internal). Defaults match the design-system reference;
    // tenants can re-skin without touching code via /admin/report-style.
    //
    // Independent from the policyDoc* fields (sister-agent
    // policy-style-admin) so the two configurators don't clash.
    // ------------------------------------------------------------------

    /** "minimal" | "branded" | "board-formal" | "auditor-formal" */
    #[ORM\Column(length: 32, options: ['default' => 'branded'])]
    private string $reportDocCoverPattern = 'branded';

    /** "vorstand" | "auditor" | "aufsicht" | "internal" */
    #[ORM\Column(length: 16, options: ['default' => 'internal'])]
    private string $reportDocDefaultAudience = 'internal';

    #[ORM\Column(options: ['default' => true])]
    private bool $reportDocWatermarkEnabled = true;

    /** 0.0 – 1.0 (UI exposes 0–100 %). */
    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0.08])]
    private float $reportDocWatermarkOpacity = 0.08;

    #[ORM\Column(options: ['default' => true])]
    private bool $reportDocShowExecSummary = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $reportDocShowAppendix = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $reportDocShowDistributionList = true;

    #[ORM\Column(length: 64, options: ['default' => 'Inter'])]
    private string $reportDocFontFamily = 'Inter';

    /** "portrait" | "landscape" | "auto" */
    #[ORM\Column(length: 16, options: ['default' => 'auto'])]
    private string $reportDocPageOrientation = 'auto';

    /** "aurora" | "audit" | "print-friendly" | "colorblind-safe" */
    #[ORM\Column(length: 32, options: ['default' => 'aurora'])]
    private string $reportDocChartColorScheme = 'aurora';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reportDocFooterDisclaimer = null;

    /**
     * Optional advanced CSS override (paste-only; rendered via `|raw`
     * inside the report-doc <style> block). Restricted to ROLE_ADMIN
     * at the form layer.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reportDocCustomCss = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $updatedByUser = null;

    public function __construct()
    {
        $this->updatedAt = new DateTimeImmutable();
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

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): static
    {
        $this->logoPath = $logoPath;
        return $this;
    }

    public function getHeaderHtml(): ?string
    {
        return $this->headerHtml;
    }

    public function setHeaderHtml(?string $headerHtml): static
    {
        $this->headerHtml = $headerHtml;
        return $this;
    }

    public function getFooterHtml(): ?string
    {
        return $this->footerHtml;
    }

    public function setFooterHtml(?string $footerHtml): static
    {
        $this->footerHtml = $footerHtml;
        return $this;
    }

    public function getPrimaryColor(): string
    {
        return $this->primaryColor;
    }

    public function setPrimaryColor(string $primaryColor): static
    {
        $this->primaryColor = $primaryColor;
        return $this;
    }

    public function getSecondaryColor(): string
    {
        return $this->secondaryColor;
    }

    public function setSecondaryColor(string $secondaryColor): static
    {
        $this->secondaryColor = $secondaryColor;
        return $this;
    }

    public function getFontFamily(): string
    {
        return $this->fontFamily;
    }

    public function setFontFamily(string $fontFamily): static
    {
        $this->fontFamily = $fontFamily;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getUpdatedByUser(): ?User
    {
        return $this->updatedByUser;
    }

    public function setUpdatedByUser(?User $updatedByUser): static
    {
        $this->updatedByUser = $updatedByUser;
        return $this;
    }

    // ------------------------------------------------------------------
    // Policy-Doc style accessors (Sprint policy-style-admin)
    // ------------------------------------------------------------------

    public const POLICY_DOC_COVER_PATTERNS = ['minimal', 'branded', 'auditor-formal', 'engineering'];
    public const POLICY_DOC_LOGO_SIZES = ['small', 'medium', 'large'];
    public const POLICY_DOC_PAGE_MARGINS = ['compact', 'standard', 'wide'];

    public function getPolicyDocFontFamily(): string
    {
        return $this->policyDocFontFamily;
    }

    public function setPolicyDocFontFamily(string $value): static
    {
        $this->policyDocFontFamily = $value;
        return $this;
    }

    public function getPolicyDocCoverPattern(): string
    {
        return $this->policyDocCoverPattern;
    }

    public function setPolicyDocCoverPattern(string $value): static
    {
        if (!in_array($value, self::POLICY_DOC_COVER_PATTERNS, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid cover pattern "%s".', $value));
        }
        $this->policyDocCoverPattern = $value;
        return $this;
    }

    public function isPolicyDocWatermarkEnabled(): bool
    {
        return $this->policyDocWatermarkEnabled;
    }

    public function setPolicyDocWatermarkEnabled(bool $value): static
    {
        $this->policyDocWatermarkEnabled = $value;
        return $this;
    }

    public function getPolicyDocWatermarkOpacity(): float
    {
        return $this->policyDocWatermarkOpacity;
    }

    /**
     * Setter accepts any float and clamps to the [0.0, 1.0] band so
     * that the Form Range constraint and the underlying property setter
     * agree on the validation contract (Symfony forms bind via the
     * setter before validators run; throwing here would short-circuit
     * the validator and surface as an opaque 500). Direct API callers
     * who want strict validation should pre-check via the entity-level
     * `Assert\Range` annotation or this constant range.
     */
    public function setPolicyDocWatermarkOpacity(float $value): static
    {
        $this->policyDocWatermarkOpacity = max(0.0, min(1.0, $value));
        return $this;
    }

    public function getPolicyDocSignatureLines(): int
    {
        return $this->policyDocSignatureLines;
    }

    /**
     * Setter clamps to [1, 6] for the same reason as the watermark
     * opacity setter (form-binding compatibility; see comment above).
     */
    public function setPolicyDocSignatureLines(int $value): static
    {
        $this->policyDocSignatureLines = max(1, min(6, $value));
        return $this;
    }

    public function isPolicyDocShowToc(): bool
    {
        return $this->policyDocShowToc;
    }

    public function setPolicyDocShowToc(bool $value): static
    {
        $this->policyDocShowToc = $value;
        return $this;
    }

    public function isPolicyDocShowHistory(): bool
    {
        return $this->policyDocShowHistory;
    }

    public function setPolicyDocShowHistory(bool $value): static
    {
        $this->policyDocShowHistory = $value;
        return $this;
    }

    public function isPolicyDocShowAnnexARefs(): bool
    {
        return $this->policyDocShowAnnexARefs;
    }

    public function setPolicyDocShowAnnexARefs(bool $value): static
    {
        $this->policyDocShowAnnexARefs = $value;
        return $this;
    }

    public function getPolicyDocFooterText(): ?string
    {
        return $this->policyDocFooterText;
    }

    public function setPolicyDocFooterText(?string $value): static
    {
        $this->policyDocFooterText = $value;
        return $this;
    }

    public function getPolicyDocCoverLogoSize(): string
    {
        return $this->policyDocCoverLogoSize;
    }

    public function setPolicyDocCoverLogoSize(string $value): static
    {
        if (!in_array($value, self::POLICY_DOC_LOGO_SIZES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid logo size "%s".', $value));
        }
        $this->policyDocCoverLogoSize = $value;
        return $this;
    }

    public function getPolicyDocPageMargin(): string
    {
        return $this->policyDocPageMargin;
    }

    public function setPolicyDocPageMargin(string $value): static
    {
        if (!in_array($value, self::POLICY_DOC_PAGE_MARGINS, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid page margin "%s".', $value));
        }
        $this->policyDocPageMargin = $value;
        return $this;
    }

    public function getPolicyDocCustomCss(): ?string
    {
        return $this->policyDocCustomCss;
    }

    public function setPolicyDocCustomCss(?string $value): static
    {
        $this->policyDocCustomCss = $value;
        return $this;
    }

    // ------------------------------------------------------------------
    // Report-Doc style accessors (Sprint report-style-admin)
    // ------------------------------------------------------------------

    public const REPORT_DOC_COVER_PATTERNS = ['minimal', 'branded', 'board-formal', 'auditor-formal'];
    public const REPORT_DOC_AUDIENCES = ['vorstand', 'auditor', 'aufsicht', 'internal'];
    public const REPORT_DOC_PAGE_ORIENTATIONS = ['portrait', 'landscape', 'auto'];
    public const REPORT_DOC_CHART_COLOR_SCHEMES = ['aurora', 'audit', 'print-friendly', 'colorblind-safe'];

    public function getReportDocCoverPattern(): string
    {
        return $this->reportDocCoverPattern;
    }

    public function setReportDocCoverPattern(string $value): static
    {
        if (!in_array($value, self::REPORT_DOC_COVER_PATTERNS, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid report cover pattern "%s".', $value));
        }
        $this->reportDocCoverPattern = $value;
        return $this;
    }

    public function getReportDocDefaultAudience(): string
    {
        return $this->reportDocDefaultAudience;
    }

    public function setReportDocDefaultAudience(string $value): static
    {
        if (!in_array($value, self::REPORT_DOC_AUDIENCES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid report audience "%s".', $value));
        }
        $this->reportDocDefaultAudience = $value;
        return $this;
    }

    public function isReportDocWatermarkEnabled(): bool
    {
        return $this->reportDocWatermarkEnabled;
    }

    public function setReportDocWatermarkEnabled(bool $value): static
    {
        $this->reportDocWatermarkEnabled = $value;
        return $this;
    }

    public function getReportDocWatermarkOpacity(): float
    {
        return $this->reportDocWatermarkOpacity;
    }

    /**
     * Setter clamps to [0.0, 1.0] to keep the form-binding contract
     * compatible with the Range constraint (Symfony forms bind via the
     * setter before validators run).
     */
    public function setReportDocWatermarkOpacity(float $value): static
    {
        $this->reportDocWatermarkOpacity = max(0.0, min(1.0, $value));
        return $this;
    }

    public function isReportDocShowExecSummary(): bool
    {
        return $this->reportDocShowExecSummary;
    }

    public function setReportDocShowExecSummary(bool $value): static
    {
        $this->reportDocShowExecSummary = $value;
        return $this;
    }

    public function isReportDocShowAppendix(): bool
    {
        return $this->reportDocShowAppendix;
    }

    public function setReportDocShowAppendix(bool $value): static
    {
        $this->reportDocShowAppendix = $value;
        return $this;
    }

    public function isReportDocShowDistributionList(): bool
    {
        return $this->reportDocShowDistributionList;
    }

    public function setReportDocShowDistributionList(bool $value): static
    {
        $this->reportDocShowDistributionList = $value;
        return $this;
    }

    public function getReportDocFontFamily(): string
    {
        return $this->reportDocFontFamily;
    }

    public function setReportDocFontFamily(string $value): static
    {
        $this->reportDocFontFamily = $value;
        return $this;
    }

    public function getReportDocPageOrientation(): string
    {
        return $this->reportDocPageOrientation;
    }

    public function setReportDocPageOrientation(string $value): static
    {
        if (!in_array($value, self::REPORT_DOC_PAGE_ORIENTATIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid page orientation "%s".', $value));
        }
        $this->reportDocPageOrientation = $value;
        return $this;
    }

    public function getReportDocChartColorScheme(): string
    {
        return $this->reportDocChartColorScheme;
    }

    public function setReportDocChartColorScheme(string $value): static
    {
        if (!in_array($value, self::REPORT_DOC_CHART_COLOR_SCHEMES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid chart color scheme "%s".', $value));
        }
        $this->reportDocChartColorScheme = $value;
        return $this;
    }

    public function getReportDocFooterDisclaimer(): ?string
    {
        return $this->reportDocFooterDisclaimer;
    }

    public function setReportDocFooterDisclaimer(?string $value): static
    {
        $this->reportDocFooterDisclaimer = $value;
        return $this;
    }

    public function getReportDocCustomCss(): ?string
    {
        return $this->reportDocCustomCss;
    }

    public function setReportDocCustomCss(?string $value): static
    {
        $this->reportDocCustomCss = $value;
        return $this;
    }

    /**
     * Snapshot of all policy-doc style fields, ready to pass as
     * `style_config` to the `_fa_policy_doc` macro. Keys are the
     * canonical macro-side property names (snake_case).
     *
     * @return array<string, mixed>
     */
    public function getPolicyDocStyleConfig(): array
    {
        return [
            'font_family' => $this->policyDocFontFamily,
            'cover_pattern' => $this->policyDocCoverPattern,
            'watermark_enabled' => $this->policyDocWatermarkEnabled,
            'watermark_opacity' => $this->policyDocWatermarkOpacity,
            'signature_lines' => $this->policyDocSignatureLines,
            'show_toc' => $this->policyDocShowToc,
            'show_history' => $this->policyDocShowHistory,
            'show_annex_a_refs' => $this->policyDocShowAnnexARefs,
            'footer_text' => $this->policyDocFooterText,
            'cover_logo_size' => $this->policyDocCoverLogoSize,
            'page_margin' => $this->policyDocPageMargin,
            'custom_css' => $this->policyDocCustomCss,
            // Shared branding so the macro does not need to query the entity twice.
            'primary_color' => $this->primaryColor,
            'secondary_color' => $this->secondaryColor,
            'logo_path' => $this->logoPath,
        ];
    }

    /**
     * Snapshot of all report-doc style fields, ready to pass as
     * `style_config` to the `_fa_report_doc` macro. Keys are the
     * canonical macro-side property names (snake_case).
     *
     * Includes shared branding fields (primary/secondary colors,
     * logo path) so the macro doesn't need to query the entity twice.
     *
     * @return array<string, mixed>
     */
    public function getReportDocStyleConfig(): array
    {
        return [
            'cover_pattern' => $this->reportDocCoverPattern,
            'default_audience' => $this->reportDocDefaultAudience,
            'watermark_enabled' => $this->reportDocWatermarkEnabled,
            'watermark_opacity' => $this->reportDocWatermarkOpacity,
            'show_exec_summary' => $this->reportDocShowExecSummary,
            'show_appendix' => $this->reportDocShowAppendix,
            'show_distribution_list' => $this->reportDocShowDistributionList,
            'font_family' => $this->reportDocFontFamily,
            'page_orientation' => $this->reportDocPageOrientation,
            'chart_color_scheme' => $this->reportDocChartColorScheme,
            'footer_disclaimer' => $this->reportDocFooterDisclaimer,
            'custom_css' => $this->reportDocCustomCss,
            'primary_color' => $this->primaryColor,
            'secondary_color' => $this->secondaryColor,
            'logo_path' => $this->logoPath,
        ];
    }
}
