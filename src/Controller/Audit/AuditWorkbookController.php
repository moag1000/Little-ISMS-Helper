<?php

declare(strict_types=1);

namespace App\Controller\Audit;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\Audit\AuditWorkbookGenerator;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AuditWorkbookController — F40.3
 *
 * Serves XLSX audit-workbook downloads for ISO 27001 chain-of-custody auditor handover.
 * Each download is logged via AuditLogger::logExport() for compliance traceability.
 *
 * Export types:
 *   - SoA (Statement of Applicability) — per compliance framework
 *   - Control Implementation — full tenant control register
 *   - Compliance Fulfillment — per compliance framework
 *   - Risk Register — full tenant risk register
 */
#[Route('/audit-workbook', name: 'app_audit_workbook_')]
#[IsGranted('ROLE_AUDITOR')]
class AuditWorkbookController extends AbstractController
{
    public function __construct(
        private readonly AuditWorkbookGenerator $generator,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly EntityManagerInterface $em,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
    ) {}

    /**
     * Index page — lists all available audit-workbook export types.
     */
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $frameworks = $this->frameworkRepository->findBy(['active' => true], ['name' => 'ASC']);

        return $this->render('audit/workbook/index.html.twig', [
            'frameworks' => $frameworks,
            'supported_export_types' => $this->generator->getSupportedExportTypes(),
        ]);
    }

    /**
     * Stream SoA XLSX for a specific compliance framework.
     * Logs an export audit-chain entry for chain-of-custody.
     */
    #[Route('/soa/{frameworkId}.xlsx', name: 'soa', requirements: ['frameworkId' => '\d+'], methods: ['GET'])]
    public function soa(int $frameworkId): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return $this->json(['error' => 'No tenant context active.'], Response::HTTP_FORBIDDEN);
        }

        $framework = $this->frameworkRepository->find($frameworkId);
        if (!$framework instanceof ComplianceFramework || !$framework->isActive()) {
            throw $this->createNotFoundException('Compliance framework not found or not active.');
        }

        try {
            $date = (new \DateTimeImmutable())->format('Y-m-d');
            $filename = sprintf(
                'soa-%s-%s.xlsx',
                preg_replace('/[^a-zA-Z0-9_-]/', '-', $framework->getName() ?? 'framework'),
                $date
            );

            $this->auditLogger->logExport(
                'SoA',
                $frameworkId,
                sprintf('SoA workbook downloaded for framework "%s" (ID %d)', $framework->getName(), $frameworkId)
            );

            return $this->generator->streamToResponse(
                'soa',
                $tenant,
                ['framework' => $framework],
                $filename
            );
        } catch (\Throwable $e) {
            $this->auditLogger->logCustom(
                'audit_workbook.failed',
                'AuditWorkbook',
                null,
                null,
                ['exportType' => 'soa', 'error' => $e->getMessage()]
            );

            return $this->json(
                ['error' => 'Failed to generate SoA workbook. Please try again.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Stream Control Implementation XLSX for the full tenant.
     * Logs an export audit-chain entry for chain-of-custody.
     */
    #[Route('/control-implementation.xlsx', name: 'control_implementation', methods: ['GET'])]
    public function controlImplementation(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return $this->json(['error' => 'No tenant context active.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $date = (new \DateTimeImmutable())->format('Y-m-d');
            $filename = sprintf('control-implementation-%s.xlsx', $date);

            $this->auditLogger->logExport(
                'ControlImplementation',
                null,
                'Control implementation workbook downloaded'
            );

            return $this->generator->streamToResponse(
                'control-implementation',
                $tenant,
                [],
                $filename
            );
        } catch (\Throwable $e) {
            $this->auditLogger->logCustom(
                'audit_workbook.failed',
                'AuditWorkbook',
                null,
                null,
                ['exportType' => 'control-implementation', 'error' => $e->getMessage()]
            );

            return $this->json(
                ['error' => 'Failed to generate control implementation workbook. Please try again.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Stream Compliance Fulfillment XLSX for a specific compliance framework.
     * Logs an export audit-chain entry for chain-of-custody.
     */
    #[Route('/compliance/{frameworkId}.xlsx', name: 'compliance', requirements: ['frameworkId' => '\d+'], methods: ['GET'])]
    public function compliance(int $frameworkId): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return $this->json(['error' => 'No tenant context active.'], Response::HTTP_FORBIDDEN);
        }

        $framework = $this->frameworkRepository->find($frameworkId);
        if (!$framework instanceof ComplianceFramework || !$framework->isActive()) {
            throw $this->createNotFoundException('Compliance framework not found or not active.');
        }

        try {
            $date = (new \DateTimeImmutable())->format('Y-m-d');
            $filename = sprintf(
                'compliance-%s-%s.xlsx',
                preg_replace('/[^a-zA-Z0-9_-]/', '-', $framework->getName() ?? 'framework'),
                $date
            );

            $this->auditLogger->logExport(
                'ComplianceFulfillment',
                $frameworkId,
                sprintf('Compliance fulfillment workbook downloaded for framework "%s" (ID %d)', $framework->getName(), $frameworkId)
            );

            return $this->generator->streamToResponse(
                'compliance-fulfillment',
                $tenant,
                ['framework' => $framework],
                $filename
            );
        } catch (\Throwable $e) {
            $this->auditLogger->logCustom(
                'audit_workbook.failed',
                'AuditWorkbook',
                null,
                null,
                ['exportType' => 'compliance-fulfillment', 'error' => $e->getMessage()]
            );

            return $this->json(
                ['error' => 'Failed to generate compliance fulfillment workbook. Please try again.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Stream Risk Register XLSX for the full tenant.
     * Logs an export audit-chain entry for chain-of-custody.
     */
    #[Route('/risk-register.xlsx', name: 'risk_register', methods: ['GET'])]
    public function riskRegister(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return $this->json(['error' => 'No tenant context active.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $date = (new \DateTimeImmutable())->format('Y-m-d');
            $filename = sprintf('risk-register-%s.xlsx', $date);

            $this->auditLogger->logExport(
                'RiskRegister',
                null,
                'Risk register workbook downloaded'
            );

            return $this->generator->streamToResponse(
                'risk-register',
                $tenant,
                [],
                $filename
            );
        } catch (\Throwable $e) {
            $this->auditLogger->logCustom(
                'audit_workbook.failed',
                'AuditWorkbook',
                null,
                null,
                ['exportType' => 'risk-register', 'error' => $e->getMessage()]
            );

            return $this->json(
                ['error' => 'Failed to generate risk register workbook. Please try again.'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
