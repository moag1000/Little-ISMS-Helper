<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\FourEyesApprovalRequest;
use App\Event\FourEyesApprovedEvent;
use App\Service\ComplianceInheritanceService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** S34 (R3b): an inheritance override takes effect only when the second person approved it. */
final class InheritanceOverrideApprovalSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ComplianceInheritanceService $inheritance,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [FourEyesApprovedEvent::class => 'onApproved'];
    }

    public function onApproved(FourEyesApprovedEvent $event): void
    {
        if ($event->request->getActionType() === FourEyesApprovalRequest::ACTION_MAPPING_OVERRIDE) {
            $this->inheritance->applyApprovedOverride($event->request, $event->approver);
        }
    }
}
