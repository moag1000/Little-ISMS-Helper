<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTime;
use DateTimeInterface;
use App\Entity\ISMSObjective;
use App\Enum\ISMSObjectiveStatus;
use App\Lifecycle\LifecycleTransitionInterface;
use App\Repository\ISMSObjectiveRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ISMSObjectiveService
{
    public function __construct(
        private readonly ISMSObjectiveRepository $ismsObjectiveRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?LifecycleTransitionInterface $lifecycleService = null,
    ) {}

    /**
     * Create a new ISMS objective
     */
    public function createObjective(ISMSObjective $ismsObjective): void
    {
        $ismsObjective->setUpdatedAt(new DateTimeImmutable());

        $this->entityManager->persist($ismsObjective);
        $this->entityManager->flush();
    }

    /**
     * Update an existing ISMS objective
     */
    public function updateObjective(ISMSObjective $ismsObjective): void
    {
        $ismsObjective->setUpdatedAt(new DateTimeImmutable());

        // Automatically set achieved date when status changes to achieved
        if ($ismsObjective->getStatus() === ISMSObjectiveStatus::Achieved->value && !$ismsObjective->getAchievedDate()) {
            $ismsObjective->setAchievedDate(new DateTime());
        }

        $this->entityManager->flush();
    }

    /**
     * Delete an ISMS objective
     */
    public function deleteObjective(ISMSObjective $ismsObjective): void
    {
        $this->entityManager->remove($ismsObjective);
        $this->entityManager->flush();
    }

    /**
     * Get statistics for all objectives
     */
    public function getStatistics(): array
    {
        $objectives = $this->ismsObjectiveRepository->findAll();
        $active = $this->ismsObjectiveRepository->findActive();

        return [
            'total' => count($objectives),
            'active' => count($active),
            'achieved' => count($this->ismsObjectiveRepository->findBy(['status' => ISMSObjectiveStatus::Achieved->value])),
            'delayed' => count(array_filter($objectives, fn(ISMSObjective $obj): bool => $obj->getStatus() === ISMSObjectiveStatus::InProgress->value &&
                   $obj->getTargetDate() < new DateTime() &&
                   !$obj->getAchievedDate())),
            'at_risk' => $this->countAtRiskObjectives($objectives),
        ];
    }

    /**
     * Count objectives at risk (within 30 days of target date but not achieved)
     */
    private function countAtRiskObjectives(array $objectives): int
    {
        $thirtyDaysFromNow = new DateTime()->modify('+30 days');

        return count(array_filter($objectives, fn($obj): bool => $obj->getStatus() === ISMSObjectiveStatus::InProgress->value &&
               $obj->getTargetDate() <= $thirtyDaysFromNow &&
               $obj->getTargetDate() >= new DateTime()));
    }

    /**
     * Get objectives by category
     */
    public function getObjectivesByCategory(string $category): array
    {
        return $this->ismsObjectiveRepository->findBy(['category' => $category]);
    }

    /**
     * Get overdue objectives
     */
    public function getOverdueObjectives(): array
    {
        $objectives = $this->ismsObjectiveRepository->findActive();

        return array_filter($objectives, fn(ISMSObjective $obj): bool => $obj->getTargetDate() < new DateTime());
    }

    /**
     * Get objectives at risk (within 30 days)
     */
    public function getAtRiskObjectives(): array
    {
        $objectives = $this->ismsObjectiveRepository->findActive();
        $thirtyDaysFromNow = new DateTime()->modify('+30 days');

        return array_filter($objectives, fn(ISMSObjective $obj): bool => $obj->getTargetDate() <= $thirtyDaysFromNow &&
               $obj->getTargetDate() >= new DateTime());
    }

    /**
     * Calculate overall progress across all objectives
     */
    public function calculateOverallProgress(): float
    {
        $objectives = $this->ismsObjectiveRepository->findAll();

        if (empty($objectives)) {
            return 0.0;
        }

        $totalProgress = 0;
        $count = 0;

        foreach ($objectives as $objective) {
            if ($objective->getTargetValue() && $objective->getCurrentValue()) {
                $totalProgress += $objective->getProgressPercentage();
                $count++;
            }
        }

        return $count > 0 ? round($totalProgress / $count, 2) : 0.0;
    }

    /**
     * Update progress for an objective
     */
    public function updateProgress(ISMSObjective $ismsObjective, float $currentValue, ?string $notes = null): void
    {
        $ismsObjective->setCurrentValue((string)$currentValue);

        if ($notes) {
            $existingNotes = $ismsObjective->getProgressNotes() ?? '';
            $timestamp = new DateTime()->format('Y-m-d H:i');
            $newNote = "[$timestamp] $notes";

            $ismsObjective->setProgressNotes(
                $existingNotes !== '' && $existingNotes !== '0' ? "$existingNotes\n\n$newNote" : $newNote
            );
        }

        // Check if objective is now achieved; transition via Lifecycle X.1.
        // C-08: previously fell back to direct setStatus() when the Workflow
        // guard rejected the transition (LifecycleService null OR status not
        // in_progress) — that bypass skipped Voter / audit-log / regulatory
        // guards and could promote a not_started/delayed objective to achieved
        // without ever going through `start`. Removed.
        // InvalidTransitionException / FourEyesRequiredException propagate to
        // the caller (Controller), which translates to a flash message.
        // For objectives that aren't in_progress, the user must first transition
        // via the lifecycle dropdown (start / reopen).
        if ($ismsObjective->getTargetValue() && $currentValue >= (float)$ismsObjective->getTargetValue()) {
            if ($this->lifecycleService === null) {
                // @intentional-assertion: LifecycleService is required; the previous
                // setStatus() fallback bypassed Voter / audit-log (C-08).
                throw new \LogicException(
                    'ISMSObjectiveService::updateProgress() requires LifecycleService; '
                    . 'direct setStatus() fallback removed (C-08).'
                );
            }
            if ($ismsObjective->getStatus() === ISMSObjectiveStatus::InProgress->value) {
                // Lifecycle X.1: canonical `achieve` transition (in_progress → achieved).
                $this->lifecycleService->transition(
                    $ismsObjective,
                    'isms_objective_lifecycle',
                    'achieve',
                );
                $ismsObjective->setAchievedDate(new DateTime());
            }
            // else: auto-promotion to achieved is no longer silent for non-
            // in_progress states. The user/operator must transition explicitly.
        }

        $this->updateObjective($ismsObjective);
    }

    /**
     * Validate objective data
     */
    public function validateObjective(ISMSObjective $ismsObjective): array
    {
        $errors = [];

        if (in_array($ismsObjective->getTitle(), [null, '', '0'], true)) {
            $errors[] = 'Titel ist erforderlich.';
        }

        if (in_array($ismsObjective->getDescription(), [null, '', '0'], true)) {
            $errors[] = 'Beschreibung ist erforderlich.';
        }

        if (in_array($ismsObjective->getCategory(), [null, '', '0'], true)) {
            $errors[] = 'Kategorie ist erforderlich.';
        }

        if (in_array($ismsObjective->getResponsiblePerson(), [null, '', '0'], true)) {
            $errors[] = 'Verantwortliche Person ist erforderlich.';
        }

        if (!$ismsObjective->getTargetDate() instanceof DateTimeInterface) {
            $errors[] = 'Zieldatum ist erforderlich.';
        }

        return $errors;
    }

    /**
     * Generate recommendations for an objective
     */
    public function generateRecommendations(ISMSObjective $ismsObjective): array
    {
        $recommendations = [];

        // Check if target date is approaching
        $daysUntilTarget = $this->getDaysUntilTarget($ismsObjective);
        if ($daysUntilTarget !== null && $daysUntilTarget > 0 && $daysUntilTarget <= 30) {
            $recommendations[] = "Das Zieldatum ist in $daysUntilTarget Tagen. Bitte überprüfen Sie den Fortschritt.";
        }

        // Check if overdue
        if ($daysUntilTarget !== null && $daysUntilTarget < 0) {
            $recommendations[] = 'Dieses Ziel ist überfällig. Bitte aktualisieren Sie den Status oder das Zieldatum.';
        }

        // Check progress
        $progress = $ismsObjective->getProgressPercentage();
        if ($progress < 50 && $daysUntilTarget !== null && $daysUntilTarget <= 60) {
            $recommendations[] = 'Der Fortschritt liegt unter 50%. Erwägen Sie zusätzliche Ressourcen oder eine Anpassung des Zieldatums.';
        }

        // Check if measurable indicators are missing
        if (in_array($ismsObjective->getMeasurableIndicators(), [null, '', '0'], true)) {
            $recommendations[] = 'Definieren Sie messbare Indikatoren, um den Fortschritt besser verfolgen zu können.';
        }

        // Check if values are missing
        if (!$ismsObjective->getTargetValue()) {
            $recommendations[] = 'Legen Sie einen Zielwert fest, um den Fortschritt quantifizieren zu können.';
        }

        return $recommendations;
    }

    /**
     * Get days until target date
     */
    private function getDaysUntilTarget(ISMSObjective $ismsObjective): ?int
    {
        $targetDate = $ismsObjective->getTargetDate();

        if (!$targetDate instanceof DateTimeInterface) {
            return null;
        }

        $now = new DateTime();
        $dateInterval = $now->diff($targetDate);

        return $dateInterval->invert ? -$dateInterval->days : $dateInterval->days;
    }
}
