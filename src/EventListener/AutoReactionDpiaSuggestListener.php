<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\DataProtectionImpactAssessment;
use App\Entity\ProcessingActivity;
use App\Enum\DpiaStatus;
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\AutoReactionService;
use App\Service\EmailNotificationService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Audit V3 C3 — Auto-DPIA-Suggestion.
 *
 * On ProcessingActivity create/update: when high-risk indicators are present
 * (special categories, automated decision-making, large scale, confidential
 * assets, vulnerable data subjects) and no DPIA is yet linked, create a draft
 * DPIA skeleton.
 *
 * Collect, then create (same pattern as SlaDeadlineMonitorSpawner): postPersist
 * / postUpdate only remember the activity — no query, no persist, no flush
 * inside the running commit. The skeleton is built and flushed in postFlush,
 * after the activity is committed. It used to be flushed from postPersist:
 * that re-entrant commit failed on the missing reference number, CLOSED the
 * EntityManager and was swallowed — no DPIA, the activity's own audit entry
 * lost, and every later write of the request failed (#1059).
 *
 * The skeleton gets its reference number from the same generator as the
 * normal create path (DataProtectionImpactAssessmentRepository::
 * getNextReferenceNumber) and every NOT NULL field prefilled from the
 * activity, so its flush cannot fail on missing data. Each skeleton is
 * flushed on its own (the next reference number sees the previous one); a
 * failing flush is logged and a closed EntityManager reset.
 *
 * Toggle: AutoReactionService::KEY_DPIA_SUGGEST (default true).
 */
#[AsEntityListener(event: Events::postPersist, entity: ProcessingActivity::class)]
#[AsEntityListener(event: Events::postUpdate, entity: ProcessingActivity::class)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AutoReactionDpiaSuggestListener
{
    private const string PLACEHOLDER = 'To be completed — auto-suggested DPIA skeleton (Art. 35 (7) GDPR).';

    /** @var array<int, ProcessingActivity> activities waiting for postFlush, keyed by object id */
    private array $pending = [];

    /** Re-entrancy guard: the skeleton flush must not process its own postFlush. */
    private bool $creating = false;

    public function __construct(
        private readonly AutoReactionService $reactions,
        private readonly LoggerInterface $logger,
        private readonly ?EmailNotificationService $emailNotifier = null,
        private readonly ?UserRepository $userRepository = null,
        private readonly ?DataProtectionImpactAssessmentRepository $dpiaRepository = null,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function postPersist(ProcessingActivity $activity, PostPersistEventArgs $args): void
    {
        $this->collect($activity);
    }

    public function postUpdate(ProcessingActivity $activity, PostUpdateEventArgs $args): void
    {
        $this->collect($activity);
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->creating || $this->pending === []) {
            return;
        }

        $pending = $this->pending;
        $this->pending = [];
        $em = $args->getObjectManager();
        $this->creating = true;

        try {
            foreach ($pending as $activity) {
                if (!$em->isOpen()) {
                    break;
                }
                $this->suggestFor($activity, $em);
            }
        } finally {
            $this->creating = false;
        }
    }

    private function collect(ProcessingActivity $activity): void
    {
        if ($this->creating) {
            return;
        }
        if (!$this->reactions->isEnabled(AutoReactionService::KEY_DPIA_SUGGEST)) {
            return;
        }
        if (!$this->isHighRisk($activity)) {
            return;
        }
        $this->pending[spl_object_id($activity)] = $activity;
    }

    private function suggestFor(ProcessingActivity $activity, EntityManagerInterface $em): void
    {
        $dpia = null;
        try {
            if ($activity->getId() === null || $activity->getTenant() === null) {
                return;
            }
            // Already linked DPIA?
            $existing = $em->getRepository(DataProtectionImpactAssessment::class)
                ->findOneBy(['processingActivity' => $activity]);
            if ($existing instanceof DataProtectionImpactAssessment) {
                return;
            }

            $dpia = $this->buildSkeleton($activity, $em);
            $em->persist($dpia);
            $em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Auto-DPIA suggestion failed', [
                'processing_activity_id' => $activity->getId(),
                'error' => $e->getMessage(),
            ]);
            if (!$em->isOpen()) {
                $this->managerRegistry?->resetManager();
            } elseif ($dpia !== null && $em->contains($dpia)) {
                $em->detach($dpia);
            }

            return;
        }

        $this->logger->info('Auto-DPIA suggestion created', [
            'processing_activity_id' => $activity->getId(),
            'dpia_id' => $dpia->getId(),
        ]);
        $this->auditLogger?->logCreate(
            'DataProtectionImpactAssessment',
            $dpia->getId(),
            [
                'reference_number' => $dpia->getReferenceNumber(),
                'title' => $dpia->getTitle(),
                'processing_activity_id' => $activity->getId(),
            ],
            'DPIA auto-suggested: ' . $dpia->getReferenceNumber(),
        );

        // V3 W2-H4 (ISO 27001 Cl.7.4 / GDPR Art.35): Notify DPO so the
        // auto-suggested skeleton enters the DPO's queue immediately.
        $this->notifyDpo($activity, $dpia);
    }

    /**
     * Draft DPIA prefilled from the activity; every NOT NULL column is set.
     */
    private function buildSkeleton(ProcessingActivity $activity, EntityManagerInterface $em): DataProtectionImpactAssessment
    {
        $tenant = $activity->getTenant();
        $repository = $this->dpiaRepository ?? $em->getRepository(DataProtectionImpactAssessment::class);
        \assert($repository instanceof DataProtectionImpactAssessmentRepository && $tenant !== null);

        $dpia = new DataProtectionImpactAssessment();
        $dpia->setTenant($tenant);
        $dpia->setProcessingActivity($activity);
        $dpia->setReferenceNumber($repository->getNextReferenceNumber($tenant));
        $dpia->setTitle(sprintf(
            'DPIA Auto-Suggestion: %s (%s)',
            $activity->getName() ?? 'Processing-Activity',
            (new \DateTimeImmutable())->format('Y-m-d'),
        ));
        $dpia->setStatus(DpiaStatus::Draft); // @phpstan-ignore lifecycle.directSetStatus (auto-reaction listener — initial state on new pre-persist DPIA skeleton)
        $dpia->setProcessingDescription(
            'Auto-generated DPIA skeleton based on high-risk indicators: '
            . implode('; ', $this->highRiskReasons($activity))
            . '. Review and complete required sections (Art. 35 (7) GDPR).'
        );
        $purposes = array_filter(array_map(
            static fn (mixed $p): string => is_scalar($p) ? trim((string) $p) : '',
            $activity->getPurposes(),
        ));
        $dpia->setProcessingPurposes($purposes !== [] ? implode('; ', $purposes) : self::PLACEHOLDER);
        $dpia->setDataCategories($activity->getPersonalDataCategories() !== [] ? $activity->getPersonalDataCategories() : ['to_be_assessed']);
        $dpia->setDataSubjectCategories($activity->getDataSubjectCategories() !== [] ? $activity->getDataSubjectCategories() : ['to_be_assessed']);
        $dpia->setLegalBasis($activity->getLegalBasis() ?: self::PLACEHOLDER);
        $dpia->setNecessityAssessment(self::PLACEHOLDER);
        $dpia->setProportionalityAssessment(self::PLACEHOLDER);
        $dpia->setIdentifiedRisks([]);
        $dpia->setRiskLevel('high');
        $dpia->setTechnicalMeasures(self::PLACEHOLDER);
        $dpia->setOrganizationalMeasures(self::PLACEHOLDER);

        return $dpia;
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
     * V3 W2-H5 — Inspect linked Assets and flag classifications
     * `confidential` and `restricted`. Reflects ISO 27001 A.5.12 /
     * Asset.dataClassification.
     *
     * V3 W2-Bug3: ProcessingActivity↔Asset M:N relation now exists, so
     * the previous method_exists()-defence has been replaced with a
     * direct accessor call.
     */
    private function hasConfidentialAsset(ProcessingActivity $activity): bool
    {
        foreach ($activity->getAssets() as $asset) {
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
