<?php

declare(strict_types=1);

namespace App\Controller\Analytics;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\Fte\FteCalibrationConstant;
use App\Entity\User;
use App\Repository\Fte\FteCalibrationConstantRepository;
use App\Repository\Fte\FteTrackingMetricRepository;
use App\Service\Admin\AdminHubCatalog;
use App\Service\AuditLogger;
use App\Service\Fte\BoardReportGenerator;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * F11 FTE-Tracking Dashboard — three routes:
 *
 *   GET  /{_locale}/dashboard/fte-tracking                 — live counter + charts
 *   GET  /{_locale}/dashboard/fte-tracking/calibration     — view calibration constants
 *   POST /{_locale}/dashboard/fte-tracking/calibration     — update calibration constants
 *   GET  /{_locale}/dashboard/fte-tracking/board-report    — PDF/HTML/CSV export
 *
 * Module-gate: analytics
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/dashboard/fte-tracking', name: 'analytics_fte_')]
#[IsGranted('ROLE_MANAGER')]
class FteTrackingDashboardController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly FteTrackingMetricRepository $metricRepo,
        private readonly FteCalibrationConstantRepository $calibrationRepo,
        private readonly BoardReportGenerator $boardReportGenerator,
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('analytics')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', $this->translator->trans('common.tenant_not_found', [], 'messages'));
            return $this->redirectToRoute('app_dashboard');
        }

        $window90d = new DateInterval('P90D');

        $totalSavings = $this->metricRepo->getSavingsAggregate($tenant, $window90d);
        $bySource = $this->metricRepo->getSavingsBySource($tenant);
        $monthlyTrend = $this->metricRepo->getMonthlyTrend($tenant, 12);
        $allTimeSavings = $this->metricRepo->getTotalSavingsAllTime($tenant);

        return $this->render('dashboard/fte_tracking/index.html.twig', [
            'total_savings_minutes' => $totalSavings,
            'total_savings_hours' => round($totalSavings / 60, 1),
            'all_time_savings_hours' => round($allTimeSavings / 60, 1),
            'by_source' => $bySource,
            'monthly_trend' => $monthlyTrend,
            'monthly_trend_json' => json_encode(array_values($monthlyTrend), JSON_THROW_ON_ERROR),
            'monthly_labels_json' => json_encode(array_keys($monthlyTrend), JSON_THROW_ON_ERROR),
        ]);
    }

    #[Route('/calibration', name: 'calibration', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function calibration(
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        if ($redirect = $this->checkModuleActive('analytics')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', $this->translator->trans('common.tenant_not_found', [], 'messages'));
            return $this->redirectToRoute('app_dashboard');
        }

        // Known operation types for the calibration form
        $operationTypes = [
            FteCalibrationConstant::OP_MANUAL_USER_PROVISIONING,
            FteCalibrationConstant::OP_MANUAL_ASSET_CREATION,
            FteCalibrationConstant::OP_MANUAL_RISK_CREATION,
            FteCalibrationConstant::OP_MANUAL_CONTROL_MAPPING,
            FteCalibrationConstant::OP_SINGLE_FRAMEWORK_EVIDENCE_MAINTENANCE,
            FteCalibrationConstant::OP_MANUAL_BUSINESS_PROCESS_CREATION,
        ];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('fte_calibration', (string) $request->request->get('_token'))) {
                $this->addFlash('danger', $this->translator->trans('common.csrf_invalid', [], 'messages'));
                return $this->redirectToRoute('analytics_fte_calibration', ['_locale' => $request->getLocale()]);
            }

            $submittedValues = $request->request->all('calibration');

            foreach ($operationTypes as $opType) {
                if (!isset($submittedValues[$opType])) {
                    continue;
                }

                $rawValue = (float) $submittedValues[$opType];
                if ($rawValue < 0.1 || $rawValue > 9999.0) {
                    continue;
                }

                $constant = $this->calibrationRepo->findOrCreate($tenant, $opType);
                $oldValue = $constant->getMinutesPerOperation();
                $constant->setMinutesPerOperation($rawValue);
                $constant->setLastUpdatedAt(new DateTimeImmutable());
                $constant->setLastUpdatedBy($user);

                $this->em->persist($constant);

                $this->auditLogger->logCustom(
                    AuditLogger::ACTION_FTE_CALIBRATION_CHANGED,
                    'FteCalibrationConstant',
                    $constant->getId(),
                    ['minutes_per_operation' => $oldValue],
                    ['minutes_per_operation' => $rawValue],
                    sprintf('Calibration updated: %s = %.2f min', $opType, $rawValue)
                );
            }

            $this->em->flush();
            $this->addFlash('success', $this->translator->trans('fte_tracking.calibration.saved', [], 'fte_tracking'));

            return $this->redirectToRoute('analytics_fte_calibration', ['_locale' => $request->getLocale()]);
        }

        // Build display list with current values
        $constants = [];
        foreach ($operationTypes as $opType) {
            $constants[$opType] = $this->calibrationRepo->getMinutesFor($tenant, $opType);
        }

        return $this->render('dashboard/fte_tracking/calibration.html.twig', [
            'constants' => $constants,
            'operation_types' => $operationTypes,
        ]);
    }

    #[Route('/board-report', name: 'board_report', methods: ['GET'])]
    public function boardReport(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('analytics')) {
            return $redirect;
        }

        $format = $request->query->get('format', 'html');
        $monthParam = $request->query->get('month', (new DateTimeImmutable())->format('Y-m'));

        try {
            $month = DateTimeImmutable::createFromFormat('Y-m', $monthParam);
            if ($month === false) {
                $month = new DateTimeImmutable();
            }
        } catch (\Throwable) {
            $month = new DateTimeImmutable();
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('warning', $this->translator->trans('common.tenant_not_found', [], 'messages'));
            return $this->redirectToRoute('app_dashboard');
        }

        $data = $this->boardReportGenerator->generateMonthly($tenant, $month);

        return match ($format) {
            'pdf' => new Response(
                $this->boardReportGenerator->renderAsPdf($data),
                Response::HTTP_OK,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => sprintf(
                        'attachment; filename="fte-report-%s.pdf"',
                        $month->format('Y-m')
                    ),
                ]
            ),
            'csv' => new Response(
                $this->boardReportGenerator->renderAsCsv($data),
                Response::HTTP_OK,
                [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => sprintf(
                        'attachment; filename="fte-report-%s.csv"',
                        $month->format('Y-m')
                    ),
                ]
            ),
            default => $this->render('dashboard/fte_tracking/board_report.html.twig', $data),
        };
    }
}
