<?php

namespace App\MessageHandler\Schedule;

use App\Message\Schedule\CheckRiskReviewsMessage;
use App\Repository\RiskRepository;
use App\Service\EmailNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles periodic risk review checks
 *
 * ISO 27001:2022 Clause 6.1.3.d compliance
 * Identifies risks due for review and sends notifications
 */
#[AsMessageHandler]
class CheckRiskReviewsHandler
{
    public function __construct(
        private readonly RiskRepository $riskRepository,
        private readonly EmailNotificationService $emailService,
        private readonly LoggerInterface $logger
    ) {}

    public function __invoke(CheckRiskReviewsMessage $message): void
    {
        $this->logger->info('Starting risk review check', [
            'scheduled_at' => $message->getScheduledAt()->format('Y-m-d H:i:s'),
        ]);

        try {
            // Find risks due for review (e.g., nextReviewDate <= today)
            $risksDueForReview = $this->riskRepository->findDueForReview(new \DateTime());

            if (empty($risksDueForReview)) {
                $this->logger->info('No risks due for review');
                return;
            }

            $this->logger->info('Found risks due for review', [
                'count' => count($risksDueForReview),
            ]);

            // Send notifications to risk owners
            foreach ($risksDueForReview as $risk) {
                $owner = $risk->getOwner();

                if (!$owner) {
                    $this->logger->warning('Risk has no owner assigned', [
                        'risk_id' => $risk->getId(),
                        'risk_title' => $risk->getTitle(),
                    ]);
                    continue;
                }

                try {
                    $this->emailService->sendEmail(
                        $owner->getEmail(),
                        'Risk Review Required: ' . $risk->getTitle(),
                        'emails/risk_review_notification.html.twig',
                        [
                            'risk' => $risk,
                            'owner' => $owner,
                            'due_date' => $risk->getNextReviewDate(),
                        ]
                    );

                    $this->logger->info('Sent risk review notification', [
                        'risk_id' => $risk->getId(),
                        'owner_email' => $owner->getEmail(),
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to send risk review notification', [
                        'risk_id' => $risk->getId(),
                        'owner_email' => $owner->getEmail(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('Risk review check completed', [
                'risks_processed' => count($risksDueForReview),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to check risk reviews', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
