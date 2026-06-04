<?php

declare(strict_types=1);

namespace App\Lifecycle\EventListener;

use App\Entity\CorrectiveAction;
use App\Enum\CorrectiveActionStatus;
use App\Service\AutoReactionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Junior-ISB-Audit C4-01 — Auto-Eskalation auf
 * `corrective_action_lifecycle.verify_ineffective`.
 *
 * ISO 27001 Cl. 10.1 (d) verlangt bei unwirksamen Korrekturmaßnahmen
 * eine erneute Bewertung und Behandlung. Die `AlvaHint`-Regel
 * {@see \App\AlvaHint\Rule\CorrectiveAction\IneffectiveFollowUpRequiredRule}
 * surfacet das auf der Show-View, aber ein deterministischer Auto-Hook
 * stellt sicher, dass die Folge-CAPA auch dann existiert, wenn der
 * Benutzer die Show-Seite nie öffnet (Re-CAPA-Loop "by design").
 *
 * Idempotenz: prüft via Repository, ob bereits eine CAPA mit
 * `previousCapa == $this` existiert, bevor sie eine neue anlegt. Damit
 * sind Replays + Test-Doppeltransitionen unproblematisch.
 *
 * Toggle: {@see AutoReactionService::KEY_RECAPA_ON_INEFFECTIVE} (Default ON).
 *
 * Wird per `workflow.completed`-Event aufgerufen. Filter auf
 * `corrective_action_lifecycle` + Transition `verify_ineffective` —
 * andere Transitions (verify_effective, retry etc.) sind No-Ops.
 *
 * Priority 10 — lower than AuditLogListener (50) and AlvaHintInvalidator (30)
 * so the status-change is already logged when we spawn the Folge-CAPA.
 */
#[AsEventListener(event: 'workflow.completed', method: 'onCompleted', priority: 10)]
final class AutoReactionRecapaOnIneffectiveListener
{
    private const WORKFLOW_NAME = 'corrective_action_lifecycle';
    private const TRIGGER_TRANSITION = 'verify_ineffective';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AutoReactionService $reactions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onCompleted(CompletedEvent $event): void
    {
        if ($event->getWorkflowName() !== self::WORKFLOW_NAME) {
            return;
        }
        if ($event->getTransition()->getName() !== self::TRIGGER_TRANSITION) {
            return;
        }

        $subject = $event->getSubject();
        if (!$subject instanceof CorrectiveAction) {
            return;
        }

        if (!$this->reactions->isEnabled(AutoReactionService::KEY_RECAPA_ON_INEFFECTIVE)) {
            return;
        }

        try {
            $existing = $this->entityManager
                ->getRepository(CorrectiveAction::class)
                ->findOneBy(['previousCapa' => $subject]);
            if ($existing instanceof CorrectiveAction) {
                // Re-CAPA-loop bereits geschlossen — idempotent skip.
                return;
            }

            $followUp = new CorrectiveAction();
            $followUp->setTenant($subject->getTenant());
            if ($subject->getFinding() !== null) {
                $followUp->setFinding($subject->getFinding());
            }
            $followUp->setTitle(sprintf(
                'Folge-CAPA: %s',
                (string) ($subject->getTitle() ?? '—'),
            ));
            $followUp->setDescription(sprintf(
                'Auto-generated re-CAPA after the predecessor CAPA #%d was verified ineffective '
                . '(ISO 27001 Cl. 10.1 d). Review the root-cause analysis and adjust the '
                . 'remediation plan before transitioning to `in_progress`.',
                $subject->getId() ?? 0,
            ));
            $followUp->setActionType(CorrectiveAction::ACTION_TYPE_CORRECTIVE);
            $followUp->setPreviousCapa($subject);
            $followUp->setStatus(CorrectiveActionStatus::Planned); // @phpstan-ignore lifecycle.directSetStatus (initial state on a pre-persist entity; matches corrective_action_lifecycle.initial_marking)

            $this->entityManager->persist($followUp);
            $this->entityManager->flush();

            $this->logger->info('Auto re-CAPA created after verify_ineffective transition', [
                'original_ca_id' => $subject->getId(),
                'followup_ca_id' => $followUp->getId(),
            ]);
        } catch (\Throwable $e) {
            // Re-CAPA failure must not block the verify_ineffective transition
            // itself — the AlvaHint rule will surface the gap on the next render.
            $this->logger->warning('Auto re-CAPA failed', [
                'original_ca_id' => method_exists($subject, 'getId') ? $subject->getId() : null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
