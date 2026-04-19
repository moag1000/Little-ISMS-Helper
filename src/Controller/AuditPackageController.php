<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\Export\AuditPackageExporter;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MANAGER')]
class AuditPackageController extends AbstractController
{
    public function __construct(
        private readonly AuditPackageExporter $exporter,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/audit-package/{framework}', name: 'app_audit_package_export', requirements: ['framework' => '[A-Za-z0-9_.:-]+'])]
    public function export(string $framework): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('Tenant context required.');
        }

        $fw = $this->frameworkRepository->findOneBy(['code' => $framework]);
        if (!$fw instanceof ComplianceFramework) {
            throw $this->createNotFoundException(sprintf('Framework %s not found.', $framework));
        }

        $result = $this->exporter->export($fw, $tenant);

        $response = new BinaryFileResponse($result['path']);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $result['filename']
        );
        $response->deleteFileAfterSend(true);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('X-Audit-Package-SHA256', $result['sha256']);

        return $response;
    }
}
