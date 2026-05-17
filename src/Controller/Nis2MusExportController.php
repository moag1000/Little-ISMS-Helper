<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Incident;
use App\Service\Nis2MusExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Download endpoints for the BSI MUS (Meldeumgebung) NIS2 Article 23
 * incident-reporting payloads in JSON form.
 */
#[IsGranted('ROLE_MANAGER')]
class Nis2MusExportController extends AbstractController
{
    public function __construct(
        private readonly Nis2MusExportService $nis2MusExportService,
    ) {
    }

    #[Route(
        '/nis2/mus-export/{id}/early-warning',
        name: 'app_nis2_mus_export_early_warning',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function earlyWarning(Incident $incident): JsonResponse
    {
        return $this->jsonDownload(
            $this->nis2MusExportService->buildEarlyWarningPayload($incident),
            $incident,
            'early-warning',
        );
    }

    #[Route(
        '/nis2/mus-export/{id}/detailed-notification',
        name: 'app_nis2_mus_export_detailed_notification',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function detailedNotification(Incident $incident): JsonResponse
    {
        return $this->jsonDownload(
            $this->nis2MusExportService->buildDetailedNotificationPayload($incident),
            $incident,
            'detailed-notification',
        );
    }

    #[Route(
        '/nis2/mus-export/{id}/final-report',
        name: 'app_nis2_mus_export_final_report',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function finalReport(Incident $incident): JsonResponse
    {
        return $this->jsonDownload(
            $this->nis2MusExportService->buildFinalReportPayload($incident),
            $incident,
            'final-report',
        );
    }

    #[Route(
        '/nis2/mus-export/{id}/bundle',
        name: 'app_nis2_mus_export_bundle',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function bundle(Incident $incident): JsonResponse
    {
        $payload = [
            'early_warning' => $this->nis2MusExportService->buildEarlyWarningPayload($incident),
            'detailed_notification' => $this->nis2MusExportService->buildDetailedNotificationPayload($incident),
            'final_report' => $this->nis2MusExportService->buildFinalReportPayload($incident),
            'deadlines' => $this->nis2MusExportService->getDeadlineStatus($incident),
        ];

        return $this->jsonDownload($payload, $incident, 'bundle');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonDownload(array $payload, Incident $incident, string $phase): JsonResponse
    {
        $response = new JsonResponse($payload);
        $response->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $filename = sprintf(
            'nis2-mus-%s-%s.json',
            $phase,
            $incident->getIncidentNumber() ?? (string) $incident->getId(),
        );
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }
}
