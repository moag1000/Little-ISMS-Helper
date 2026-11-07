<?php

declare(strict_types=1);

namespace App\Service\Group;

use App\Entity\Tenant;
use App\Service\AuditLogger;
use App\Service\ExcelExportService;
use App\Service\PdfExportService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Organisation comparison of a holding as Excel table and landscape PDF —
 * the same rows and KPI values as the page ({@see OrganisationComparison}).
 * Every export is audit-logged (who, format, how many organisations).
 */
readonly class OrganisationComparisonExporter
{
    public function __construct(
        private OrganisationComparison $comparison,
        private ExcelExportService $excel,
        private PdfExportService $pdf,
        private AuditLogger $auditLogger,
        private TranslatorInterface $translator,
    ) {
    }

    /** @param list<Tenant> $tenants */
    public function excel(Tenant $root, array $tenants): Response
    {
        $data = $this->comparison->build($tenants);

        $headers = [$this->t('group_report.comparison.col.organisation'), $this->t('group_report.comparison.col.code')];
        foreach ($data['columns'] as $column) {
            $headers[] = $this->columnHeader($column);
        }
        foreach ($data['frameworks'] as $framework) {
            $headers[] = $framework['label'] . ' (%)';
        }

        $rows = [];
        foreach ($data['rows'] as $row) {
            $line = [(string) $row['tenant']->getName(), (string) $row['tenant']->getCode()];
            foreach ($data['columns'] as $column) {
                $line[] = $row['kpis'][$column['key']]['value'] ?? '';
            }
            foreach ($data['frameworks'] as $framework) {
                $line[] = $row['frameworks'][$framework['key']]['value'] ?? '';
            }
            $rows[] = $line;
        }
        $total = [$this->t('group_report.comparison.group_row'), ''];
        foreach ($data['columns'] as $column) {
            $total[] = $data['group'][$column['key']]['value'] ?? '';
        }
        foreach ($data['frameworks'] as $framework) {
            $total[] = $data['group']['fw:' . $framework['key']]['value'] ?? '';
        }
        $rows[] = $total;

        $sheet = $this->excel->exportArray($rows, $headers, $this->t('group_report.comparison.sheet'));
        $this->log($root, 'xlsx', count($data['rows']));

        return new Response($this->excel->generateExcel($sheet), Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $this->filename($root, 'xlsx') . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @param list<Tenant> $tenants */
    public function pdf(Tenant $root, array $tenants): Response
    {
        $content = $this->pdf->generatePdf('group_report/comparison_pdf.html.twig', [
            'root' => $root,
            'comparison' => $this->comparison->build($tenants),
        ], ['orientation' => 'landscape']);
        $this->log($root, 'pdf', count($tenants));

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->filename($root, 'pdf') . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @param array{key: string, group: string, unit: string} $column */
    private function columnHeader(array $column): string
    {
        $label = $this->translator->trans('dashboard.management_kpis.' . $column['group'] . '.title', [], 'dashboard')
            . ' · ' . $this->translator->trans('dashboard.kpi.' . $column['key'], [], 'dashboard');

        return $column['unit'] !== '' ? $label . ' (' . $column['unit'] . ')' : $label;
    }

    private function filename(Tenant $root, string $extension): string
    {
        $code = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $root->getCode()) ?: 'konzern';

        return sprintf('organisationsvergleich-%s-%s.%s', $code, date('Y-m-d'), $extension);
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'group_report');
    }

    private function log(Tenant $root, string $format, int $count): void
    {
        $this->auditLogger->logCustom(
            'group_report.comparison.export',
            'Tenant',
            $root->getId(),
            null,
            ['format' => $format, 'organisations' => $count],
            sprintf('Organisation comparison exported (%s, %d organisations)', $format, $count),
        );
    }
}
