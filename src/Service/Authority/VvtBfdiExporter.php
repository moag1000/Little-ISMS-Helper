<?php

declare(strict_types=1);

namespace App\Service\Authority;

use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Repository\ProcessingActivityRepository;
use App\Service\PdfExportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * VVT-BfDI-Exporter — Art. 30 DSGVO / BfDI-Muster
 *
 * Generates the Record of Processing Activities (Verfahrensverzeichnis) in the
 * format recommended by the German Federal Commissioner for Data Protection and
 * Freedom of Information (BfDI) for regulatory reporting.
 *
 * Supported formats: XLSX (multi-tab), CSV (BfDI column order), PDF (via Twig/DomPDF)
 *
 * Column order follows: BfDI Muster VVT (Art. 30 Abs. 1 DSGVO):
 *   1. Verantwortlicher (controller name + contact)
 *   2. Datenschutzbeauftragter (DPO name + email)
 *   3. Verarbeitungszweck (purpose(s))
 *   4. Kategorien betroffener Personen (dataSubjectCategories)
 *   5. Kategorien personenbezogener Daten (personalDataCategories)
 *   6. Kategorien von Empfängern (recipientCategories)
 *   7. Drittlandübermittlung (hasThirdCountryTransfer + countries)
 *   8. Löschfristen (retentionPeriod)
 *   9. TOMs (technicalOrganizationalMeasures + implementedControls ref)
 *
 * @see https://www.bfdi.bund.de/DE/Datenschutz/Ueberblick/MeineRechte/Artikel/DatenschutzbeauftragterAufgaben.html
 */
final class VvtBfdiExporter
{
    /** BfDI-conformant column headers (Art. 30 DSGVO). */
    private const BFDI_COLUMNS = [
        'Verantwortlicher',
        'Datenschutzbeauftragter (DSB)',
        'Verarbeitungszweck',
        'Kategorien betroffener Personen',
        'Kategorien personenbezogener Daten',
        'Kategorien von Empfängern',
        'Drittlandübermittlung',
        'Löschfristen',
        'Technische und organisatorische Maßnahmen (TOM)',
    ];

    /** Header fill colour (BfDI uses blue tones in the official template). */
    private const HEADER_BG = 'E8F0FE';
    private const HEADER_FONT_BOLD = true;

    public function __construct(
        private readonly ProcessingActivityRepository $processingActivityRepository,
        private readonly PdfExportService $pdfExportService,
    ) {
    }

    // ─── XLSX Export ─────────────────────────────────────────────────────────

    /**
     * Generate a BfDI-conformant XLSX workbook with multiple tabs.
     *
     * Tabs:
     *  1. Cover — organisation info + export metadata
     *  2. VVT-Hauptliste — main Art. 30 table (one row per ProcessingActivity)
     *  3. Sub-Processors — external processors / recipients
     *  4. TOMs-Mapping — controls mapped to processing activities
     *  5. Auditor-Notes — space for auditor comments
     */
    public function exportXlsx(Tenant $tenant): Spreadsheet
    {
        $activities = $this->processingActivityRepository->findByTenant($tenant);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle('Verfahrensverzeichnis Art. 30 DSGVO')
            ->setSubject('BfDI-Muster Verfahrensverzeichnis')
            ->setCreator($tenant->getName() ?? 'ISMS-Helper')
            ->setCompany($tenant->getName() ?? 'ISMS-Helper')
            ->setDescription('Verzeichnis von Verarbeitungstätigkeiten (Art. 30 DSGVO)');

        // ─── Tab 1: Cover ─────────────────────────────────────────────────────
        $coverSheet = $spreadsheet->getActiveSheet();
        $coverSheet->setTitle('Cover');

        $coverSheet->setCellValue('A1', 'Verzeichnis von Verarbeitungstätigkeiten');
        $coverSheet->setCellValue('A2', 'Art. 30 DSGVO — BfDI-Muster');
        $coverSheet->setCellValue('A4', 'Verantwortlicher:');
        $coverSheet->setCellValue('B4', $tenant->getName() ?? '—');
        $coverSheet->setCellValue('A5', 'Datenschutzbeauftragter:');
        $coverSheet->setCellValue('B5', $tenant->getDpoContactName() ?? '—');
        $coverSheet->setCellValue('A6', 'DSB-E-Mail:');
        $coverSheet->setCellValue('B6', $tenant->getDpoContactEmail() ?? '—');
        $coverSheet->setCellValue('A7', 'Erstellt am:');
        $coverSheet->setCellValue('B7', (new \DateTimeImmutable())->format('d.m.Y H:i'));
        $coverSheet->setCellValue('A8', 'Anzahl Verarbeitungstätigkeiten:');
        $coverSheet->setCellValue('B8', count($activities));

        $coverSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $coverSheet->getStyle('A2')->getFont()->setSize(11)->setItalic(true);
        $coverSheet->getStyle('A4:A8')->getFont()->setBold(true);
        $coverSheet->getColumnDimension('A')->setWidth(38);
        $coverSheet->getColumnDimension('B')->setWidth(50);

        // ─── Tab 2: VVT-Hauptliste ────────────────────────────────────────────
        $mainSheet = $spreadsheet->createSheet();
        $mainSheet->setTitle('VVT-Hauptliste');

        $this->writeVvtHeaders($mainSheet);
        $this->writeVvtRows($mainSheet, $activities, $tenant);

        // ─── Tab 3: Sub-Processors ────────────────────────────────────────────
        $subSheet = $spreadsheet->createSheet();
        $subSheet->setTitle('Sub-Processors');

        $subSheet->setCellValue('A1', 'Verarbeitungstätigkeit');
        $subSheet->setCellValue('B1', 'Empfänger / Sub-Processor');
        $subSheet->setCellValue('C1', 'Kategorie');
        $subSheet->setCellValue('D1', 'Drittland');
        $subSheet->setCellValue('E1', 'Schutzgarantie (Art. 46 DSGVO)');

        $this->applyHeaderStyle($subSheet, 'A1:E1');
        $this->autoSizeColumns($subSheet, ['A', 'B', 'C', 'D', 'E']);

        $subRow = 2;
        foreach ($activities as $activity) {
            $recipientDetails = $activity->getRecipientDetails();
            if (empty($recipientDetails)) {
                continue;
            }

            $subSheet->setCellValue('A' . $subRow, $activity->getName());
            $subSheet->setCellValue('B' . $subRow, $recipientDetails);
            $subSheet->setCellValue('C' . $subRow, implode(', ', $activity->getRecipientCategories() ?? []));
            $subSheet->setCellValue('D' . $subRow, $activity->getHasThirdCountryTransfer() ? 'Ja' : 'Nein');
            $subSheet->setCellValue('E' . $subRow, $activity->getTransferSafeguards() ?? '—');
            ++$subRow;
        }

        // ─── Tab 4: TOMs-Mapping ──────────────────────────────────────────────
        $tomsSheet = $spreadsheet->createSheet();
        $tomsSheet->setTitle('TOMs-Mapping');

        $tomsSheet->setCellValue('A1', 'Verarbeitungstätigkeit');
        $tomsSheet->setCellValue('B1', 'Control-ID');
        $tomsSheet->setCellValue('C1', 'Control-Titel');
        $tomsSheet->setCellValue('D1', 'TOM-Beschreibung');

        $this->applyHeaderStyle($tomsSheet, 'A1:D1');
        $this->autoSizeColumns($tomsSheet, ['A', 'B', 'C', 'D']);

        $tomsRow = 2;
        foreach ($activities as $activity) {
            $controls = $activity->getImplementedControls();
            if ($controls->isEmpty()) {
                // Still write TOM description row if text-based TOMs present
                $tom = $activity->getTechnicalOrganizationalMeasures();
                if (!empty($tom)) {
                    $tomsSheet->setCellValue('A' . $tomsRow, $activity->getName());
                    $tomsSheet->setCellValue('B' . $tomsRow, '—');
                    $tomsSheet->setCellValue('C' . $tomsRow, '—');
                    $tomsSheet->setCellValue('D' . $tomsRow, $tom);
                    ++$tomsRow;
                }
                continue;
            }

            foreach ($controls as $control) {
                $tomsSheet->setCellValue('A' . $tomsRow, $activity->getName());
                $tomsSheet->setCellValue('B' . $tomsRow, method_exists($control, 'getIdentifier') ? $control->getIdentifier() : '—');
                $tomsSheet->setCellValue('C' . $tomsRow, method_exists($control, 'getTitle') ? $control->getTitle() : '—');
                $tomsSheet->setCellValue('D' . $tomsRow, $activity->getTechnicalOrganizationalMeasures() ?? '—');
                ++$tomsRow;
            }
        }

        // ─── Tab 5: Auditor-Notes ─────────────────────────────────────────────
        $auditSheet = $spreadsheet->createSheet();
        $auditSheet->setTitle('Auditor-Notes');

        $auditSheet->setCellValue('A1', 'Prüfung durchgeführt von:');
        $auditSheet->setCellValue('A2', 'Datum der Prüfung:');
        $auditSheet->setCellValue('A3', 'Ergebnis:');
        $auditSheet->setCellValue('A4', 'Anmerkungen:');
        $auditSheet->getStyle('A1:A4')->getFont()->setBold(true);
        $auditSheet->getColumnDimension('A')->setWidth(30);
        $auditSheet->getColumnDimension('B')->setWidth(60);

        // Return to main sheet
        $spreadsheet->setActiveSheetIndex(1);

        return $spreadsheet;
    }

    // ─── CSV Export ───────────────────────────────────────────────────────────

    /**
     * Generate a BfDI-conformant CSV in UTF-8 with BOM.
     *
     * Returns the raw CSV string ready for streaming.
     */
    public function exportCsv(Tenant $tenant): string
    {
        $activities = $this->processingActivityRepository->findByTenant($tenant);

        $handle = fopen('php://temp', 'wb+');

        if ($handle === false) {
            throw new \RuntimeException('Could not open temp stream for CSV export.');
        }

        // UTF-8 BOM for Excel compatibility
        fwrite($handle, "\xEF\xBB\xBF");

        // Header row
        fputcsv($handle, self::BFDI_COLUMNS, ';', '"', '\\');

        foreach ($activities as $activity) {
            fputcsv($handle, $this->buildVvtRow($activity, $tenant), ';', '"', '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv !== false ? $csv : '';
    }

    // ─── PDF Export ───────────────────────────────────────────────────────────

    /**
     * Generate a BfDI-conformant PDF using the existing PdfExportService + Twig template.
     *
     * Returns the raw PDF bytes.
     */
    public function exportPdf(Tenant $tenant): string
    {
        $activities = $this->processingActivityRepository->findByTenant($tenant);

        return $this->pdfExportService->generatePdf('processing_activity/vvt_bfdi_export.html.twig', [
            'tenant'     => $tenant,
            'activities' => $activities,
            'columns'    => self::BFDI_COLUMNS,
            'exportedAt' => new \DateTimeImmutable(),
        ]);
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Write the BfDI column headers to the given sheet.
     */
    private function writeVvtHeaders(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        $cols = range('A', chr(ord('A') + count(self::BFDI_COLUMNS) - 1));

        foreach (self::BFDI_COLUMNS as $i => $header) {
            $sheet->setCellValue($cols[$i] . '1', $header);
        }

        $lastCol = $cols[count(self::BFDI_COLUMNS) - 1];
        $this->applyHeaderStyle($sheet, 'A1:' . $lastCol . '1');
        $this->autoSizeColumns($sheet, $cols);
    }

    /**
     * Write one row per ProcessingActivity to the VVT main sheet.
     *
     * @param ProcessingActivity[] $activities
     */
    private function writeVvtRows(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $activities,
        Tenant $tenant,
    ): void {
        $rowNum = 2;

        foreach ($activities as $activity) {
            $row = $this->buildVvtRow($activity, $tenant);

            foreach (array_values($row) as $colIdx => $value) {
                $col = chr(ord('A') + $colIdx);
                $sheet->setCellValue($col . $rowNum, $value);
                // Wrap text in cells to accommodate long values
                $sheet->getStyle($col . $rowNum)->getAlignment()->setWrapText(true);
            }

            ++$rowNum;
        }
    }

    /**
     * Build a single VVT row array in BfDI column order.
     *
     * @return list<string>
     */
    private function buildVvtRow(ProcessingActivity $activity, Tenant $tenant): array
    {
        // 1. Verantwortlicher
        $responsible = $tenant->getName() ?? '—';

        // 2. DSB
        $dpo = implode(' / ', array_filter([
            $tenant->getDpoContactName(),
            $tenant->getDpoContactEmail(),
        ])) ?: '—';

        // 3. Verarbeitungszweck
        $purposes = implode('; ', $activity->getPurposes());

        // 4. Kategorien betroffener Personen
        $dataSubjects = implode('; ', $activity->getDataSubjectCategories());

        // 5. Kategorien personenbezogener Daten
        $dataCategories = implode('; ', $activity->getPersonalDataCategories());

        // 6. Empfänger
        $recipients = implode('; ', $activity->getRecipientCategories() ?? []);
        if (!empty($activity->getRecipientDetails())) {
            $recipients .= ($recipients ? '; ' : '') . $activity->getRecipientDetails();
        }

        // 7. Drittlandübermittlung
        $thirdCountry = $activity->getHasThirdCountryTransfer()
            ? 'Ja — ' . implode(', ', $activity->getThirdCountries() ?? [])
            : 'Nein';

        // 8. Löschfristen
        $retention = $activity->getRetentionPeriod() ?? '—';
        if ($activity->getRetentionPeriodDays() !== null) {
            $retention .= ' (' . $activity->getRetentionPeriodDays() . ' Tage)';
        }

        // 9. TOMs
        $toms = $activity->getTechnicalOrganizationalMeasures() ?? '';
        $controlIds = [];
        foreach ($activity->getImplementedControls() as $control) {
            if (method_exists($control, 'getIdentifier') && $control->getIdentifier() !== null) {
                $controlIds[] = $control->getIdentifier();
            }
        }

        if (!empty($controlIds)) {
            $toms .= ($toms ? "\n" : '') . 'Controls: ' . implode(', ', $controlIds);
        }

        if ($toms === '') {
            $toms = '—';
        }

        return [
            $responsible,
            $dpo,
            $purposes,
            $dataSubjects,
            $dataCategories,
            $recipients ?: '—',
            $thirdCountry,
            $retention,
            $toms,
        ];
    }

    /**
     * Apply the BfDI header row style to a cell range.
     */
    private function applyHeaderStyle(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(self::HEADER_FONT_BOLD);
        $style->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB(self::HEADER_BG);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    /**
     * Auto-size a list of column letters on the given sheet.
     *
     * @param list<string> $cols
     */
    private function autoSizeColumns(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $cols): void
    {
        foreach ($cols as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
