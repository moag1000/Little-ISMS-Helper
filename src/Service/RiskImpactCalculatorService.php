<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Risk;
use App\Entity\Asset;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * RiskImpactCalculatorService
 *
 * Phase 6F-D: Data Reuse Integration
 * Auto-calculates Risk.impact from Asset valuation fields (currentValue,
 * fall-back acquisitionValue).
 *
 * Data Reuse Benefit:
 * - ~15 Min saved per Risk Assessment
 * - Consistent impact calculation across organization
 * - Asset monetary value directly influences risk prioritization
 *
 * Safe Guards:
 * - Asset valuation fields are ALWAYS manually set (never auto-calculated)
 * - Calculation is suggestion-only, user can override
 * - Audit log tracks all changes
 *
 * Junior-ISB-Audit S14+ #15 (2026-05): switched from the deprecated
 * `Asset.monetaryValue` field to the canonical AssetType form fields
 * `currentValue` (preferred) / `acquisitionValue` (fallback). The
 * `monetary_value` column was dropped in
 * Version20260612100000_DropAssetMonetaryValue.
 */
final class RiskImpactCalculatorService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Resolve an asset's effective valuation for impact calculation.
     *
     * Preference order: currentValue → acquisitionValue.
     * Returns null when neither is set or both resolve to 0.
     */
    private function resolveAssetValue(?Asset $asset): ?string
    {
        if (!$asset instanceof Asset) {
            return null;
        }

        foreach ([$asset->getCurrentValue(), $asset->getAcquisitionValue()] as $candidate) {
            if ($candidate === null) {
                continue;
            }
            if ((float) $candidate <= 0.0) {
                continue;
            }
            return $candidate;
        }

        return null;
    }

    /**
     * Calculate suggested impact level based on asset valuation.
     *
     * Impact Scale (1-5):
     * - 1 (Negligible): Loss < €10,000
     * - 2 (Minor): Loss €10,000 - €50,000
     * - 3 (Moderate): Loss €50,000 - €250,000
     * - 4 (Major): Loss €250,000 - €1,000,000
     * - 5 (Catastrophic): Loss > €1,000,000
     *
     * @param Risk $risk The risk to calculate impact for
     * @return int|null Suggested impact level (1-5) or null if no asset valuation available
     */
    public function calculateSuggestedImpact(Risk $risk): ?int
    {
        $asset = $risk->getAsset();

        if (!$asset instanceof Asset) {
            $this->logger->debug('Risk has no associated asset', ['risk_id' => $risk->getId()]);
            return null;
        }

        $assetValue = $this->resolveAssetValue($asset);

        if ($assetValue === null) {
            $this->logger->debug('Asset has no monetary valuation set', [
                'risk_id' => $risk->getId(),
                'asset_id' => $asset->getId()
            ]);
            return null;
        }

        $value = (float) $assetValue;

        // Calculate impact based on monetary value thresholds
        if ($value >= 1000000) {
            return 5; // Catastrophic
        } elseif ($value >= 250000) {
            return 4; // Major
        } elseif ($value >= 50000) {
            return 3; // Moderate
        } elseif ($value >= 10000) {
            return 2; // Minor
        } else {
            return 1; // Negligible
        }
    }

    /**
     * Get detailed impact calculation breakdown
     *
     * @return array{suggested_impact: int|null, current_impact: int|null, asset_value: string|null, rationale: string, should_update: bool}
     */
    public function getImpactCalculationDetails(Risk $risk): array
    {
        $suggestedImpact = $this->calculateSuggestedImpact($risk);
        $currentImpact = $risk->getImpact();
        $asset = $risk->getAsset();
        $assetValue = $this->resolveAssetValue($asset);

        $details = [
            'suggested_impact' => $suggestedImpact,
            'current_impact' => $currentImpact,
            'asset_value' => $assetValue,
            'rationale' => '',
            'should_update' => false,
            'difference' => null
        ];

        if ($suggestedImpact === null) {
            $details['rationale'] = 'No asset valuation available for impact calculation';
            return $details;
        }

        $details['difference'] = $suggestedImpact - $currentImpact;
        $details['should_update'] = abs($details['difference']) >= 2; // Update if difference >= 2 levels

        if ($suggestedImpact > $currentImpact) {
            $details['rationale'] = sprintf(
                'Asset valuation (€%s) suggests higher impact level (%d vs current %d)',
                number_format((float) $assetValue, 2),
                $suggestedImpact,
                $currentImpact
            );
        } elseif ($suggestedImpact < $currentImpact) {
            $details['rationale'] = sprintf(
                'Asset valuation (€%s) suggests lower impact level (%d vs current %d)',
                number_format((float) $assetValue, 2),
                $suggestedImpact,
                $currentImpact
            );
        } else {
            $details['rationale'] = sprintf(
                'Current impact level (%d) aligns with asset valuation (€%s)',
                $currentImpact,
                number_format((float) $assetValue, 2)
            );
        }

        return $details;
    }

    /**
     * Batch calculate suggested impacts for multiple risks
     *
     * @param Risk[] $risks
     * @return array<int, array{risk_id: int, suggested_impact: int|null, current_impact: int|null, should_update: bool}>
     */
    public function batchCalculateImpacts(array $risks): array
    {
        $results = [];

        foreach ($risks as $risk) {
            $details = $this->getImpactCalculationDetails($risk);

            $results[] = [
                'risk_id' => $risk->getId(),
                'risk_title' => $risk->getTitle(),
                'suggested_impact' => $details['suggested_impact'],
                'current_impact' => $details['current_impact'],
                'asset_value' => $details['asset_value'],
                'should_update' => $details['should_update'],
                'difference' => $details['difference'],
                'rationale' => $details['rationale']
            ];
        }

        return $results;
    }

    /**
     * Get all risks with impact misalignment (suggested != current, difference >= 2)
     *
     * @param Risk[]|null $risks Pre-filtered risk list (e.g. tenant-scoped). If null, all risks are loaded.
     * @return array<int, array{risk: Risk, details: array}>
     */
    public function findMisalignedRisks(?array $risks = null): array
    {
        $entityRepository = $this->entityManager->getRepository(Risk::class);
        $allRisks = $risks ?? $entityRepository->findAll();

        $misaligned = [];

        foreach ($allRisks as $allRisk) {
            $details = $this->getImpactCalculationDetails($allRisk);

            if ($details['should_update']) {
                $misaligned[] = [
                    'risk' => $allRisk,
                    'details' => $details
                ];
            }
        }

        $this->logger->info('Found risks with impact misalignment', [
            'count' => count($misaligned),
            'total_risks' => count($allRisks)
        ]);

        return $misaligned;
    }

    /**
     * Apply suggested impact to a risk (suggestion-only, user must confirm)
     *
     * Safe Guard: This method only SUGGESTS, it does not auto-update
     * User must explicitly call updateRiskImpact() to apply
     *
     * @return array{success: bool, message: string, old_impact: int|null, new_impact: int|null}
     */
    public function getSuggestion(Risk $risk): array
    {
        $suggestedImpact = $this->calculateSuggestedImpact($risk);
        $currentImpact = $risk->getImpact();

        if ($suggestedImpact === null) {
            return [
                'success' => false,
                'message' => 'No asset valuation available for suggestion',
                'old_impact' => $currentImpact,
                'new_impact' => null
            ];
        }

        if ($suggestedImpact === $currentImpact) {
            return [
                'success' => false,
                'message' => 'Current impact already matches asset valuation',
                'old_impact' => $currentImpact,
                'new_impact' => $suggestedImpact
            ];
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Suggested impact: %d (based on asset value €%s)',
                $suggestedImpact,
                $this->resolveAssetValue($risk->getAsset()) ?? '0'
            ),
            'old_impact' => $currentImpact,
            'new_impact' => $suggestedImpact
        ];
    }

    /**
     * Update risk impact with user confirmation
     *
     * Safe Guard: Requires explicit user action, never auto-updates
     * Audit logging tracks who made the change
     *
     * @param int $newImpact The impact value to set (1-5)
     * @param bool $userConfirmed User must confirm the change
     * @return array{success: bool, message: string}
     */
    public function updateRiskImpact(Risk $risk, int $newImpact, bool $userConfirmed = false): array
    {
        if (!$userConfirmed) {
            return [
                'success' => false,
                'message' => 'User confirmation required for impact update'
            ];
        }

        if ($newImpact < 1 || $newImpact > 5) {
            return [
                'success' => false,
                'message' => 'Impact must be between 1 and 5'
            ];
        }

        $oldImpact = $risk->getImpact();
        $risk->setImpact($newImpact);

        // Recalculate residual impact proportionally
        if ($oldImpact !== 0) {
            $ratio = $newImpact / $oldImpact;
            $newResidualImpact = (int) round($risk->getResidualImpact() * $ratio);
            $risk->setResidualImpact(min(5, max(1, $newResidualImpact)));
        }

        $this->entityManager->persist($risk);
        $this->entityManager->flush();

        $this->logger->info('Risk impact updated based on asset valuation', [
            'risk_id' => $risk->getId(),
            'old_impact' => $oldImpact,
            'new_impact' => $newImpact,
            'asset_value' => $this->resolveAssetValue($risk->getAsset())
        ]);

        return [
            'success' => true,
            'message' => sprintf('Risk impact updated from %d to %d', $oldImpact, $newImpact)
        ];
    }
}
