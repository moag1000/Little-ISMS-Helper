<?php

namespace App\MessageHandler\Schedule;

use App\Message\Schedule\GenerateComplianceReportMessage;
use App\Repository\ComplianceRequirementRepository;
use App\Service\EmailNotificationService;
use DateTime;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles generation of weekly compliance reports
 *
 * Generates summary reports and sends to compliance team
 */
#[AsMessageHandler]
class GenerateComplianceReportHandler
{
    public function __construct(
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly EmailNotificationService $emailService,
        private readonly LoggerInterface $logger
    ) {}

    public function __invoke(GenerateComplianceReportMessage $message): void
    {
        $this->logger->info('Starting compliance report generation', [
            'scheduled_at' => $message->getScheduledAt()->format('Y-m-d H:i:s'),
        ]);

        try {
            // Calculate compliance statistics
            $totalRequirements = $this->requirementRepository->count([]);
            $compliantRequirements = $this->requirementRepository->countCompliant();
            $compliancePercentage = $totalRequirements > 0
                ? round(($compliantRequirements / $totalRequirements) * 100, 2)
                : 0;

            $reportData = [
                'generated_at' => new DateTime(),
                'total_requirements' => $totalRequirements,
                'compliant_requirements' => $compliantRequirements,
                'compliance_percentage' => $compliancePercentage,
                'non_compliant_requirements' => $totalRequirements - $compliantRequirements,
            ];

            $this->logger->info('Compliance report generated', $reportData);

            // TODO: Send report email to compliance team
            // For now, just log the report
            // In production, you would send this via email to CISO/Compliance Manager

        } catch (Exception $e) {
            $this->logger->error('Failed to generate compliance report', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
