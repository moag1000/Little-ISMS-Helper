<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Entity\ScheduledReport;
use App\Risk\RiskMatrixThresholds;
use App\Service\ExcelExportService;
use App\Service\PdfExportService;
use DateTime;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The file attached to a scheduled report, in the language chosen on the
 * schedule (W10, R8-09).
 *
 * Before, the language applied only to the mail text: the PDF was rendered in
 * the worker's default locale and the Excel sheet had fixed English headers.
 * Data and file are now produced under {@see LocaleSwitcher::runWithLocale()},
 * so labels computed by the report services, the Twig templates (including
 * `<html lang="{{ app.locale }}">`) and the sheet headers all follow it.
 */
final class ScheduledReportAttachment
{
    /**
     * @param list<string> $supportedLocales
     */
    public function __construct(
        private readonly PdfExportService $pdfExportService,
        private readonly ExcelExportService $excelExportService,
        private readonly TranslatorInterface $translator,
        private readonly LocaleSwitcher $localeSwitcher,
        #[Autowire('%app.supported_locales%')]
        private readonly array $supportedLocales = ['de', 'en'],
    ) {
    }

    public function localeOf(ScheduledReport $report): string
    {
        $locale = $report->getLocale();

        return is_string($locale) && in_array($locale, $this->supportedLocales, true)
            ? $locale
            : ($this->supportedLocales[0] ?? 'de');
    }

    /**
     * @param callable(): array<string, mixed> $data report data, computed inside the report's locale
     */
    public function render(ScheduledReport $report, callable $data, string $template): string
    {
        return $this->localeSwitcher->runWithLocale(
            $this->localeOf($report),
            function () use ($report, $data, $template): string {
                $values = $data();
                if ($report->getFormat() === ScheduledReport::FORMAT_PDF) {
                    $generatedAt = new DateTime();

                    return $this->pdfExportService->generatePdf($template, array_merge($values, [
                        'generated_at' => $generatedAt,
                        'version' => $generatedAt->format('Y.m.d'),
                    ]));
                }

                return match ($report->getReportType()) {
                    ScheduledReport::TYPE_RISK => $this->riskSheet($values),
                    ScheduledReport::TYPE_ASSETS => $this->assetSheet($values),
                    default => $this->summarySheet($report->getReportType(), $values),
                };
            },
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function riskSheet(array $data): string
    {
        $headers = array_map(
            fn (string $key): string => $this->translator->trans('risk.export.header.' . $key, [], 'risk'),
            ['id', 'title', 'category', 'probability', 'impact', 'risk_score', 'risk_level', 'treatment_strategy',
                'status', 'risk_owner'],
        );
        $rows = [];
        foreach ($data['report']['risks'] ?? [] as $risk) {
            $score = $risk->getRiskScore();
            $rows[] = [
                $risk->getId(),
                $risk->getTitle(),
                $risk->getCategory(),
                $risk->getProbability(),
                $risk->getImpact(),
                $score,
                $this->label('risk.export.level.', RiskMatrixThresholds::classify($score, $risk->getTenant()), 'risk'),
                $this->label('risk.export.treatment.', $risk->getTreatmentStrategy()?->value, 'risk'),
                $this->label('risk.export.status.', $risk->getStatus()?->value, 'risk'),
                $risk->getRiskOwner()?->getEmail() ?? '-',
            ];
        }

        return $this->sheet($rows, $headers, 'scheduled_report.attachment.sheet.risk');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assetSheet(array $data): string
    {
        $headers = array_map(
            fn (string $key): string => $this->translator->trans('scheduled_report.attachment.asset.' . $key, [], 'scheduled_reports'),
            ['id', 'name', 'type', 'classification', 'owner', 'status'],
        );
        $rows = [];
        foreach ($data['report']['assets'] ?? [] as $asset) {
            $rows[] = [
                $asset->getId(),
                $asset->getName(),
                $this->label('asset.type.', $asset->getAssetType(), 'asset'),
                $this->label('asset.classification.', $asset->getDataClassification(), 'asset'),
                $asset->getEffectiveOwner() ?? '-',
                $this->label('asset.status.', $asset->getStatus(), 'asset'),
            ];
        }

        return $this->sheet($rows, $headers, 'scheduled_report.attachment.sheet.assets');
    }

    /**
     * Types without their own sheet: one row per metric.
     *
     * @param array<string, mixed> $data
     */
    private function summarySheet(string $type, array $data): string
    {
        $title = $this->translator->trans('scheduled_report.type.' . $type, [], 'scheduled_reports');
        $headers = [
            $this->translator->trans('scheduled_report.attachment.summary.metric', [], 'scheduled_reports'),
            $this->translator->trans('scheduled_report.attachment.summary.value', [], 'scheduled_reports'),
        ];

        $spreadsheet = $this->excelExportService->exportArray($this->flatten($data), $headers, $this->sheetTitle($title));

        return $this->excelExportService->generateExcel($spreadsheet);
    }

    /**
     * @param list<list<mixed>> $rows
     * @param list<string>      $headers
     */
    private function sheet(array $rows, array $headers, string $titleKey): string
    {
        $title = $this->translator->trans($titleKey, [], 'scheduled_reports');
        $spreadsheet = $this->excelExportService->exportArray($rows, $headers, $this->sheetTitle($title));

        return $this->excelExportService->generateExcel($spreadsheet);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{0: string, 1: string}>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $rows = [];
        foreach ($data as $key => $value) {
            $label = $prefix !== '' ? $prefix . '.' . $key : (string) $key;
            if (is_array($value)) {
                if ($value !== [] && !array_is_list($value)) {
                    $rows = array_merge($rows, $this->flatten($value, $label));
                } else {
                    $rows[] = [$label, $this->translator->trans(
                        'scheduled_report.attachment.summary.items',
                        ['%count%' => count($value)],
                        'scheduled_reports',
                    )];
                }
            } elseif ($value instanceof \DateTimeInterface) {
                $rows[] = [$label, $value->format('Y-m-d H:i')];
            } elseif (is_bool($value)) {
                $rows[] = [$label, $this->translator->trans($value ? 'common.yes' : 'common.no', [], 'messages')];
            } elseif (!is_object($value)) {
                $rows[] = [$label, (string) $value];
            }
        }

        return $rows;
    }

    /**
     * The translated label of a stored value; the value itself when the
     * catalogue has no entry for it (free categories, legacy values).
     */
    private function label(string $prefix, ?string $value, string $domain): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        $key = $prefix . $value;
        $text = $this->translator->trans($key, [], $domain);

        return $text === $key ? $value : $text;
    }

    /** Excel allows 31 characters and no []:*?/\ in a sheet title. */
    private function sheetTitle(string $title): string
    {
        return mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $title), 0, 31);
    }
}
