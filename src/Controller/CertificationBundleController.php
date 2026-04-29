<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Export\CertificationBundleExporter;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Certification Bundle Controller
 *
 * Provides a one-click export of all ISMS documentation required for
 * ISO 27001 certification audits. The bundle includes SoA, risk treatment
 * plans, asset register, evidence documents, and gap analysis.
 */
#[Route('/certification-bundle', name: 'app_certification_bundle_')]
#[IsGranted('ROLE_MANAGER')]
class CertificationBundleController extends AbstractController
{
    public function __construct(
        private readonly CertificationBundleExporter $exporter,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
    ) {
    }

    /**
     * Preview page showing bundle contents and counts before generation.
     */
    #[Route('', name: 'index')]
    public function index(): Response
    {
        $tenant = $this->security->getUser()?->getTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant context available.');
        }

        $counts = $this->exporter->getPreviewCounts($tenant);

        return $this->render('certification_bundle/index.html.twig', [
            'tenant' => $tenant,
            'counts' => $counts,
        ]);
    }

    /**
     * Generate and download the certification bundle ZIP.
     */
    #[Route('/export', name: 'export', methods: ['POST'])]
    public function export(Request $request): BinaryFileResponse
    {
        $submittedToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('certification_bundle_export', $submittedToken)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $tenant = $this->security->getUser()?->getTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant context available.');
        }

        $result = $this->exporter->export($tenant);

        $response = new BinaryFileResponse($result['path']);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $result['filename']
        );
        $response->deleteFileAfterSend(true);

        $this->addFlash('success', 'certification_bundle.success');

        return $response;
    }
}
