<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BCExercise;
use App\Entity\Bsi2004ExerciseLog;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Domain service for BSI-200-4 Übungs-Logbuch (F27).
 *
 * Handles creation from a BCExercise, lifecycle transitions (submit / confirm)
 * and extraction of improvement actions for follow-up task management.
 */
final class Bsi2004ExerciseLogService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Create a new log pre-filled from the BCExercise data.
     * Participants are seeded from the exercise's `participants` free-text field;
     * the caller may enrich them further before persisting.
     */
    public function createFromExercise(BCExercise $exercise): Bsi2004ExerciseLog
    {
        if ($exercise->getExerciseLog() !== null) {
            throw new RuntimeException(sprintf(
                'BCExercise #%d already has a Bsi2004ExerciseLog.',
                (int) $exercise->getId()
            ));
        }

        $log = new Bsi2004ExerciseLog();
        $log->setTenant($exercise->getTenant());
        $log->setBcExercise($exercise);

        // Map BCExercise.exerciseType → log exercise type (best-effort; fall back to tabletop)
        $mapped = $this->mapLegacyExerciseType($exercise->getExerciseType() ?? '');
        $log->setExerciseType($mapped);

        // Pre-fill scenario from exercise description
        $log->setScenarioSummary(
            ($exercise->getScenario() ?? '') !== ''
                ? (string) $exercise->getScenario()
                : (string) ($exercise->getDescription() ?? '')
        );

        // Pre-fill objectives from exercise objectives text
        $raw = $exercise->getObjectives() ?? '';
        if ($raw !== '') {
            // Split newline-separated objectives into array
            $lines = array_filter(
                array_map('trim', explode("\n", $raw)),
                static fn (string $l): bool => $l !== ''
            );
            $log->setObjectives(array_values($lines));
        } else {
            $log->setObjectives([]);
        }

        // Seed participants from free-text field
        $participantText = $exercise->getParticipants() ?? '';
        $participants = [];
        if ($participantText !== '') {
            foreach (explode(',', $participantText) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $participants[] = ['name' => $name];
                }
            }
        }
        $log->setParticipants($participants);

        return $log;
    }

    /**
     * Finalize and submit the log.
     * Sets submittedAt + submittedBy and writes audit log.
     */
    public function markComplete(Bsi2004ExerciseLog $log, User $submittedBy): void
    {
        if ($log->isSubmitted()) {
            throw new RuntimeException('Log is already submitted.');
        }

        $log->setSubmittedBy($submittedBy);
        $log->setSubmittedAt(new DateTimeImmutable());

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            AuditLogger::ACTION_BSI_2004_LOG_SUBMITTED,
            'Bsi2004ExerciseLog',
            $log->getId() ?? 0,
            null,
            ['submitted_by' => $submittedBy->getId()],
            sprintf(
                'BSI-200-4 Übungs-Logbuch für Übung "%s" eingereicht.',
                $log->getBcExercise()?->getName() ?? '?'
            )
        );
    }

    /**
     * Auditor confirms the submitted log.
     */
    public function confirmByAuditor(Bsi2004ExerciseLog $log, User $auditor): void
    {
        if (!$log->isSubmitted()) {
            throw new RuntimeException('Log must be submitted before it can be confirmed.');
        }
        if ($log->isConfirmed()) {
            throw new RuntimeException('Log is already confirmed.');
        }

        $log->setConfirmedByAuditor($auditor);
        $log->setConfirmedAt(new DateTimeImmutable());

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            AuditLogger::ACTION_BSI_2004_LOG_CONFIRMED,
            'Bsi2004ExerciseLog',
            $log->getId() ?? 0,
            null,
            ['confirmed_by' => $auditor->getId()],
            sprintf(
                'BSI-200-4 Übungs-Logbuch für Übung "%s" durch Auditor bestätigt.',
                $log->getBcExercise()?->getName() ?? '?'
            )
        );
    }

    /**
     * Extract improvement actions as plain associative arrays for downstream task-surfacing.
     * Returns only non-completed items with a description.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractImprovementActionsAsTasks(Bsi2004ExerciseLog $log): array
    {
        $actions = $log->getImprovementActions() ?? [];
        return array_values(array_filter(
            $actions,
            static fn (array $a): bool =>
                !empty($a['description']) && empty($a['completed'])
        ));
    }

    // -------------------------------------------------------------------------

    private function mapLegacyExerciseType(string $type): string
    {
        return match ($type) {
            'tabletop'      => Bsi2004ExerciseLog::EXERCISE_TYPE_TABLETOP,
            'walkthrough'   => Bsi2004ExerciseLog::EXERCISE_TYPE_WALKTHROUGH,
            'simulation'    => Bsi2004ExerciseLog::EXERCISE_TYPE_SIMULATION,
            'full_test'     => Bsi2004ExerciseLog::EXERCISE_TYPE_FULL_SCALE,
            'component_test' => Bsi2004ExerciseLog::EXERCISE_TYPE_TECHNICAL_RECOVERY,
            default         => Bsi2004ExerciseLog::EXERCISE_TYPE_TABLETOP,
        };
    }
}
