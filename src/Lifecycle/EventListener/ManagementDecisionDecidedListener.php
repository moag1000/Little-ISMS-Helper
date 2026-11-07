<?php

declare(strict_types=1);

namespace App\Lifecycle\EventListener;

use App\Entity\ManagementDecision;
use App\Entity\User;
use App\Service\ManagementDecision\ManagementDecisionService;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Writes the evidence of a top-management decision: who decided, when, with
 * which comment (ISO 27001 5.1, 9.3.3; NIS-2 Art. 20). Whatever path applies
 * approve / reject — the decision dialog or the generic lifecycle endpoint —
 * the record carries the same fields. The audit log entry comes from
 * AuditLogListener.
 */
#[AsEventListener(event: 'workflow.management_decision_lifecycle.completed', method: 'onCompleted')]
final class ManagementDecisionDecidedListener
{
    public function __construct(
        private readonly ManagementDecisionService $decisions,
    ) {
    }

    public function onCompleted(CompletedEvent $event): void
    {
        $decision = $event->getSubject();
        $transition = $event->getTransition()?->getName();
        if (!$decision instanceof ManagementDecision || !in_array($transition, ['approve', 'reject'], true)) {
            return;
        }

        $context = $event->getContext();
        $user = $context['user'] ?? null;
        $reason = $context['reason'] ?? null;

        $decision->setDecidedBy($user instanceof User ? $user : null)
            ->setDecidedAt(new DateTimeImmutable())
            ->setDecisionComment(is_string($reason) && trim($reason) !== '' ? trim($reason) : null);

        if ($transition === 'approve') {
            $this->decisions->afterApproval($decision, $user instanceof User ? $user : null);
        }
    }
}
