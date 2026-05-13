<?php

declare(strict_types=1);

namespace App\Controller\Admin\SystemSettings;

use App\Entity\Tenant;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fiscal year & reporting cadence settings.
 * financialYearStartMonth stored in Tenant column.
 * quarterlyGrouping + reportingCadence stored in Tenant.settings['fiscal'] sub-key.
 */
#[Route('/admin/settings/fiscal-year')]
#[IsGranted('ROLE_ADMIN')]
class FiscalYearController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'admin_settings_fiscal_year', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $tenant = $this->requireTenant();

        $settings = $tenant->getSettings() ?? [];
        $fiscal = $settings['fiscal'] ?? [];

        $current = [
            'financial_year_start_month' => $tenant->getFinancialYearStartMonth() ?? 1,
            'quarterly_grouping'         => (bool) ($fiscal['quarterly_grouping'] ?? false),
            'reporting_cadence'          => (string) ($fiscal['reporting_cadence'] ?? 'monthly'),
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_settings_fiscal_year', (string) $request->request->get('_token', ''))) {
                $this->addFlash('danger', 'admin.fiscal_year.csrf_invalid');
                return $this->redirectToRoute('admin_settings_fiscal_year');
            }

            $month = (int) $request->request->get('financial_year_start_month', 1);
            $new = [
                'financial_year_start_month' => max(1, min(12, $month)),
                'quarterly_grouping'         => $request->request->getBoolean('quarterly_grouping'),
                'reporting_cadence'          => match ($request->request->get('reporting_cadence', 'monthly')) {
                    'monthly', 'quarterly', 'yearly' => $request->request->get('reporting_cadence'),
                    default => 'monthly',
                },
            ];

            $tenant->setFinancialYearStartMonth($new['financial_year_start_month']);

            $updatedSettings = $settings;
            $updatedSettings['fiscal'] = [
                'quarterly_grouping' => $new['quarterly_grouping'],
                'reporting_cadence'  => $new['reporting_cadence'],
            ];
            $tenant->setSettings($updatedSettings);

            $this->em->flush();

            $this->auditLogger->logUpdate(
                entityType: 'Tenant',
                entityId: $tenant->getId(),
                oldValues: $current,
                newValues: $new,
                description: 'Fiscal year settings updated',
            );

            $this->addFlash('success', 'admin.fiscal_year.saved');
            return $this->redirectToRoute('admin_settings_fiscal_year');
        }

        return $this->render('admin/system_settings/fiscal_year.html.twig', [
            'current' => $current,
        ]);
    }

    private function requireTenant(): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('No tenant context.');
        }
        return $tenant;
    }
}
