<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\DataProtectionImpactAssessment;
use App\Entity\ProcessingActivity;
use App\Service\AutoReactionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Audit V3 C3 — Auto-DPIA-Suggestion.
 *
 * On ProcessingActivity create/update: when high-risk indicators are present
 * (special categories, automated decision-making, large scale) and no DPIA
 * is yet linked, create a draft DPIA skeleton in status='draft'.
 *
 * Toggle: AutoReactionService::KEY_DPIA_SUGGEST (default true).
 */
#[AsEntityListener(event: Events::postPersist, entity: ProcessingActivity::class)]
#[AsEntityListener(event: Events::postUpdate, entity: ProcessingActivity::class)]
class AutoReactionDpiaSuggestListener
{
    public function __construct(
        private readonly AutoReactionService $reactions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postPersist(ProcessingActivity $activity, PostPersistEventArgs $args): void
    {
        $this->maybeSuggestDpia($activity, $args);
    }

    public function postUpdate(ProcessingActivity $activity, PostUpdateEventArgs $args): void
    {
        $this->maybeSuggestDpia($activity, $args);
    }

    private function maybeSuggestDpia(ProcessingActivity $activity, mixed $args): void
    {
        if (!$this->reactions->isEnabled(AutoReactionService::KEY_DPIA_SUGGEST)) {
            return;
        }

        if (!$this->isHighRisk($activity)) {
            return;
        }

        try {
            $em = $args->getObjectManager();

            // Already linked DPIA?
            $existing = $em->getRepository(DataProtectionImpactAssessment::class)
                ->findOneBy(['processingActivity' => $activity]);
            if ($existing instanceof DataProtectionImpactAssessment) {
                return;
            }

            $dpia = new DataProtectionImpactAssessment();
            $dpia->setTenant($activity->getTenant());
            $dpia->setProcessingActivity($activity);
            $dpia->setTitle('DPIA Auto-Suggestion: ' . ($activity->getTitle() ?? 'Processing-Activity'));
            $dpia->setStatus('draft');
            if (method_exists($dpia, 'setProcessingDescription')) {
                $dpia->setProcessingDescription(
                    'Auto-generated DPIA skeleton based on high-risk indicators (special categories / automated decision-making / large scale). Review and complete required sections (Art. 35 (7) GDPR).'
                );
            }
            $em->persist($dpia);
            $em->flush();

            $this->logger->info('Auto-DPIA suggestion created', [
                'processing_activity_id' => $activity->getId(),
                'dpia_id' => $dpia->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-DPIA suggestion failed', [
                'processing_activity_id' => $activity->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isHighRisk(ProcessingActivity $activity): bool
    {
        if (method_exists($activity, 'getProcessesSpecialCategories')
            && $activity->getProcessesSpecialCategories() === true) {
            return true;
        }
        if (method_exists($activity, 'getAutomatedDecisionMakingDetails')) {
            $admd = $activity->getAutomatedDecisionMakingDetails();
            if (!empty($admd)) {
                return true;
            }
        }
        // Large scale heuristic
        if (method_exists($activity, 'getEstimatedDataSubjectsCount')
            && (int) $activity->getEstimatedDataSubjectsCount() > 1000) {
            return true;
        }
        return false;
    }
}
