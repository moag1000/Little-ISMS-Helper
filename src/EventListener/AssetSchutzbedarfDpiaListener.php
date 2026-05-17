<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Asset;
use App\Entity\DataProtectionImpactAssessment;
use App\Repository\UserRepository;
use App\Service\AutoReactionService;
use App\Service\EmailNotificationService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Psr\Log\LoggerInterface;

/**
 * V3 W2-WS-10 — Asset-Schutzbedarf → DPIA-Bedarf-Check.
 *
 * BSI 200-2 / 3.6 + DSGVO Art. 35: when an Asset's protection requirement
 * is raised to "hoch" (4) or "sehr hoch" (5) on any of CIA, the linked
 * Processing-Activities (M:N) might newly require a DPIA. This listener
 * surfaces the suggestion to the DPO without auto-creating a DPIA — the
 * DPO decides whether the elevated Schutzbedarf actually changes the
 * GDPR risk picture.
 *
 * Trigger: Asset postUpdate, only when one of the CIA values RISES to
 * 4+ from below 4 (or to 5 from below 5). Pure same-state updates (e.g.,
 * already at 4 and stays at 4) do not re-fire to avoid notification
 * spam during routine maintenance.
 *
 * Toggled via the existing AutoReactionService::KEY_DPIA_SUGGEST flag —
 * when DPO opted out of auto-DPIA-suggestion globally, this listener
 * stays silent too.
 */
#[AsEntityListener(event: Events::postUpdate, entity: Asset::class)]
final class AssetSchutzbedarfDpiaListener
{
    public function __construct(
        private readonly AutoReactionService $reactions,
        private readonly LoggerInterface $logger,
        private readonly ?EmailNotificationService $emailNotifier = null,
        private readonly ?UserRepository $userRepository = null,
    ) {
    }

    public function postUpdate(Asset $asset, PostUpdateEventArgs $args): void
    {
        if (!$this->reactions->isEnabled(AutoReactionService::KEY_DPIA_SUGGEST)) {
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();
        if (!$uow instanceof UnitOfWork) {
            return;
        }
        $changes = $uow->getEntityChangeSet($asset);
        $relevantFields = ['confidentialityValue', 'integrityValue', 'availabilityValue'];
        $rose = false;
        foreach ($relevantFields as $field) {
            if (!isset($changes[$field])) {
                continue;
            }
            [$old, $new] = $changes[$field];
            $oldI = (int) $old;
            $newI = (int) $new;
            if ($newI >= 4 && $newI > $oldI) {
                $rose = true;
                break;
            }
        }
        if (!$rose) {
            return;
        }

        try {
            $activities = method_exists($asset, 'getProcessingActivities')
                ? $asset->getProcessingActivities()
                : null;
            if ($activities === null || $activities->isEmpty()) {
                return;
            }

            // Find the PAs that have NO DPIA linked yet.
            $em = $args->getObjectManager();
            $dpiaRepo = $em->getRepository(DataProtectionImpactAssessment::class);
            $pasNeedingDpia = [];
            foreach ($activities as $activity) {
                $existing = $dpiaRepo->findOneBy(['processingActivity' => $activity]);
                if (!$existing instanceof DataProtectionImpactAssessment) {
                    $pasNeedingDpia[] = $activity;
                }
            }
            if ($pasNeedingDpia === []) {
                return;
            }

            $this->logger->info('Asset Schutzbedarf raised — DPIA-Bedarf-Check fired', [
                'asset_id' => $asset->getId(),
                'pa_without_dpia_count' => count($pasNeedingDpia),
                'cia' => sprintf(
                    'C=%d I=%d A=%d',
                    (int) $asset->getConfidentialityValue(),
                    (int) $asset->getIntegrityValue(),
                    (int) $asset->getAvailabilityValue(),
                ),
            ]);

            $this->notifyDpo($asset, $pasNeedingDpia);
        } catch (\Throwable $e) {
            $this->logger->warning('Asset Schutzbedarf DPIA-Check failed', [
                'asset_id' => $asset->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param list<\App\Entity\ProcessingActivity> $pasNeedingDpia
     */
    private function notifyDpo(Asset $asset, array $pasNeedingDpia): void
    {
        if ($this->emailNotifier === null || $this->userRepository === null) {
            return;
        }
        try {
            $tenant = $asset->getTenant();
            $dpos = $this->userRepository->findByRoleInTenant('ROLE_DPO', $tenant);
            if (empty($dpos)) {
                return;
            }
            $this->emailNotifier->sendGenericNotification(
                sprintf('Asset Schutzbedarf raised — DPIA-Bedarf for %d PA(s)', count($pasNeedingDpia)),
                'emails/asset_schutzbedarf_dpia_check.html.twig',
                [
                    'asset' => $asset,
                    'activities' => $pasNeedingDpia,
                ],
                $dpos,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('DPO notification for Schutzbedarf-DPIA-Check failed', [
                'asset_id' => $asset->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
