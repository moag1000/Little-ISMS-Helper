<?php

namespace App\Controller;

use App\Service\LicenseReportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LicenseController extends AbstractController
{
    #[Route('/about/licenses', name: 'app_licenses')]
    public function index(): Response
    {
        $noticeFile = $this->getParameter('kernel.project_dir') . '/NOTICE.md';

        if (!file_exists($noticeFile)) {
            throw new NotFoundHttpException('NOTICE.md file not found');
        }

        $noticeContent = file_get_contents($noticeFile);

        // Parse composer.json for project info
        $composerFile = $this->getParameter('kernel.project_dir') . '/composer.json';
        $composerData = json_decode(file_get_contents($composerFile), true);

        return $this->render('license/index.html.twig', [
            'notice_content' => $noticeContent,
            'project_name' => $composerData['description'] ?? 'Little ISMS Helper',
            'project_license' => $composerData['license'] ?? 'proprietary',
        ]);
    }

    #[Route('/about/licenses/report', name: 'app_licenses_report')]
    public function report(): Response
    {
        $reportFile = $this->getParameter('kernel.project_dir') . '/docs/reports/license-report.md';

        if (!file_exists($reportFile)) {
            // Try to generate it
            $this->addFlash('warning', 'License report not found. Please run "./license-report.sh" to generate it.');
            return $this->redirectToRoute('app_licenses');
        }

        $reportContent = file_get_contents($reportFile);

        return $this->render('license/report.html.twig', [
            'report_content' => $reportContent,
        ]);
    }

    #[Route('/about/licenses/summary', name: 'app_licenses_summary')]
    public function summary(): Response
    {
        $reportFile = $this->getParameter('kernel.project_dir') . '/docs/reports/license-report.md';

        $stats = [
            'total' => 0,
            'allowed' => 0,
            'restricted' => 0,
            'copyleft' => 0,
            'not_allowed' => 0,
            'unknown' => 0,
            'generated_at' => null,
        ];

        if (file_exists($reportFile)) {
            $content = file_get_contents($reportFile);

            // Extract statistics from markdown report
            if (preg_match('/\*\*Generiert am:\*\* (.+)/', $content, $matches)) {
                $stats['generated_at'] = $matches[1];
            }

            // Extract package counts from the summary table
            if (preg_match('/\| \*\*Gesamt\*\* \| \| \*\*(\d+)\*\* \| \*\*(\d+)\*\* \| \*\*(\d+)\*\* \| \*\*(\d+)\*\* \|/', $content, $matches)) {
                $stats['total'] = (int)$matches[1] + (int)$matches[2] + (int)$matches[3];
            }

            // Extract status counts
            if (preg_match('/Erlaubt<\/span>.*?\| \*\*(\d+)\*\* \|/s', $content, $matches)) {
                $stats['allowed'] = (int)$matches[1];
            }
            if (preg_match('/Eingeschränkt<\/span>.*?\| \*\*(\d+)\*\* \|/s', $content, $matches)) {
                $stats['restricted'] = (int)$matches[1];
            }
            if (preg_match('/Copyleft<\/span>.*?\| \*\*(\d+)\*\* \|/s', $content, $matches)) {
                $stats['copyleft'] = (int)$matches[1];
            }
            if (preg_match('/Nicht erlaubt<\/span>.*?\| \*\*(\d+)\*\* \|/s', $content, $matches)) {
                $stats['not_allowed'] = (int)$matches[1];
            }
            if (preg_match('/Unbekannt<\/span>.*?\| \*\*(\d+)\*\* \|/s', $content, $matches)) {
                $stats['unknown'] = (int)$matches[1];
            }
        }

        return $this->render('license/summary.html.twig', [
            'stats' => $stats,
        ]);
    }

    #[Route('/about/licenses/generate', name: 'app_licenses_generate', methods: ['POST'])]
    public function generate(LicenseReportService $licenseReportService): JsonResponse
    {
        try {
            $result = $licenseReportService->generateReport();

            if ($result['success']) {
                return $this->json([
                    'success' => true,
                    'message' => 'License report generated successfully',
                    'report' => $result['report'],
                ]);
            } else {
                return $this->json([
                    'success' => false,
                    'message' => 'License report generation failed',
                    'error' => $result['error_output'] ?: $result['output'],
                ], 500);
            }
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Error generating license report: ' . $e->getMessage(),
            ], 500);
        }
    }
}
