<?php

namespace App\Service;

use App\Entity\ISMSObjective;
use App\Repository\ISMSObjectiveRepository;
use Doctrine\ORM\EntityManagerInterface;

class ISMSObjectiveService
{
    public function __construct(
        private ISMSObjectiveRepository $objectiveRepository,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Create a new ISMS objective
     */
    public function createObjective(ISMSObjective $objective): void
    {
        $objective->setUpdatedAt(new \DateTime());

        $this->entityManager->persist($objective);
        $this->entityManager->flush();
    }

    /**
     * Update an existing ISMS objective
     */
    public function updateObjective(ISMSObjective $objective): void
    {
        $objective->setUpdatedAt(new \DateTime());

        // Automatically set achieved date when status changes to achieved
        if ($objective->getStatus() === 'achieved' && !$objective->getAchievedDate()) {
            $objective->setAchievedDate(new \DateTime());
        }

        $this->entityManager->flush();
    }

    /**
     * Delete an ISMS objective
     */
    public function deleteObjective(ISMSObjective $objective): void
    {
        $this->entityManager->remove($objective);
        $this->entityManager->flush();
    }

    /**
     * Get statistics for all objectives
     */
    public function getStatistics(): array
    {
        $objectives = $this->objectiveRepository->findAll();
        $active = $this->objectiveRepository->findActive();

        return [
            'total' => count($objectives),
            'active' => count($active),
            'achieved' => count($this->objectiveRepository->findBy(['status' => 'achieved'])),
            'delayed' => count(array_filter($objectives, function($obj) {
                return $obj->getStatus() === 'in_progress' &&
                       $obj->getTargetDate() < new \DateTime() &&
                       !$obj->getAchievedDate();
            })),
            'at_risk' => $this->countAtRiskObjectives($objectives),
        ];
    }

    /**
     * Count objectives at risk (within 30 days of target date but not achieved)
     */
    private function countAtRiskObjectives(array $objectives): int
    {
        $thirtyDaysFromNow = (new \DateTime())->modify('+30 days');

        return count(array_filter($objectives, function($obj) use ($thirtyDaysFromNow) {
            return $obj->getStatus() === 'in_progress' &&
                   $obj->getTargetDate() <= $thirtyDaysFromNow &&
                   $obj->getTargetDate() >= new \DateTime();
        }));
    }

    /**
     * Get objectives by category
     */
    public function getObjectivesByCategory(string $category): array
    {
        return $this->objectiveRepository->findBy(['category' => $category]);
    }

    /**
     * Get overdue objectives
     */
    public function getOverdueObjectives(): array
    {
        $objectives = $this->objectiveRepository->findActive();

        return array_filter($objectives, function($obj) {
            return $obj->getTargetDate() < new \DateTime();
        });
    }

    /**
     * Get objectives at risk (within 30 days)
     */
    public function getAtRiskObjectives(): array
    {
        $objectives = $this->objectiveRepository->findActive();
        $thirtyDaysFromNow = (new \DateTime())->modify('+30 days');

        return array_filter($objectives, function($obj) use ($thirtyDaysFromNow) {
            return $obj->getTargetDate() <= $thirtyDaysFromNow &&
                   $obj->getTargetDate() >= new \DateTime();
        });
    }

    /**
     * Calculate overall progress across all objectives
     */
    public function calculateOverallProgress(): float
    {
        $objectives = $this->objectiveRepository->findAll();

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
    public function updateProgress(ISMSObjective $objective, float $currentValue, ?string $notes = null): void
    {
        $objective->setCurrentValue((string)$currentValue);

        if ($notes) {
            $existingNotes = $objective->getProgressNotes() ?? '';
            $timestamp = (new \DateTime())->format('Y-m-d H:i');
            $newNote = "[$timestamp] $notes";

            $objective->setProgressNotes(
                $existingNotes ? "$existingNotes\n\n$newNote" : $newNote
            );
        }

        // Check if objective is now achieved
        if ($objective->getTargetValue() && (float)$currentValue >= (float)$objective->getTargetValue()) {
            $objective->setStatus('achieved');
            $objective->setAchievedDate(new \DateTime());
        }

        $this->updateObjective($objective);
    }

    /**
     * Validate objective data
     */
    public function validateObjective(ISMSObjective $objective): array
    {
        $errors = [];

        if (empty($objective->getTitle())) {
            $errors[] = 'Titel ist erforderlich.';
        }

        if (empty($objective->getDescription())) {
            $errors[] = 'Beschreibung ist erforderlich.';
        }

        if (empty($objective->getCategory())) {
            $errors[] = 'Kategorie ist erforderlich.';
        }

        if (empty($objective->getResponsiblePerson())) {
            $errors[] = 'Verantwortliche Person ist erforderlich.';
        }

        if (!$objective->getTargetDate()) {
            $errors[] = 'Zieldatum ist erforderlich.';
        }

        return $errors;
    }

    /**
     * Generate recommendations for an objective
     */
    public function generateRecommendations(ISMSObjective $objective): array
    {
        $recommendations = [];

        // Check if target date is approaching
        $daysUntilTarget = $this->getDaysUntilTarget($objective);
        if ($daysUntilTarget !== null && $daysUntilTarget > 0 && $daysUntilTarget <= 30) {
            $recommendations[] = "Das Zieldatum ist in $daysUntilTarget Tagen. Bitte überprüfen Sie den Fortschritt.";
        }

        // Check if overdue
        if ($daysUntilTarget !== null && $daysUntilTarget < 0) {
            $recommendations[] = 'Dieses Ziel ist überfällig. Bitte aktualisieren Sie den Status oder das Zieldatum.';
        }

        // Check progress
        $progress = $objective->getProgressPercentage();
        if ($progress < 50 && $daysUntilTarget !== null && $daysUntilTarget <= 60) {
            $recommendations[] = 'Der Fortschritt liegt unter 50%. Erwägen Sie zusätzliche Ressourcen oder eine Anpassung des Zieldatums.';
        }

        // Check if measurable indicators are missing
        if (empty($objective->getMeasurableIndicators())) {
            $recommendations[] = 'Definieren Sie messbare Indikatoren, um den Fortschritt besser verfolgen zu können.';
        }

        // Check if values are missing
        if (!$objective->getTargetValue()) {
            $recommendations[] = 'Legen Sie einen Zielwert fest, um den Fortschritt quantifizieren zu können.';
        }

        return $recommendations;
    }

    /**
     * Get days until target date
     */
    private function getDaysUntilTarget(ISMSObjective $objective): ?int
    {
        $targetDate = $objective->getTargetDate();

        if (!$targetDate) {
            return null;
        }

        $now = new \DateTime();
        $interval = $now->diff($targetDate);

        return $interval->invert ? -$interval->days : $interval->days;
    }
}
