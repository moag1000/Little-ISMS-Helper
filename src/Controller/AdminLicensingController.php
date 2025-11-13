<?php

namespace App\Controller;

use App\Service\LicenseReportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin wrapper for License Management
 * Integrates existing license functionality into admin panel
 */
#[Route('/admin/licensing')]
class AdminLicensingController extends AbstractController
{
    /**
     * License Overview - Third-Party Licenses
     */
    #[Route('', name: 'admin_licensing_index', methods: ['GET'])]
    #[IsGranted('ADMIN_VIEW')]
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

        return $this->render('admin/licensing/index.html.twig', [
            'notice_content' => $noticeContent,
            'project_name' => $composerData['description'] ?? 'Little ISMS Helper',
            'project_license' => $composerData['license'] ?? 'proprietary',
        ]);
    }

    /**
     * License Report - Detailed License Analysis
     */
    #[Route('/report', name: 'admin_licensing_report', methods: ['GET'])]
    #[IsGranted('ADMIN_VIEW')]
    public function report(): Response
    {
        $reportFile = $this->getParameter('kernel.project_dir') . '/docs/reports/license-report.md';

        if (!file_exists($reportFile)) {
            // Try to generate it
            $this->addFlash('warning', 'License report not found. Please generate it using the button below.');
            return $this->redirectToRoute('admin_licensing_index');
        }

        $reportContent = file_get_contents($reportFile);

        return $this->render('admin/licensing/report.html.twig', [
            'report_content' => $reportContent,
        ]);
    }

    /**
     * License Summary - Statistics Overview
     */
    #[Route('/summary', name: 'admin_licensing_summary', methods: ['GET'])]
    #[IsGranted('ADMIN_VIEW')]
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

        $licenseBreakdown = [];

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

            // Extract license breakdown
            if (preg_match_all('/\| ([A-Z\-0-9\.]+) \| (\d+) \|/i', $content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if ($match[1] !== '**Gesamt**' && !in_array($match[1], ['Erlaubt', 'Eingeschränkt', 'Copyleft', 'Nicht erlaubt', 'Unbekannt'])) {
                        $licenseBreakdown[$match[1]] = (int)$match[2];
                    }
                }
            }
        }

        return $this->render('admin/licensing/summary.html.twig', [
            'stats' => $stats,
            'license_breakdown' => $licenseBreakdown,
        ]);
    }

    /**
     * Generate License Report
     */
    #[Route('/generate', name: 'admin_licensing_generate', methods: ['POST'])]
    #[IsGranted('ADMIN_VIEW')]
    public function generate(LicenseReportService $licenseReportService): JsonResponse
    {
        try {
            $result = $licenseReportService->generateReport();

            if ($result['success']) {
                $this->addFlash('success', 'License report generated successfully');

                return $this->json([
                    'success' => true,
                    'message' => 'License report generated successfully',
                    'report' => $result['report'],
                ]);
            } else {
                $this->addFlash('error', 'License report generation failed');

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
