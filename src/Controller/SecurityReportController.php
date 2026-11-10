<?php

declare(strict_types=1);

namespace App\Controller;

use App\Job\GenerateSecurityAuditJob;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\Job\JobDispatcher;
use App\Service\Job\JobStatusService;
use App\Service\SecurityAuditService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class SecurityReportController extends AbstractController
{
    public function __construct(private readonly SecurityAuditService $securityAuditService)
    {
    }

    #[Route('/about/security', name: 'app_security_report', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $stats = [
            'owasp_2025' => $this->parseSecurityReport($this->securityAuditService->reportPath(SecurityAuditService::EDITION_2025)),
            'owasp_2021' => $this->parseSecurityReport($this->securityAuditService->reportPath(SecurityAuditService::EDITION_2021)),
        ];

        return $this->render('security/index.html.twig', [
            'stats' => $stats,
        ]);
    }

    #[Route('/about/security/owasp-2025', name: 'app_security_report_2025', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function owasp2025(Request $request): Response
    {
        $reportFile = $this->securityAuditService->reportPath(SecurityAuditService::EDITION_2025);

        if ($reportFile === null) {
            $this->addFlash('warning', 'OWASP 2025 Security Report not found. Please run "php scripts/generate-security-audit.php" to generate it.');
            return $this->redirectToRoute('app_security_report');
        }

        if ($request->query->has('download')) {
            return $this->downloadReport($reportFile, 'security-audit-owasp-2025.md');
        }

        return $this->render('security/report.html.twig', [
            'report_content' => file_get_contents($reportFile),
            'report_version' => '2025 RC1',
            'report_title' => 'OWASP Top 10:2025 RC1 Security Report',
        ]);
    }

    #[Route('/about/security/owasp-2021', name: 'app_security_report_2021', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function owasp2021(Request $request): Response
    {
        $reportFile = $this->securityAuditService->reportPath(SecurityAuditService::EDITION_2021);

        if ($reportFile === null) {
            $this->addFlash('warning', 'OWASP 2021 Security Report not found. Please run "php scripts/generate-security-audit.php" to generate it.');
            return $this->redirectToRoute('app_security_report');
        }

        if ($request->query->has('download')) {
            return $this->downloadReport($reportFile, 'security-audit-owasp-2021.md');
        }

        return $this->render('security/report.html.twig', [
            'report_content' => file_get_contents($reportFile),
            'report_version' => '2021',
            'report_title' => 'OWASP Top 10:2021 Security Report',
        ]);
    }

    /**
     * Regenerates the instance-wide security reports (S32): a SUPER_ADMIN operation, run as async job (the script
     * takes up to 300 s). The answer carries the job's status URL, never script output or paths.
     */
    #[IsGranted(TenantScopedAdminVoter::ADMIN_GLOBAL_OP)]
    #[Route('/about/security/generate', name: 'app_security_report_generate', methods: ['POST'])]
    #[IsCsrfTokenValid('report_regenerate', tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function generate(Request $request, JobStatusService $jobStatusService, JobDispatcher $jobDispatcher): JsonResponse
    {
        $jobId = $jobStatusService->create('security.audit_report', ['_label' => 'OWASP security reports']);
        $response = $this->json([
            'success' => true,
            'queued' => true,
            'status_url' => $this->generateUrl('admin_job_status', ['id' => $jobId]),
        ], Response::HTTP_ACCEPTED);
        $jobDispatcher->dispatch(GenerateSecurityAuditJob::class, [], $jobId, $response, $request->getSession());

        return $response;
    }

    private function downloadReport(string $file, string $downloadName): BinaryFileResponse
    {
        $response = new BinaryFileResponse($file);
        $response->headers->set('Content-Type', 'text/markdown; charset=UTF-8');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $downloadName);

        return $response;
    }

    private function parseSecurityReport(?string $filePath): array
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

        if ($filePath === null) {
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
