<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\ComplianceRequirementFulfillmentUpdatedEvent;
use App\Repository\FulfillmentInheritanceLogRepository;
use App\Repository\UserRepository;
use App\Service\ComplianceInheritanceService;
use App\Service\CompliancePolicyService;
use App\Service\EmailNotificationService;
use App\Doctrine\PostFlushWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * ENT-2: notification-based, no silent cascade. Marks derived inheritance logs
 * as source_updated so the reviewer sees the need for re-confirmation.
 */
#[AsEventListener(event: ComplianceRequirementFulfillmentUpdatedEvent::class)]
final class NotifyDerivedFulfillmentsListener
{
    public function __construct(
        private readonly FulfillmentInheritanceLogRepository $inheritanceRepository,
        private readonly ComplianceInheritanceService $inheritanceService,
        private readonly PostFlushWriter $postFlushWriter,
        private readonly EmailNotificationService $emailService,
        private readonly UserRepository $userRepository,
        private readonly LoggerInterface $logger,
        private readonly CompliancePolicyService $policy,
    ) {
    }

    public function __invoke(ComplianceRequirementFulfillmentUpdatedEvent $event): void
    {
        $threshold = $this->policy->getInt(CompliancePolicyService::KEY_SIGNIFICANT_CHANGE_THRESHOLD, 5);
        if (!$event->hasSignificantChange($threshold)) {
            return;
        }

        $derived = $this->inheritanceRepository->findDerivedFromSource($event->fulfillment);
        if ($derived === []) {
            return;
        }


        $notifyPerOwner = [];
        foreach ($derived as $log) {
            if ($log->getFulfillment()?->isDerived() === true) {
                continue; // a rule recomputes the target live — nothing to re-confirm
            }
            $this->inheritanceService->markSourceUpdated($log);
            $this->postFlushWriter->persist($log);

            $owner = $log->getReviewedBy() ?? $log->getFulfillment()?->getResponsiblePersonUser();
            if ($owner !== null) {
                $notifyPerOwner[$owner->getId()] ??= [
                    'user' => $owner,
                    'logs' => [],
                ];
                $notifyPerOwner[$owner->getId()]['logs'][] = $log;
            }
        }
        // Dispatched from preUpdate: a flush here would re-enter the running
        // commit (pitfall 1b) — the status change is written after the flush.

        foreach ($notifyPerOwner as $payload) {
            try {
                $this->emailService->sendGenericNotification(
                    subject: sprintf('[ISMS] Source-Fulfillment geändert — %d Ableitung(en) neu prüfen', count($payload['logs'])),
                    template: 'emails/inheritance_source_updated.html.twig',
                    context: [
                        'source_fulfillment' => $event->fulfillment,
                        'previous_percentage' => $event->previousPercentage,
                        'current_percentage' => $event->currentPercentage,
                        'logs' => $payload['logs'],
                    ],
                    recipients: [$payload['user']->getEmail()],
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to send source-updated notification', [
                    'user_id' => $payload['user']->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
