<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SecurityReportController extends AbstractController
{
    #[Route('/about/security', name: 'app_security_report')]
    public function index(): Response
    {
        $report2025File = $this->getParameter('kernel.project_dir') . '/docs/reports/security-audit-owasp-2025-rc1.md';
        $report2021File = $this->getParameter('kernel.project_dir') . '/docs/reports/security-audit-owasp-2021.md';

        $stats = [
            'owasp_2025' => $this->parseSecurityReport($report2025File),
            'owasp_2021' => $this->parseSecurityReport($report2021File),
        ];

        return $this->render('security/index.html.twig', [
            'stats' => $stats,
        ]);
    }

    #[Route('/about/security/owasp-2025', name: 'app_security_report_2025')]
    public function owasp2025(): Response
    {
        $reportFile = $this->getParameter('kernel.project_dir') . '/docs/reports/security-audit-owasp-2025-rc1.md';

        if (!file_exists($reportFile)) {
            $this->addFlash('warning', 'OWASP 2025 Security Report not found. Please run "php scripts/generate-security-audit.php" to generate it.');
            return $this->redirectToRoute('app_security_report');
        }

        $reportContent = file_get_contents($reportFile);

        return $this->render('security/report.html.twig', [
            'report_content' => $reportContent,
            'report_version' => '2025 RC1',
            'report_title' => 'OWASP Top 10:2025 RC1 Security Report',
        ]);
    }

    #[Route('/about/security/owasp-2021', name: 'app_security_report_2021')]
    public function owasp2021(): Response
    {
        $reportFile = $this->getParameter('kernel.project_dir') . '/docs/reports/security-audit-owasp-2021.md';

        if (!file_exists($reportFile)) {
            $this->addFlash('warning', 'OWASP 2021 Security Report not found. Please run "php scripts/generate-security-audit.php" to generate it.');
            return $this->redirectToRoute('app_security_report');
        }

        $reportContent = file_get_contents($reportFile);

        return $this->render('security/report.html.twig', [
            'report_content' => $reportContent,
            'report_version' => '2021',
            'report_title' => 'OWASP Top 10:2021 Security Report',
        ]);
    }

    private function parseSecurityReport(string $filePath): array
    {
        $stats = [
            'exists' => false,
            'score' => 0.0,
            'status' => 'UNBEKANNT',
            'critical_count' => 0,
            'high_count' => 0,
            'medium_count' => 0,
            'generated_at' => null,
        ];

        if (!file_exists($filePath)) {
            return $stats;
        }

        $stats['exists'] = true;
        $content = file_get_contents($filePath);

        // Extract overall score
        if (preg_match('/\*\*Gesamtbewertung:\*\*\s+([\d.]+)\/10\s+\(([^)]+)\)/i', $content, $matches)) {
            $stats['score'] = (float)$matches[1];
            $stats['status'] = $matches[2];
        }

        // Extract date
        if (preg_match('/\*\*Berichtsdatum:\*\*\s+(.+)/', $content, $matches)) {
            $stats['generated_at'] = $matches[1];
        }

        // Count findings by severity
        $stats['critical_count'] = preg_match_all('/####\s*\[P0-URGENT\]/i', $content);
        $stats['high_count'] = preg_match_all('/####\s*\[P1-HIGH\]/i', $content);
        $stats['medium_count'] = preg_match_all('/####\s*\[P2-MEDIUM\]/i', $content);

        return $stats;
    }
}
