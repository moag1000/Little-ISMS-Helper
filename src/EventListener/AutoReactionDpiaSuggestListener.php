<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\DataProtectionImpactAssessment;
use App\Entity\ProcessingActivity;
use App\Repository\UserRepository;
use App\Service\AutoReactionService;
use App\Service\EmailNotificationService;
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
        private readonly ?EmailNotificationService $emailNotifier = null,
        private readonly ?UserRepository $userRepository = null,
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
            // ProcessingActivity exposes the human-readable label via getName();
            // earlier versions of this listener mistakenly called getTitle()
            // which silently threw and was caught + logged as a warning, so the
            // DPIA skeleton was never persisted. V3 W2-Bug1 fixes the call.
            $dpia->setTitle(sprintf(
                'DPIA Auto-Suggestion: %s (%s)',
                $activity->getName() ?? 'Processing-Activity',
                (new \DateTimeImmutable())->format('Y-m-d'),
            ));
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

            // V3 W2-H4 (ISO 27001 Cl.7.4 / GDPR Art.35): Notify DPO so the
            // auto-suggested skeleton enters the DPO's queue immediately.
            $this->notifyDpo($activity, $dpia);
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-DPIA suggestion failed', [
                'processing_activity_id' => $activity->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * V3 W2-H4 — Notify ROLE_DPO (tenant-scoped) about the auto-generated
     * DPIA skeleton. Best-effort: failures must not block the persist.
     */
    private function notifyDpo(ProcessingActivity $activity, DataProtectionImpactAssessment $dpia): void
    {
        if ($this->emailNotifier === null || $this->userRepository === null) {
            return;
        }
        try {
            $tenant = $activity->getTenant();
            $recipients = $this->userRepository->findByRoleInTenant('ROLE_DPO', $tenant);
            if (empty($recipients)) {
                return;
            }
            $subject = sprintf(
                'Auto-DPIA suggested for processing activity "%s"',
                (string) ($activity->getName() ?? '—')
            );
            $this->emailNotifier->sendGenericNotification(
                $subject,
                'emails/auto_reaction_dpia_suggested.html.twig',
                [
                    'activity' => $activity,
                    'dpia' => $dpia,
                    'reasons' => $this->highRiskReasons($activity),
                ],
                $recipients
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-DPIA DPO notification failed', [
                'processing_activity_id' => $activity->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * V3 W2-H5: enumerate which high-risk indicators triggered the suggestion
     * (used in the notification body).
     *
     * @return list<string>
     */
    private function highRiskReasons(ProcessingActivity $activity): array
    {
        $reasons = [];
        if (method_exists($activity, 'getProcessesSpecialCategories') && $activity->getProcessesSpecialCategories() === true) {
            $reasons[] = 'GDPR Art. 9 special categories';
        }
        if (method_exists($activity, 'getAutomatedDecisionMakingDetails')) {
            $admd = $activity->getAutomatedDecisionMakingDetails();
            if (!empty($admd)) {
                $reasons[] = 'Automated decision-making';
            }
        }
        if (method_exists($activity, 'getEstimatedDataSubjectsCount')
            && (int) $activity->getEstimatedDataSubjectsCount() > 1000) {
            $reasons[] = 'Large-scale processing (>1000 data subjects)';
        }
        // V3 W2-H5: Asset-classification-driven trigger
        if ($this->hasConfidentialAsset($activity)) {
            $reasons[] = 'Linked Asset with classification confidential/restricted';
        }
        // V3 W2-H5: vulnerable data-subject categories (Children/Employees/Patients)
        $vulnerable = $this->vulnerableSubjectCategories($activity);
        if (!empty($vulnerable)) {
            $reasons[] = 'Vulnerable data-subject categories: ' . implode(', ', $vulnerable);
        }
        return $reasons;
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
        // V3 W2-H5: Asset with confidential/restricted dataClassification.
        if ($this->hasConfidentialAsset($activity)) {
            return true;
        }
        // V3 W2-H5: Vulnerable data-subject categories
        // (Children/Employees/Patients per Art. 35 (3) GDPR + WP29 guidelines).
        if (!empty($this->vulnerableSubjectCategories($activity))) {
            return true;
        }
        return false;
    }

    /**
     * V3 W2-H5 — Inspect linked Assets (best-effort) and flag classifications
     * `confidential` and `restricted`. Reflects ISO 27001 A.5.12 / Asset.dataClassification.
     */
    private function hasConfidentialAsset(ProcessingActivity $activity): bool
    {
        if (!method_exists($activity, 'getAssets')) {
            return false;
        }
        $assets = $activity->getAssets();
        if ($assets === null) {
            return false;
        }
        foreach ($assets as $asset) {
            if (!is_object($asset) || !method_exists($asset, 'getDataClassification')) {
                continue;
            }
            $classification = strtolower((string) $asset->getDataClassification());
            if (in_array($classification, ['confidential', 'restricted'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * V3 W2-H5 — Detect vulnerable data-subject categories per
     * Art. 35 (3) GDPR + WP29 DPIA guidelines.
     *
     * @return list<string>
     */
    private function vulnerableSubjectCategories(ProcessingActivity $activity): array
    {
        if (!method_exists($activity, 'getDataSubjectCategories')) {
            return [];
        }
        $categories = (array) $activity->getDataSubjectCategories();
        $vulnerableMarkers = [
            'children', 'kinder', 'minor', 'minors', 'minderjährige',
            'employees', 'mitarbeiter', 'beschäftigte',
            'patients', 'patienten',
            'elderly', 'senioren',
            'vulnerable', 'schutzbedürftige',
        ];
        $hits = [];
        foreach ($categories as $category) {
            if (!is_string($category)) {
                continue;
            }
            $needle = strtolower($category);
            foreach ($vulnerableMarkers as $marker) {
                if (str_contains($needle, $marker)) {
                    $hits[] = $category;
                    break;
                }
            }
        }
        return $hits;
    }
}
