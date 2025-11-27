<?php

namespace App\Service;

use DateTime;
use App\Entity\Risk;
use App\Entity\Incident;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * RiskProbabilityAdjustmentService
 *
 * Phase 6F-D: Data Reuse Integration
 * Adjusts risk probability based on historical incident data
 *
 * Data Reuse Benefit:
 * - ~30 Min saved per Risk Review
 * - Evidence-based risk assessment
 * - Automatic calibration based on real incidents
 *
 * CRITICAL SAFE GUARDS (Anti-Circular-Dependency):
 * 1. Temporal Decoupling: Only incidents >30 days old AND status=closed
 * 2. One-Way Adjustment: Only INCREASE probability, NEVER auto-decrease
 * 3. User Override: Users can always manually reduce probability
 * 4. Audit Logging: All probability changes are logged automatically
 * 5. Threshold: Only suggest adjustment if realization count >= 2
 *
 * Why Safe Guards?
 * - Prevents feedback loops (Incident → Risk → Incident)
 * - Ensures data stability (30-day cooling-off period)
 * - Maintains user control (no auto-decrease)
 * - Compliance with ISO 27005 (audit trail)
 */
class RiskProbabilityAdjustmentService
{
    private const int MINIMUM_AGE_DAYS = 30; // Only consider incidents older than 30 days
    private const int MINIMUM_REALIZATION_COUNT = 2; // Need at least 2 incidents to suggest adjustment

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Calculate suggested probability based on historical incidents
     *
     * Safe Guard: Only increases probability, never decreases
     *
     * @return int|null Suggested probability (1-5) or null if no adjustment needed
     */
    public function calculateSuggestedProbability(Risk $risk): ?int
    {
        $incidents = $this->getEligibleIncidents($risk);
        $currentProbability = $risk->getProbability();

        if (count($incidents) < self::MINIMUM_REALIZATION_COUNT) {
            // Not enough historical data
            return null;
        }

        // Count incidents in time buckets
        $timeFrames = $this->analyzeIncidentFrequency($incidents);

        $suggestedProbability = $this->mapFrequencyToProbability($timeFrames);

        // Safe Guard: Only suggest increase, never decrease
        if ($suggestedProbability <= $currentProbability) {
            return null; // No adjustment needed
        }

        $this->logger->info('Probability adjustment suggested', [
            'risk_id' => $risk->getId(),
            'current_probability' => $currentProbability,
            'suggested_probability' => $suggestedProbability,
            'incident_count' => count($incidents)
        ]);

        return $suggestedProbability;
    }

    /**
     * Get eligible incidents for probability adjustment
     *
     * Safe Guard: Only closed incidents older than 30 days
     *
     * @return Incident[]
     */
    private function getEligibleIncidents(Risk $risk): array
    {
        $cutoffDate = new DateTime(sprintf('-%d days', self::MINIMUM_AGE_DAYS));

        $eligibleIncidents = [];

        foreach ($risk->getIncidents() as $incident) {
            // Safe Guard 1: Must be closed
            if ($incident->getStatus() !== 'closed') {
                continue;
            }

            // Safe Guard 2: Must be older than 30 days
            if ($incident->getDetectedAt() > $cutoffDate) {
                continue;
            }

            $eligibleIncidents[] = $incident;
        }

        return $eligibleIncidents;
    }

    /**
     * Analyze incident frequency in different time frames
     *
     * @param Incident[] $incidents
     * @return array{last_year: int, last_6_months: int, last_3_months: int}
     */
    private function analyzeIncidentFrequency(array $incidents): array
    {
        new DateTime();
        $oneYearAgo = new DateTime('-1 year');
        $sixMonthsAgo = new DateTime('-6 months');
        $threeMonthsAgo = new DateTime('-3 months');

        $timeFrames = [
            'last_year' => 0,
            'last_6_months' => 0,
            'last_3_months' => 0
        ];

        foreach ($incidents as $incident) {
            $detectedAt = $incident->getDetectedAt();

            if ($detectedAt >= $oneYearAgo) {
                $timeFrames['last_year']++;
            }

            if ($detectedAt >= $sixMonthsAgo) {
                $timeFrames['last_6_months']++;
            }

            if ($detectedAt >= $threeMonthsAgo) {
                $timeFrames['last_3_months']++;
            }
        }

        return $timeFrames;
    }

    /**
     * Map incident frequency to probability level
     *
     * Probability Scale:
     * - 1 (Rare): <1 incident per year
     * - 2 (Unlikely): 1-2 incidents per year
     * - 3 (Possible): 3-6 incidents per year (or 1-2 per 6 months)
     * - 4 (Likely): 7-12 incidents per year (or 3-6 per 6 months)
     * - 5 (Almost Certain): >12 incidents per year (or >6 per 6 months)
     *
     * @param array{last_year: int, last_6_months: int, last_3_months: int} $timeFrames
     */
    private function mapFrequencyToProbability(array $timeFrames): int
    {
        $yearlyCount = $timeFrames['last_year'];
        $halfYearCount = $timeFrames['last_6_months'];
        $quarterCount = $timeFrames['last_3_months'];

        // Check most recent data first (gives more weight to recent incidents)
        if ($quarterCount >= 3) {
            return 5; // >12 per year rate
        } elseif ($quarterCount >= 2) {
            return 4; // ~8 per year rate
        }

        if ($halfYearCount >= 6) {
            return 5; // >12 per year rate
        } elseif ($halfYearCount >= 3) {
            return 4; // 6-12 per year rate
        } elseif ($halfYearCount >= 2) {
            return 3; // 3-6 per year rate
        }

        if ($yearlyCount >= 12) {
            return 5; // Almost certain
        } elseif ($yearlyCount >= 7) {
            return 4; // Likely
        } elseif ($yearlyCount >= 3) {
            return 3; // Possible
        } elseif ($yearlyCount >= 1) {
            return 2; // Unlikely
        } else {
            return 1; // Rare
        }
    }

    /**
     * Get detailed probability adjustment analysis
     *
     * @return array{current_probability: int, suggested_probability: int|null, eligible_incidents: int, total_incidents: int, frequency_analysis: array, should_adjust: bool, rationale: string}
     */
    public function analyzeProbabilityAdjustment(Risk $risk): array
    {
        $eligibleIncidents = $this->getEligibleIncidents($risk);
        $totalIncidents = $risk->getIncidents()->count();
        $currentProbability = $risk->getProbability();
        $suggestedProbability = $this->calculateSuggestedProbability($risk);

        $frequencyAnalysis = count($eligibleIncidents) >= self::MINIMUM_REALIZATION_COUNT
            ? $this->analyzeIncidentFrequency($eligibleIncidents)
            : ['last_year' => 0, 'last_6_months' => 0, 'last_3_months' => 0];

        $shouldAdjust = $suggestedProbability !== null;

        $rationale = $this->generateRationale(
            $eligibleIncidents,
            $currentProbability,
            $suggestedProbability,
            $frequencyAnalysis
        );

        return [
            'current_probability' => $currentProbability,
            'suggested_probability' => $suggestedProbability,
            'eligible_incidents' => count($eligibleIncidents),
            'total_incidents' => $totalIncidents,
            'frequency_analysis' => $frequencyAnalysis,
            'should_adjust' => $shouldAdjust,
            'rationale' => $rationale
        ];
    }

    /**
     * Generate human-readable rationale for probability adjustment
     *
     * @param Incident[] $eligibleIncidents
     */
    private function generateRationale(
        array $eligibleIncidents,
        int $currentProbability,
        ?int $suggestedProbability,
        array $frequencyAnalysis
    ): string {
        if (count($eligibleIncidents) < self::MINIMUM_REALIZATION_COUNT) {
            return sprintf(
                'Insufficient historical data (%d eligible incidents, need at least %d)',
                count($eligibleIncidents),
                self::MINIMUM_REALIZATION_COUNT
            );
        }

        if ($suggestedProbability === null) {
            return sprintf(
                'Current probability (%d) already reflects incident history (%d incidents in last year)',
                $currentProbability,
                $frequencyAnalysis['last_year']
            );
        }

        return sprintf(
            'Risk has been realized %d times in the last year (%d in last 6 months, %d in last 3 months). ' .
            'Based on this frequency, probability should be increased from %d to %d.',
            $frequencyAnalysis['last_year'],
            $frequencyAnalysis['last_6_months'],
            $frequencyAnalysis['last_3_months'],
            $currentProbability,
            $suggestedProbability
        );
    }

    /**
     * Find all risks requiring probability adjustment
     *
     * @return array<int, array{risk: Risk, analysis: array}>
     */
    public function findRisksRequiringAdjustment(): array
    {
        $entityRepository = $this->entityManager->getRepository(Risk::class);
        $allRisks = $entityRepository->findAll();

        $requiresAdjustment = [];

        foreach ($allRisks as $allRisk) {
            $analysis = $this->analyzeProbabilityAdjustment($allRisk);

            if ($analysis['should_adjust']) {
                $requiresAdjustment[] = [
                    'risk' => $allRisk,
                    'analysis' => $analysis
                ];
            }
        }

        // Sort by suggested probability increase (highest first)
        usort($requiresAdjustment, function(array $a, array $b): int {
            $diffA = $a['analysis']['suggested_probability'] - $a['analysis']['current_probability'];
            $diffB = $b['analysis']['suggested_probability'] - $b['analysis']['current_probability'];
            return $diffB <=> $diffA;
        });

        $this->logger->info('Found risks requiring probability adjustment', [
            'count' => count($requiresAdjustment),
            'total_risks' => count($allRisks)
        ]);

        return $requiresAdjustment;
    }

    /**
     * Apply probability adjustment to a risk
     *
     * Safe Guard: Requires user confirmation, never auto-applies
     * Safe Guard: Only allows increase, users can manually decrease if needed
     *
     * @return array{success: bool, message: string, old_probability: int, new_probability: int|null}
     */
    public function applyProbabilityAdjustment(Risk $risk, int $newProbability, bool $userConfirmed = false): array
    {
        if (!$userConfirmed) {
            return [
                'success' => false,
                'message' => 'User confirmation required for probability adjustment',
                'old_probability' => $risk->getProbability(),
                'new_probability' => null
            ];
        }

        if ($newProbability < 1 || $newProbability > 5) {
            return [
                'success' => false,
                'message' => 'Probability must be between 1 and 5',
                'old_probability' => $risk->getProbability(),
                'new_probability' => null
            ];
        }

        $oldProbability = $risk->getProbability();

        // Safe Guard: Warn if user is decreasing probability
        if ($newProbability < $oldProbability) {
            $this->logger->warning('User manually decreased probability despite incident history', [
                'risk_id' => $risk->getId(),
                'old_probability' => $oldProbability,
                'new_probability' => $newProbability,
                'eligible_incidents' => count($this->getEligibleIncidents($risk))
            ]);
        }

        $risk->setProbability($newProbability);

        // Recalculate residual probability proportionally
        if ($oldProbability !== 0) {
            $ratio = $newProbability / $oldProbability;
            $newResidualProbability = (int) round($risk->getResidualProbability() * $ratio);
            $risk->setResidualProbability(min(5, max(1, $newResidualProbability)));
        }

        $this->entityManager->persist($risk);
        $this->entityManager->flush();

        // Audit log automatically captures this change
        $this->logger->info('Probability adjusted based on incident history', [
            'risk_id' => $risk->getId(),
            'old_probability' => $oldProbability,
            'new_probability' => $newProbability,
            'incident_count' => count($this->getEligibleIncidents($risk))
        ]);

        return [
            'success' => true,
            'message' => sprintf('Probability updated from %d to %d', $oldProbability, $newProbability),
            'old_probability' => $oldProbability,
            'new_probability' => $newProbability
        ];
    }
}
