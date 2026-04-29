<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Aggregate value object holding the full ISMS implementation journey state.
 *
 * Built by ImplementationJourneyService::getProgress().
 */
class JourneyProgress
{
    /**
     * @param JourneyPhase[] $phases          Ordered list of journey phases
     * @param int            $overallPercent   Weighted overall progress (0-100)
     * @param int            $currentPhaseIndex Index of the current active phase (0-based)
     * @param string         $alvaMood        Alva mascot mood: thinking|working|focused|celebrating
     */
    public function __construct(
        /** @var JourneyPhase[] */
        public readonly array $phases,
        public readonly int $overallPercent,
        public readonly int $currentPhaseIndex,
        public readonly string $alvaMood,
    ) {
    }

    /**
     * Return the phase the user should currently focus on.
     */
    public function getCurrentPhase(): ?JourneyPhase
    {
        return $this->phases[$this->currentPhaseIndex] ?? null;
    }

    /**
     * Return the first phase that is neither locked, complete, nor dismissed.
     */
    public function getNextActionablePhase(): ?JourneyPhase
    {
        foreach ($this->phases as $phase) {
            $status = $phase->getStatus();
            if ($status !== 'locked' && $status !== 'complete' && $status !== 'dismissed') {
                return $phase;
            }
        }

        return null;
    }

    /**
     * How many phases are fully complete (100 %) or dismissed.
     */
    public function getCompletedCount(): int
    {
        $count = 0;
        foreach ($this->phases as $phase) {
            if ($phase->completionPercent >= 100 || $phase->dismissed) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Total number of phases.
     */
    public function getTotalCount(): int
    {
        return count($this->phases);
    }
}
