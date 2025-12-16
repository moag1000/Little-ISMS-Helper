<?php

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardSession;
use App\Repository\WizardSessionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Wizard Progress Service
 *
 * Phase 7E: Manages compliance wizard sessions and progress tracking.
 * Allows users to pause, resume, and complete wizard assessments.
 */
class WizardProgressService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WizardSessionRepository $sessionRepository,
        private readonly ComplianceWizardService $wizardService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Start a new wizard session or resume existing one
     */
    public function startOrResumeSession(User $user, Tenant $tenant, string $wizardType): WizardSession
    {
        // Check for existing in-progress session
        $existingSession = $this->sessionRepository->findActiveSession($user, $wizardType);

        if ($existingSession !== null) {
            $this->logger->info('Resuming existing wizard session', [
                'session_id' => $existingSession->getId(),
                'wizard_type' => $wizardType,
                'user' => $user->getEmail(),
            ]);

            // Update last activity
            $existingSession->setLastActivityAt(new DateTimeImmutable());
            $this->entityManager->flush();

            return $existingSession;
        }

        // Get wizard configuration for total steps
        $wizardConfig = $this->wizardService->getWizardConfig($wizardType);
        $totalSteps = $wizardConfig !== null ? count($wizardConfig['categories'] ?? []) : 1;

        // Create new session
        $session = new WizardSession();
        $session->setUser($user);
        $session->setTenant($tenant);
        $session->setWizardType($wizardType);
        $session->setTotalSteps($totalSteps);
        $session->setCurrentStep(1);

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $this->logger->info('Started new wizard session', [
            'session_id' => $session->getId(),
            'wizard_type' => $wizardType,
            'user' => $user->getEmail(),
            'total_steps' => $totalSteps,
        ]);

        return $session;
    }

    /**
     * Update session progress
     */
    public function updateProgress(
        WizardSession $session,
        int $currentStep,
        ?string $completedCategory = null
    ): WizardSession {
        $session->setCurrentStep($currentStep);
        $session->setLastActivityAt(new DateTimeImmutable());

        if ($completedCategory !== null) {
            $session->addCompletedCategory($completedCategory);
        }

        $this->entityManager->flush();

        $this->logger->debug('Updated wizard progress', [
            'session_id' => $session->getId(),
            'current_step' => $currentStep,
            'completed_category' => $completedCategory,
        ]);

        return $session;
    }

    /**
     * Save assessment results to session
     */
    public function saveAssessmentResults(WizardSession $session, array $results): WizardSession
    {
        $session->setAssessmentResults($results);

        if (isset($results['overall_score'])) {
            $session->setOverallScore((int) $results['overall_score']);
        }

        if (isset($results['critical_gaps'])) {
            $session->setCriticalGaps($results['critical_gaps']);
        }

        if (isset($results['categories'])) {
            $recommendations = [];
            foreach ($results['categories'] as $categoryKey => $category) {
                if (!empty($category['gaps'])) {
                    foreach ($category['gaps'] as $gap) {
                        $recommendations[] = [
                            'category' => $categoryKey,
                            'category_name' => $category['name'] ?? $categoryKey,
                            'gap' => $gap,
                            'priority' => $this->calculateGapPriority($gap),
                        ];
                    }
                }
            }
            // Sort by priority (high first)
            usort($recommendations, fn($a, $b) => $b['priority'] <=> $a['priority']);
            $session->setRecommendations($recommendations);
        }

        $session->setLastActivityAt(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->logger->info('Saved assessment results', [
            'session_id' => $session->getId(),
            'overall_score' => $session->getOverallScore(),
            'critical_gaps_count' => count($session->getCriticalGaps()),
        ]);

        return $session;
    }

    /**
     * Complete a wizard session
     */
    public function completeSession(WizardSession $session): WizardSession
    {
        // Run final assessment if not already done
        if (empty($session->getAssessmentResults())) {
            $results = $this->wizardService->runAssessment($session->getWizardType());
            if ($results['success']) {
                $this->saveAssessmentResults($session, $results);
            }
        }

        $session->complete();
        $this->entityManager->flush();

        $this->logger->info('Completed wizard session', [
            'session_id' => $session->getId(),
            'wizard_type' => $session->getWizardType(),
            'overall_score' => $session->getOverallScore(),
        ]);

        return $session;
    }

    /**
     * Abandon a wizard session
     */
    public function abandonSession(WizardSession $session): WizardSession
    {
        $session->abandon();
        $this->entityManager->flush();

        $this->logger->info('Abandoned wizard session', [
            'session_id' => $session->getId(),
            'wizard_type' => $session->getWizardType(),
        ]);

        return $session;
    }

    /**
     * Get session by ID with security check
     */
    public function getSession(int $sessionId, User $user): ?WizardSession
    {
        $session = $this->sessionRepository->find($sessionId);

        if ($session === null) {
            return null;
        }

        // Security check: user must own the session or be admin
        if ($session->getUser() !== $user) {
            $this->logger->warning('Unauthorized session access attempt', [
                'session_id' => $sessionId,
                'requesting_user' => $user->getEmail(),
                'session_owner' => $session->getUser()->getEmail(),
            ]);
            return null;
        }

        return $session;
    }

    /**
     * Get all sessions for a user
     */
    public function getUserSessions(User $user): array
    {
        return $this->sessionRepository->findByUser($user);
    }

    /**
     * Get completed sessions for a user
     */
    public function getCompletedSessions(User $user): array
    {
        return $this->sessionRepository->findCompletedByUser($user);
    }

    /**
     * Get latest completed assessment for a wizard type
     */
    public function getLatestAssessment(Tenant $tenant, string $wizardType): ?WizardSession
    {
        return $this->sessionRepository->findLatestCompletedForTenant($tenant, $wizardType);
    }

    /**
     * Get statistics for a tenant
     */
    public function getTenantStatistics(Tenant $tenant): array
    {
        return $this->sessionRepository->getStatisticsForTenant($tenant);
    }

    /**
     * Auto-abandon old sessions
     */
    public function autoAbandonOldSessions(int $daysInactive = 30): int
    {
        $oldSessions = $this->sessionRepository->findAbandonedSessions($daysInactive);
        $count = 0;

        foreach ($oldSessions as $session) {
            $session->abandon();
            $count++;
        }

        if ($count > 0) {
            $this->entityManager->flush();
            $this->logger->info('Auto-abandoned old wizard sessions', ['count' => $count]);
        }

        return $count;
    }

    /**
     * Cleanup old abandoned sessions
     */
    public function cleanupOldSessions(int $daysOld = 90): int
    {
        $count = $this->sessionRepository->cleanupAbandonedSessions($daysOld);

        if ($count > 0) {
            $this->logger->info('Cleaned up old abandoned sessions', ['count' => $count]);
        }

        return $count;
    }

    /**
     * Calculate gap priority based on keywords
     */
    private function calculateGapPriority(string $gap): int
    {
        $gap = strtolower($gap);

        // Critical keywords
        if (str_contains($gap, 'critical') || str_contains($gap, 'security') || str_contains($gap, 'breach')) {
            return 3;
        }

        // High priority keywords
        if (str_contains($gap, 'missing') || str_contains($gap, 'required') || str_contains($gap, 'mandatory')) {
            return 2;
        }

        // Default priority
        return 1;
    }

    /**
     * Generate summary for a completed session
     */
    public function generateSessionSummary(WizardSession $session): array
    {
        $results = $session->getAssessmentResults();

        $summary = [
            'wizard_type' => $session->getWizardType(),
            'wizard_name' => $session->getWizardName(),
            'overall_score' => $session->getOverallScore(),
            'status' => $results['status'] ?? 'unknown',
            'completed_at' => $session->getCompletedAt()?->format('Y-m-d H:i:s'),
            'duration_minutes' => $this->calculateDuration($session),
            'total_categories' => count($results['categories'] ?? []),
            'critical_gaps' => count($session->getCriticalGaps()),
            'recommendations_count' => count($session->getRecommendations()),
            'categories' => [],
        ];

        // Add category summaries
        foreach ($results['categories'] ?? [] as $key => $category) {
            $summary['categories'][$key] = [
                'name' => $category['name'] ?? $key,
                'score' => $category['score'] ?? 0,
                'gaps_count' => count($category['gaps'] ?? []),
            ];
        }

        return $summary;
    }

    /**
     * Calculate session duration in minutes
     */
    private function calculateDuration(WizardSession $session): int
    {
        $start = $session->getStartedAt();
        $end = $session->getCompletedAt() ?? $session->getLastActivityAt() ?? new DateTimeImmutable();

        if ($start === null) {
            return 0;
        }

        return (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);
    }
}
