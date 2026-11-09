<?php

declare(strict_types=1);

namespace App\Controller\GateFixture;

use App\Attribute\RequiresModule;
use App\Service\ModuleConfigurationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Gate 11 fixture (download pass, S32): every `expect:` line is a mutation the rule must report. */
#[Route('/export')]
final class RecordExportController extends AbstractController
{
    private const ENTITY_MODULES = ['risk' => 'risks', 'asset' => 'assets'];

    public function __construct(private readonly ModuleConfigurationService $modules)
    {
    }

    #[Route('/risks/pdf', name: 'app_fixture_risks_pdf')]
    public function risksPdf(): Response // expect: gate11.route
    {
        return new Response();
    }

    #[Route('/risks/excel', name: 'app_fixture_risks_excel')]
    #[RequiresModule('risks')]
    public function risksExcel(): Response
    {
        return new Response();
    }

    #[Route('/assets/csv', name: 'app_fixture_assets_csv')]
    #[RequiresModule('incidents')]
    public function assetsWrongModule(): Response // expect: gate11.route
    {
        return new Response();
    }

    #[Route('/{entityType}.xlsx', name: 'app_fixture_entity', requirements: ['entityType' => 'risk|asset'])]
    public function entityUngated(string $entityType): Response // expect: gate11.route
    {
        return new Response($entityType);
    }

    #[Route('/{entityType}.csv', name: 'app_fixture_entity_gated', requirements: ['entityType' => 'risk|asset'])]
    public function entityGated(string $entityType): Response
    {
        if (!$this->modules->isModuleActive(self::ENTITY_MODULES[$entityType])) {
            throw $this->createNotFoundException();
        }

        return new Response($entityType);
    }

    #[Route('/risk-appetite/pdf', name: 'app_fixture_appetite_pdf')]
    #[RequiresModule('risk_appetite')]
    public function appetitePdf(): Response
    {
        return new Response();
    }

    #[Route('/audit-log/csv', name: 'app_fixture_audit_log_csv')]
    public function auditLogCsv(): Response
    {
        return new Response();
    }
}
