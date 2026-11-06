<?php

declare(strict_types=1);

namespace App\Service\Location;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\ExcelExportService;
use App\Service\PdfExportService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Situation report of the location map: Excel table and a one-page PDF for
 * the management review (ISO 27001 9.3). Same rows, levers and peer ranks as
 * the map; the selection on the map narrows it. Every export is audit-logged.
 */
readonly class LocationMapExporter
{
    public function __construct(
        private LocationMapView $mapView,
        private ExcelExportService $excel,
        private PdfExportService $pdf,
        private AuditLogger $auditLogger,
        private TranslatorInterface $translator,
    ) {
    }

    /** @param list<int> $ids selected sites; empty = all */
    public function excel(Tenant $tenant, User $user, array $ids): Response
    {
        $view = $this->view($tenant, $user, $ids);
        $t = fn (string $key): string => $this->translator->trans($key, [], 'locations');

        $headers = [
            $t('location.field.name'), $t('location.map.col.place'), $t('location.map.col.protection'),
            $t('location.map.col.risk'), $t('location.map.col.gaps'), $t('location.map.export.col.rate'),
        ];
        $withPeers = $view['peerMode'] !== LocationMapSettings::PEER_OFF;
        if ($withPeers) {
            $headers[] = $t('location.map.export.col.peer');
        }
        $headers[] = $t('location.map.col.incidents');
        if ($view['money']) {
            $headers[] = $t('location.map.export.col.ale');
        }

        $data = [];
        foreach ($view['sites'] as $site) {
            $peer = $view['peers'][$site['id']] ?? null;
            $row = [
                $site['name'],
                implode(', ', array_filter([$site['city'], $site['country']])),
                $site['protection'] ?: '',
                $site['riskBand'] === 'none' ? '' : $site['score'],
                implode(', ', array_map(fn (string $c): string => $t('location.map.check.' . $c), $site['gaps'])),
                $site['checksRate'] ?? '',
            ];
            if ($withPeers) {
                $row[] = $peer !== null && $peer['rank'] !== null ? $t('location.map.peer.rank.' . $peer['rank']) : '';
            }
            $row[] = $site['openIncidents'];
            if ($view['money']) {
                $row[] = $site['ale'] ?? 0;
            }
            $data[] = $row;
        }

        $sheet = $this->excel->exportArray($data, $headers, $t('location.map.export.sheet'));
        $this->log('xlsx', count($data));

        return new Response($this->excel->generateExcel($sheet), Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="standort-lagebild_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    /** @param list<int> $ids selected sites; empty = all */
    public function pdf(Tenant $tenant, User $user, array $ids): Response
    {
        $view = $this->view($tenant, $user, $ids);
        $content = $this->pdf->generatePdf('location/map_export_pdf.html.twig', $view + [
            'tenant_name' => $tenant->getName(),
            'levers' => LocationMapView::levers($view['sites'], 5),
            'action_needed' => array_values(array_filter(
                $view['sites'],
                static fn (array $s): bool => $s['gaps'] !== [] || in_array($s['riskBand'], ['high', 'critical'], true),
            )),
            'check_norms' => LocationPostureService::CHECK_NORM,
        ]);
        $this->log('pdf', count($view['sites']));

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="standort-lagebild_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    /**
     * @param list<int> $ids
     * @return array<string, mixed>
     */
    private function view(Tenant $tenant, User $user, array $ids): array
    {
        $view = $this->mapView->build($tenant, $user);
        if ($ids !== []) {
            $view['sites'] = array_values(array_filter($view['sites'], static fn (array $s): bool => in_array($s['id'], $ids, true)));
        }

        return $view;
    }

    private function log(string $format, int $count): void
    {
        $this->auditLogger->logCustom(
            'location.map.export',
            'Location',
            null,
            null,
            ['format' => $format, 'sites' => $count],
            sprintf('Location situation report exported (%s, %d sites)', $format, $count),
        );
    }
}
