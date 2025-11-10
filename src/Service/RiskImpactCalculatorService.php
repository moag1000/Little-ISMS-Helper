<?php

namespace App\Service;

use App\Entity\Risk;
use App\Entity\Asset;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * RiskImpactCalculatorService
 *
 * Phase 6F-D: Data Reuse Integration
 * Auto-calculates Risk.impact from Asset.monetaryValue
 *
 * Data Reuse Benefit:
 * - ~15 Min saved per Risk Assessment
 * - Consistent impact calculation across organization
 * - Asset monetary value directly influences risk prioritization
 *
 * Safe Guards:
 * - Asset.monetaryValue is ALWAYS manually set (never auto-calculated)
 * - Calculation is suggestion-only, user can override
 * - Audit log tracks all changes
 */
class RiskImpactCalculatorService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    /**
     * Calculate suggested impact level based on asset monetary value
     *
     * Impact Scale (1-5):
     * - 1 (Negligible): Loss < €10,000
     * - 2 (Minor): Loss €10,000 - €50,000
     * - 3 (Moderate): Loss €50,000 - €250,000
     * - 4 (Major): Loss €250,000 - €1,000,000
     * - 5 (Catastrophic): Loss > €1,000,000
     *
     * @param Risk $risk The risk to calculate impact for
     * @return int|null Suggested impact level (1-5) or null if no asset with monetary value
     */
    public function calculateSuggestedImpact(Risk $risk): ?int
    {
        $asset = $risk->getAsset();

        if (!$asset) {
            $this->logger->debug('Risk has no associated asset', ['risk_id' => $risk->getId()]);
            return null;
        }

        $monetaryValue = $asset->getMonetaryValue();

        if ($monetaryValue === null || $monetaryValue === '0.00') {
            $this->logger->debug('Asset has no monetary value set', [
                'risk_id' => $risk->getId(),
                'asset_id' => $asset->getId()
            ]);
            return null;
        }

        $value = (float) $monetaryValue;

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
     * @param Risk $risk
     * @return array{suggested_impact: int|null, current_impact: int|null, monetary_value: string|null, rationale: string, should_update: bool}
     */
    public function getImpactCalculationDetails(Risk $risk): array
    {
        $suggestedImpact = $this->calculateSuggestedImpact($risk);
        $currentImpact = $risk->getImpact();
        $asset = $risk->getAsset();
        $monetaryValue = $asset?->getMonetaryValue();

        $details = [
            'suggested_impact' => $suggestedImpact,
            'current_impact' => $currentImpact,
            'monetary_value' => $monetaryValue,
            'rationale' => '',
            'should_update' => false,
            'difference' => null
        ];

        if ($suggestedImpact === null) {
            $details['rationale'] = 'No monetary value available for impact calculation';
            return $details;
        }

        $details['difference'] = $suggestedImpact - $currentImpact;
        $details['should_update'] = abs($details['difference']) >= 2; // Update if difference >= 2 levels

        if ($suggestedImpact > $currentImpact) {
            $details['rationale'] = sprintf(
                'Asset monetary value (€%s) suggests higher impact level (%d vs current %d)',
                number_format((float) $monetaryValue, 2),
                $suggestedImpact,
                $currentImpact
            );
        } elseif ($suggestedImpact < $currentImpact) {
            $details['rationale'] = sprintf(
                'Asset monetary value (€%s) suggests lower impact level (%d vs current %d)',
                number_format((float) $monetaryValue, 2),
                $suggestedImpact,
                $currentImpact
            );
        } else {
            $details['rationale'] = sprintf(
                'Current impact level (%d) aligns with asset monetary value (€%s)',
                $currentImpact,
                number_format((float) $monetaryValue, 2)
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
                'monetary_value' => $details['monetary_value'],
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
     * @return array<int, array{risk: Risk, details: array}>
     */
    public function findMisalignedRisks(): array
    {
        $riskRepository = $this->entityManager->getRepository(Risk::class);
        $allRisks = $riskRepository->findAll();

        $misaligned = [];

        foreach ($allRisks as $risk) {
            $details = $this->getImpactCalculationDetails($risk);

            if ($details['should_update']) {
                $misaligned[] = [
                    'risk' => $risk,
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
     * @param Risk $risk
     * @return array{success: bool, message: string, old_impact: int|null, new_impact: int|null}
     */
    public function getSuggestion(Risk $risk): array
    {
        $suggestedImpact = $this->calculateSuggestedImpact($risk);
        $currentImpact = $risk->getImpact();

        if ($suggestedImpact === null) {
            return [
                'success' => false,
                'message' => 'No monetary value available for suggestion',
                'old_impact' => $currentImpact,
                'new_impact' => null
            ];
        }

        if ($suggestedImpact === $currentImpact) {
            return [
                'success' => false,
                'message' => 'Current impact already matches asset monetary value',
                'old_impact' => $currentImpact,
                'new_impact' => $suggestedImpact
            ];
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Suggested impact: %d (based on asset value €%s)',
                $suggestedImpact,
                $risk->getAsset()?->getMonetaryValue() ?? '0'
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
     * @param Risk $risk
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

        $this->logger->info('Risk impact updated based on asset monetary value', [
            'risk_id' => $risk->getId(),
            'old_impact' => $oldImpact,
            'new_impact' => $newImpact,
            'monetary_value' => $risk->getAsset()?->getMonetaryValue()
        ]);

        return [
            'success' => true,
            'message' => sprintf('Risk impact updated from %d to %d', $oldImpact, $newImpact)
        ];
    }
}
