<?php

declare(strict_types=1);

namespace App\Service\Compliance\Fulfillment;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Control;
use App\Entity\Tenant;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ControlRepository;
use App\Service\Tisax\TisaxFulfillmentSync;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The one rule that turns the organisation's data into a requirement's
 * fulfilment — for every framework, without framework-specific code.
 *
 * Order (the first rule that has data wins):
 *  1. maturity  — the organisation's own imported Reifegrad rows (TISAX/VDA-ISA).
 *  2. controls  — the organisation's controls behind the requirement: the Annex-A
 *                 control of the same id (ISO 27001), the catalogue's
 *                 `iso_controls` list and explicit control mappings. Controls the
 *                 SoA excludes drop out; the Annex-A requirement takes its
 *                 applicability from its control (one SoA truth).
 *  3. records   — the organisation's records behind a clause or article, through
 *                 the compliance wizard's data checks ({@see RecordCheckRule}):
 *                 ISO 27001 7.2 from training records, 9.3 from management
 *                 reviews, DORA Art. 28 from the supplier register, …
 *  4. mapping   — approved cross-framework mappings to requirements the
 *                 organisation already fulfils (effective value × mapping %),
 *                 the best one counts. This is inheritance, live.
 * No rule → null: the requirement is assessed by hand.
 *
 * Tenant-safe: requirements and mappings are global, controls and fulfilments
 * are read for the given organisation only (mappedControls of a global
 * requirement may hold every organisation's controls).
 */
class FulfillmentRuleEngine implements ResetInterface
{
    public const KIND_CONTROLS = 'controls';
    public const KIND_MAPPING = 'mapping';
    public const KIND_MATURITY = 'maturity';
    public const KIND_RECORDS = 'records';

    private const ISO27001 = 'ISO27001';
    private const MAX_MAPPING_DEPTH = 2;

    /** @var array<int, array<string, Control>> tenant id → normalised control id → control */
    private array $controlsByTenant = [];

    public function __construct(
        private readonly ControlRepository $controlRepository,
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly ?RecordCheckRule $recordRule = null,
    ) {
    }

    public function derive(ComplianceRequirement $requirement, Tenant $tenant): ?FulfillmentDerivation
    {
        return $this->deriveAt($requirement, $tenant, 0, []);
    }

    /**
     * The organisation's controls behind a requirement (deduplicated).
     *
     * @return list<Control>
     */
    public function controlsFor(ComplianceRequirement $requirement, Tenant $tenant): array
    {
        $byKey = $this->tenantControls($tenant);
        $found = [];

        $identity = $this->identityControl($requirement, $tenant);
        if ($identity instanceof Control) {
            $found[(int) $identity->getId()] = $identity;
        }

        foreach ($this->catalogueControlIds($requirement) as $controlId) {
            $control = $byKey[self::normalise($controlId)] ?? null;
            if ($control instanceof Control) {
                $found[(int) $control->getId()] = $control;
            }
        }

        $tenantId = $tenant->getId();
        foreach ($requirement->getMappedControls() as $control) {
            if ($control->getTenant()?->getId() === $tenantId) {
                $found[(int) $control->getId()] = $control;
            }
        }

        return array_values($found);
    }

    /**
     * The Annex-A control an ISO 27001 Annex-A requirement IS (same id) — the
     * requirement then has no applicability of its own, the SoA decides.
     */
    public function identityControl(ComplianceRequirement $requirement, Tenant $tenant): ?Control
    {
        if (!self::isAnnexARequirement($requirement)) {
            return null;
        }

        return $this->tenantControls($tenant)[self::normalise((string) $requirement->getRequirementId())] ?? null;
    }

    public static function isAnnexARequirement(ComplianceRequirement $requirement): bool
    {
        return $requirement->getFramework()?->getCode() === self::ISO27001
            && preg_match('/^A\.\d+\.\d+$/', (string) $requirement->getRequirementId()) === 1;
    }

    /** "A.5.1", "5.1", " 5.1 " → "5.1" — the key the catalogues and the SoA share. */
    public static function normalise(string $controlId): string
    {
        $id = trim($controlId);

        return str_starts_with($id, 'A.') ? substr($id, 2) : $id;
    }

    /**
     * Contribution of one control: implemented/verified count fully, partial
     * work counts with its percentage (50 when none is given), the rest zero.
     */
    public static function controlValue(Control $control): int
    {
        $status = (string) $control->getImplementationStatus();
        if ($status === 'implemented' || $status === 'verified') {
            return 100;
        }
        $pct = max(0, min(100, (int) ($control->getImplementationPercentage() ?? 0)));
        if ($pct > 0) {
            return min(99, $pct);
        }

        return $status === 'in_progress' ? 50 : 0;
    }

    public function reset(): void
    {
        $this->controlsByTenant = [];
    }

    /** Drops the cached control map and record results, e.g. after controls were created in this request. */
    public function forgetTenant(Tenant $tenant): void
    {
        unset($this->controlsByTenant[(int) $tenant->getId()]);
        $this->recordRule?->forgetTenant($tenant);
    }

    /**
     * @param array<int, true> $visiting requirement ids on the current mapping path
     */
    private function deriveAt(ComplianceRequirement $requirement, Tenant $tenant, int $depth, array $visiting): ?FulfillmentDerivation
    {
        $maturity = $this->fromMaturity($requirement, $tenant);
        if ($maturity instanceof FulfillmentDerivation) {
            return $maturity;
        }

        $controls = $this->controlsFor($requirement, $tenant);
        $excludedOnly = null;
        if ($controls !== []) {
            $fromControls = $this->fromControls($requirement, $tenant, $controls);
            if ($fromControls->percentage !== null) {
                return $fromControls;
            }
            $excludedOnly = $fromControls;
        }

        $fromRecords = $excludedOnly?->applicable === false ? null : $this->recordRule?->derive($requirement, $tenant);
        if ($fromRecords instanceof FulfillmentDerivation) {
            return $fromRecords;
        }

        $visiting[(int) $requirement->getId()] = true;
        $fromMapping = $depth < self::MAX_MAPPING_DEPTH
            ? $this->fromMappings($requirement, $tenant, $depth, $visiting)
            : null;

        if ($fromMapping instanceof FulfillmentDerivation) {
            return $excludedOnly?->applicable === false
                ? $excludedOnly // the SoA excluded the control itself: not applicable beats inheritance
                : $fromMapping;
        }

        return $excludedOnly;
    }

    private function fromMaturity(ComplianceRequirement $requirement, Tenant $tenant): ?FulfillmentDerivation
    {
        if ($requirement->getUploadTenant()?->getId() !== $tenant->getId()) {
            return null; // a Reifegrad is the organisation's own import, never a global row
        }
        $result = TisaxFulfillmentSync::derive($requirement);
        if ($result === null) {
            return null;
        }

        return new FulfillmentDerivation($result['percentage'], self::KIND_MATURITY, [[
            'type' => 'maturity',
            'level' => $result['level'],
            'value' => $result['percentage'],
        ]], true);
    }

    /**
     * @param list<Control> $controls
     */
    private function fromControls(ComplianceRequirement $requirement, Tenant $tenant, array $controls): FulfillmentDerivation
    {
        $basis = [];
        $sum = 0;
        $counted = 0;
        foreach ($controls as $control) {
            $applicable = $control->isApplicable() !== false;
            $value = $applicable ? self::controlValue($control) : null;
            $basis[] = [
                'type' => 'control',
                'id' => $control->getId(),
                'ref' => $control->getControlId(),
                'label' => $control->getName(),
                'status' => $control->getImplementationStatus(),
                'applicable' => $applicable,
                'value' => $value,
            ];
            if ($value !== null) {
                $sum += $value;
                $counted++;
            }
        }

        $identity = $this->identityControl($requirement, $tenant);
        $applicable = $identity instanceof Control ? $identity->isApplicable() !== false : null;
        $justification = $identity instanceof Control ? $identity->getJustification() : null;

        if ($applicable === false) {
            return new FulfillmentDerivation(null, self::KIND_CONTROLS, $basis, false, $justification);
        }
        if ($counted === 0) {
            return new FulfillmentDerivation(null, self::KIND_CONTROLS, $basis, $applicable, $justification);
        }

        return new FulfillmentDerivation(
            (int) round($sum / $counted),
            self::KIND_CONTROLS,
            $basis,
            $applicable,
            $justification,
        );
    }

    /**
     * @param array<int, true> $visiting
     */
    private function fromMappings(ComplianceRequirement $requirement, Tenant $tenant, int $depth, array $visiting): ?FulfillmentDerivation
    {
        $best = null;
        $basis = [];
        foreach ($this->sourcesOf($requirement, $tenant) as [$source, $mapping]) {
            if (isset($visiting[(int) $source->getId()])) {
                continue;
            }
            $sourceValue = $this->effectiveValue($source, $tenant, $depth, $visiting);
            if ($sourceValue === null || $sourceValue <= 0) {
                continue;
            }
            $mappingPct = max(0, min(100, $mapping->getMappingPercentage()));
            $value = (int) round($sourceValue * $mappingPct / 100);
            if ($value <= 0) {
                continue;
            }
            $basis[] = [
                'type' => 'mapping',
                'id' => $source->getId(),
                'framework' => $source->getFramework()?->getCode(),
                'ref' => $source->getRequirementId(),
                'label' => $source->getTitle(),
                'source_value' => $sourceValue,
                'mapping_percentage' => $mappingPct,
                'value' => $value,
            ];
            $best = max($best ?? 0, $value);
        }

        if ($best === null) {
            return null;
        }
        usort($basis, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return new FulfillmentDerivation($best, self::KIND_MAPPING, $basis);
    }

    /**
     * Operational mappings that let another requirement stand in for this one:
     * inbound ones, and bidirectional ones in the other direction.
     *
     * @return list<array{0: ComplianceRequirement, 1: ComplianceMapping}>
     */
    private function sourcesOf(ComplianceRequirement $requirement, Tenant $tenant): array
    {
        $sources = [];
        foreach ($this->mappingRepository->findMappingsToRequirement($requirement) as $mapping) {
            $source = $mapping->getSourceRequirement();
            if ($source instanceof ComplianceRequirement && $this->visibleTo($mapping, $tenant)) {
                $sources[] = [$source, $mapping];
            }
        }
        foreach ($this->mappingRepository->findMappingsFromRequirement($requirement) as $mapping) {
            $target = $mapping->getTargetRequirement();
            if ($mapping->isBidirectional() && $target instanceof ComplianceRequirement && $this->visibleTo($mapping, $tenant)) {
                $sources[] = [$target, $mapping];
            }
        }

        return $sources;
    }

    /** A mapping owned by another organisation (tenant-uploaded requirement) never counts. */
    private function visibleTo(ComplianceMapping $mapping, Tenant $tenant): bool
    {
        $owner = $mapping->getOwnerTenant();

        return $owner === null || $owner->getId() === $tenant->getId();
    }

    /**
     * @param array<int, true> $visiting
     */
    private function effectiveValue(ComplianceRequirement $source, Tenant $tenant, int $depth, array $visiting): ?int
    {
        $fulfillment = $this->fulfillmentRepository->findOneBy(['tenant' => $tenant, 'requirement' => $source]);
        if ($fulfillment instanceof ComplianceRequirementFulfillment) {
            return $fulfillment->isApplicable() ? $fulfillment->getFulfillmentPercentage() : null;
        }

        return $this->deriveAt($source, $tenant, $depth + 1, $visiting)?->percentage;
    }

    /**
     * @return list<string>
     */
    private function catalogueControlIds(ComplianceRequirement $requirement): array
    {
        $ids = $requirement->getDataSourceMapping()['iso_controls'] ?? [];
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        return array_values(array_filter(array_map('strval', $ids), static fn (string $id): bool => trim($id) !== ''));
    }

    /**
     * @return array<string, Control>
     */
    private function tenantControls(Tenant $tenant): array
    {
        $tenantId = (int) $tenant->getId();
        if (!isset($this->controlsByTenant[$tenantId])) {
            $map = [];
            foreach ($this->controlRepository->findByTenant($tenant) as $control) {
                $map[self::normalise((string) $control->getControlId())] ??= $control;
            }
            $this->controlsByTenant[$tenantId] = $map;
        }

        return $this->controlsByTenant[$tenantId];
    }
}
