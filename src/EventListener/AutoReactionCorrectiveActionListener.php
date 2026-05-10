<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\AuditFinding;
use App\Entity\CorrectiveAction;
use App\Service\AutoReactionService;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Audit V3 C3 — Auto-CorrectiveAction on Major/Critical Audit-Finding.
 *
 * On AuditFinding postPersist/Update with severity in [major, critical, high]:
 * create a Corrective-Action skeleton with planned completion in 30 days,
 * status='planned', linked to the finding.
 *
 * Toggle: AutoReactionService::KEY_CA_ON_FINDING (default true).
 */
#[AsEntityListener(event: Events::postPersist, entity: AuditFinding::class)]
#[AsEntityListener(event: Events::postUpdate, entity: AuditFinding::class)]
class AutoReactionCorrectiveActionListener
{
    public function __construct(
        private readonly AutoReactionService $reactions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postPersist(AuditFinding $finding, PostPersistEventArgs $args): void
    {
        $this->maybeCreateCa($finding, $args);
    }

    public function postUpdate(AuditFinding $finding, PostUpdateEventArgs $args): void
    {
        $this->maybeCreateCa($finding, $args);
    }

    private function maybeCreateCa(AuditFinding $finding, mixed $args): void
    {
        if (!$this->reactions->isEnabled(AutoReactionService::KEY_CA_ON_FINDING)) {
            return;
        }

        $type = method_exists($finding, 'getType') ? $finding->getType() : null;
        $severity = method_exists($finding, 'getSeverity') ? $finding->getSeverity() : null;

        $isMajor = in_array($type, [AuditFinding::TYPE_MAJOR_NC], true)
            || in_array($severity, [AuditFinding::SEVERITY_CRITICAL, AuditFinding::SEVERITY_HIGH], true);

        if (!$isMajor) {
            return;
        }

        try {
            $em = $args->getObjectManager();

            // Already has a corrective action?
            $existing = $em->getRepository(CorrectiveAction::class)->findOneBy([
                'finding' => $finding,
            ]);
            if ($existing instanceof CorrectiveAction) {
                return;
            }

            $ca = new CorrectiveAction();
            $ca->setTenant(method_exists($finding, 'getTenant') ? $finding->getTenant() : null);
            $ca->setFinding($finding);
            $ca->setTitle('Corrective Action: ' . ($finding->getTitle() ?? '—'));
            if (method_exists($ca, 'setDescription')) {
                $ca->setDescription(
                    'Auto-generated corrective action skeleton for major/critical finding. Update root-cause analysis and target date.'
                );
            }
            if (method_exists($ca, 'setActionType')) {
                $ca->setActionType(CorrectiveAction::ACTION_TYPE_CORRECTIVE);
            }
            if (method_exists($ca, 'setPlannedCompletionDate')) {
                $ca->setPlannedCompletionDate(new DateTimeImmutable('+30 days'));
            }
            if (method_exists($ca, 'setStatus')) {
                $ca->setStatus(CorrectiveAction::STATUS_PLANNED);
            }

            $em->persist($ca);
            $em->flush();

            $this->logger->info('Auto-CorrectiveAction created for finding', [
                'finding_id' => $finding->getId(),
                'ca_id' => $ca->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-CorrectiveAction failed', [
                'finding_id' => $finding->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
