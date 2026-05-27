<?php

declare(strict_types=1);

namespace App\Service\Clone;

use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * BCExercise Cloner (C4-C1 — Klon-Funktionen).
 *
 * Use case: recurring drill cadence — copy last quarter's tabletop exercise
 * template (scope, objectives, scenario, success criteria, facilitator) and
 * reschedule. ISO 22301 Cl. 8.5 requires periodic exercises; the operational
 * scaffolding rarely changes between sessions.
 *
 * The clone keeps the planning template (exerciseType, scope, objectives,
 * scenario, tested BC-plans M2M, facilitator/leader/observer Pattern-A
 * dual-state refs, success criteria scaffolding) and resets all
 * execution/result fields.
 *
 * Reset on clone:
 *   - status → 'planned' (initial lifecycle marking)
 *   - exerciseDate cleared (must be re-planned)
 *   - durationHours kept (template value)
 *   - results / whatWentWell / areasForImprovement / findings / actionItems
 *     / lessonsLearned / planUpdatesRequired all cleared
 *   - actualRtoAchieved / actualRpoAchieved cleared
 *   - successRating cleared
 *   - evidenceArtifacts cleared
 *   - reportCompleted → false; reportDate cleared
 *
 * Cascade omissions:
 *   - exerciseLog (Bsi2004ExerciseLog OneToOne) — log is per-execution
 *   - documents M2M — evidence files belong to specific exercise run
 *   - participants/observers legacy text — per-session attendance list
 *
 * Caller is expected to flush.
 */
final class BCExerciseCloner implements EntityClonerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supportsEntity(): string
    {
        return BCExercise::class;
    }

    /**
     * @param BCExercise $source
     */
    public function clone(object $source, ?Tenant $targetTenant = null, ?string $titleOverride = null): BCExercise
    {
        if (!$source instanceof BCExercise) {
            throw new \InvalidArgumentException(sprintf(
                'BCExerciseCloner expects %s, got %s',
                BCExercise::class,
                $source::class,
            ));
        }

        $clone = new BCExercise();

        $tenant = $targetTenant ?? $source->getTenant();
        if ($tenant instanceof Tenant) {
            $clone->setTenant($tenant);
        }

        $baseName = (string) $source->getName();
        $clone->setName($titleOverride !== null && $titleOverride !== ''
            ? $titleOverride
            : ($baseName !== '' ? $baseName . ' (Kopie)' : 'Kopie')
        );

        $clone->setExerciseType($source->getExerciseType());
        $clone->setDescription($source->getDescription());
        $clone->setScope($source->getScope());
        $clone->setObjectives($source->getObjectives());
        $clone->setScenario($source->getScenario());
        $clone->setDurationHours($source->getDurationHours());

        // Facilitator / leader Pattern-A dual-state refs (User OR Person)
        // — at least one must remain so the validateFacilitatorSlot
        // callback passes on the clone.
        $clone->setFacilitator($source->getFacilitator());
        $clone->setFacilitatorUser($source->getFacilitatorUser());
        $clone->setFacilitatorPerson($source->getFacilitatorPerson());
        $clone->setExerciseLeaderUser($source->getExerciseLeaderUser());
        $clone->setExerciseLeaderPerson($source->getExerciseLeaderPerson());

        // Tested BC-plans M2M — template stays attached to the next session.
        foreach ($source->getTestedPlans() as $plan) {
            if ($plan instanceof BusinessContinuityPlan) {
                $clone->addTestedPlan($plan);
            }
        }

        // Success-criteria scaffolding (criteria list, not pass/fail data).
        $clone->setSuccessCriteria($source->getSuccessCriteria());

        // Reset execution + results
        $clone->setStatus('planned');
        $clone->setExerciseDate(null);
        $clone->setResults(null);
        $clone->setWhatWentWell(null);
        $clone->setAreasForImprovement(null);
        $clone->setFindings(null);
        $clone->setActionItems(null);
        $clone->setLessonsLearned(null);
        $clone->setPlanUpdatesRequired(null);
        $clone->setActualRtoAchieved(null);
        $clone->setActualRpoAchieved(null);
        $clone->setSuccessRating(null);
        $clone->setEvidenceArtifacts(null);
        $clone->setReportCompleted(false);
        $clone->setReportDate(null);
        $clone->setParticipants(null);
        $clone->setObservers(null);

        $clone->setCreatedAt(new DateTimeImmutable());
        $clone->setUpdatedAt(null);

        $this->entityManager->persist($clone);

        return $clone;
    }
}
