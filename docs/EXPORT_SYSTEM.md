# Professional Export System - Documentation

## 🎯 Übersicht

Das Little ISMS Helper verfügt über ein professionelles Export-System mit **CSV** und **Excel (Multi-Tab)** Unterstützung.

### Implementierungsstatus

| Modul | CSV Export | Excel Export (Multi-Tab) | PDF Export | Status |
|-------|-----------|--------------------------|------------|--------|
| **Risk Management** | ✅ `/risk/export` | ✅ `/risk/export/excel` | ✅ `/risk/export/pdf` | **LIVE** |
| **Framework Comparison** | ✅ `/compliance/export/comparison` | ✅ `/compliance/export/comparison/excel` | ⏳ Template ready | **LIVE** |
| **Gap Analysis** | ✅ `/compliance/framework/{id}/gaps/export` | ✅ `/compliance/framework/{id}/gaps/export/excel` | ✅ `/compliance/framework/{id}/gaps/export/pdf` | **LIVE** |
| **Data Reuse Insights** | ✅ `/compliance/framework/{id}/data-reuse/export` | ✅ `/compliance/framework/{id}/data-reuse/export/excel` | ⏳ Template ready | **LIVE** |
| **Transitive Compliance** | ✅ `/compliance/export/transitive` | ✅ `/compliance/export/transitive/excel` | ⏳ Template ready | **LIVE** |

---

## 📄 PDF Export Features

### Professional PDF Reports with DomPDF

**PdfExportService:** `src/Service/PdfExportService.php`

**Features:**
- Professional Twig-based templates
- Custom header/footer with page numbers
- Responsive table layouts
- Color-coded badges (Critical/High/Medium/Low)
- Page breaks for better readability
- Secure by design (no remote resources)
- UTF-8 support with DejaVu Sans font

### 1. Risk Management PDF Export

**Route:** `GET /risk/export/pdf`
**Button:** Risk Index → "PDF Report" (rot)

**Content:**
- Executive Summary Box with KPIs
- Applied filters info
- **Critical & High Risks Table** (Priority section)
- **All Risks Detailed Table** (Complete overview)
- **Risk Statistics Section:**
  - Distribution by Risk Level (with percentages)
  - Distribution by Status
- Professional layout with color-coded badges

**Example Filename:** `risk_management_report_2025-11-13_153045.pdf`

### 2. Gap Analysis PDF Export

**Route:** `GET /compliance/framework/{id}/gaps/export/pdf`
**Button:** Gap Analysis → "PDF Report" (rot)

**Content:**
- Executive Summary with Compliance Score
- Gap Distribution by Severity (with percentages)
- **Critical Gaps Section** (Immediate action required)
- **All Gaps Detailed Table**
- **Recommendations Section:**
  - Priority 1: Critical Gaps (30-day plan)
  - Priority 2: High Gaps (90-day plan)
  - Compliance Roadmap (Q1-Q4)
  - Next Steps Checklist

**Example Filename:** `gap_analysis_ISO27001_2025-11-13_153045.pdf`

**Special Feature:** Management-ready recommendations with actionable timelines

### PDF Template System

**Base Template:** `templates/pdf/base.html.twig`
- Fixed header/footer
- Page numbering
- Professional styling
- Color scheme matching Excel exports

**Specific Templates:**
- `templates/pdf/risk_report.html.twig`
- `templates/pdf/gap_analysis_report.html.twig`

**Usage Example:**
```php
$pdfContent = $this->pdfExportService->generatePdf('pdf/risk_report.html.twig', [
    'risks' => $risks,
    'total_risks' => $totalRisks,
    'critical_risks' => $criticalRisks,
    // ... more data
]);

$response = new Response($pdfContent);
$response->headers->set('Content-Type', 'application/pdf');
$response->headers->set('Content-Disposition', 'attachment; filename="report.pdf"');
```

---

## 📊 Excel Export Features

### 1. Risk Management Excel Export

**Route:** `GET /risk/export/excel`
**Button:** Risk Index → "Excel Export" (grün)

**3 Tabs:**
1. **Zusammenfassung**
   - KPI Cards: Gesamt/Kritisch/Hoch/Mittel/Niedrig
   - Status-Verteilung
   - Export-Datum

2. **Alle Risiken**
   - 15 Spalten mit vollständigen Risk-Details
   - **Farbcodierung:** Rot (Kritisch), Orange (Hoch), Gelb (Mittel), Grün (Niedrig)
   - Frozen Headers (bleiben beim Scrollen sichtbar)
   - Auto-sized Columns

3. **Kritische & Hohe Risiken**
   - Gefilterte Ansicht für Management
   - Gleiche Formatierung wie Tab 2

**Features:**
- Conditional Formatting basierend auf Risk Score
- Beide Risk Levels (Inherent + Residual) farbcodiert
- Management-ready Output
- Unterstützt alle Filter (level, status, treatment, owner)

**Beispiel-Dateiname:** `risk_management_report_2025-11-13_153045.xlsx`

---

### 2. Framework Comparison Excel Export

**Route:** `GET /compliance/export/comparison/excel?framework1=1&framework2=2`
**Button:** Compare Page → "Excel Export" (grün)

**3 Tabs:**
1. **Zusammenfassung**
   - Framework Names & Codes
   - Requirement Counts
   - Mapping Statistics
   - Overlap Percentage

2. **Detaillierter Vergleich**
   - Side-by-Side Comparison
   - **Mapping Status:** Grün (Mapped), Gelb (Not Mapped)
   - **Match Quality:** Grün (>80%), Gelb (60-80%), Rot (<60%)
   - Frozen Headers

3. **Unique Requirements**
   - Framework 1 exclusive requirements
   - Zeigt Gaps zwischen Frameworks

**Features:**
- Automatische Farbcodierung basierend auf Match Quality
- Overlap-Analyse
- Identify Unique Requirements

**Beispiel-Dateiname:** `framework_comparison_ISO27001_vs_TISAX_2025-11-13_153045.xlsx`

---

## 🛠️ ExcelExportService

### Neue Methoden

```php
// Summary Section mit Styling
$nextRow = $excelExportService->addSummarySection(
    $sheet,
    ['Label' => 'Value'],
    $startRow,
    'Überschrift'
);

// Formatted Header Row mit Freeze Panes
$excelExportService->addFormattedHeaderRow(
    $sheet,
    ['Col1', 'Col2'],
    $row = 1,
    $freezePane = true
);

// Data Rows mit Conditional Formatting
$conditionalFormatting = [
    0 => [ // Column Index
        'Kritisch' => 'FF0000',  // Condition => Color
        'Hoch' => 'FFA500',
        '>=80' => 'C6EFCE',      // Operator-based
    ]
];
$excelExportService->addFormattedDataRows(
    $sheet,
    $data,
    $startRow,
    $conditionalFormatting
);

// Auto-size Columns
$excelExportService->autoSizeColumns($sheet, $maxWidth = 50);

// Create new Sheet
$newSheet = $excelExportService->createSheet($spreadsheet, 'Sheet Name');

// Get Color Constants
$color = $excelExportService->getColor('critical'); // 'FF0000'
```

### Farb-System

```php
'success'/'green'  => 'C6EFCE'  // Hell-Grün
'warning'/'yellow' => 'FFEB9C'  // Hell-Gelb
'danger'/'red'     => 'FFC7CE'  // Hell-Rot
'info'/'blue'      => 'BDD7EE'  // Hell-Blau
'critical'         => 'FF0000'  // Rot
'high'             => 'FFA500'  // Orange
'medium'           => 'FFFF00'  // Gelb
'low'              => '90EE90'  // Grün
'header_bg'        => 'D9E1F2'  // Blau
'header_font'      => '1F4E78'  // Dunkelblau
'summary_bg'       => 'FFF2CC'  // Gelb
```

---

## 🚀 Wie fügt man weitere Excel-Exporte hinzu?

### Schritt 1: Route hinzufügen

```php
#[Route('/export/gaps/excel', name: 'app_compliance_export_gaps_excel')]
public function exportGapsExcel(int $id): Response
{
    // 1. Daten sammeln (wie bei CSV-Export)
    $gaps = $this->requirementRepository->findGapsByFramework($framework);

    // 2. Spreadsheet erstellen
    $spreadsheet = $this->excelExportService->createSpreadsheet('Gap Analysis Report');

    // 3. Tab 1: Summary
    $summarySheet = $spreadsheet->getActiveSheet();
    $summarySheet->setTitle('Zusammenfassung');

    $metrics = [
        'Framework' => $framework->getName(),
        'Total Gaps' => count($gaps),
        'Critical' => $criticalCount,
    ];
    $this->excelExportService->addSummarySection($summarySheet, $metrics);

    // 4. Tab 2: Details
    $detailSheet = $this->excelExportService->createSheet($spreadsheet, 'Gap Details');
    $headers = ['ID', 'Titel', 'Severity', 'Status'];
    $this->excelExportService->addFormattedHeaderRow($detailSheet, $headers);

    // Prepare data array
    $data = [];
    foreach ($gaps as $gap) {
        $data[] = [
            $gap->getRequirementId(),
            $gap->getTitle(),
            $gap->getPriority(),
            $gap->getStatus(),
        ];
    }

    // Conditional formatting für Severity
    $conditionalFormatting = [
        2 => [ // Severity column
            'critical' => $this->excelExportService->getColor('critical'),
            'high' => $this->excelExportService->getColor('high'),
            'medium' => $this->excelExportService->getColor('medium'),
            'low' => $this->excelExportService->getColor('low'),
        ],
    ];
    $this->excelExportService->addFormattedDataRows($detailSheet, $data, 2, $conditionalFormatting);
    $this->excelExportService->autoSizeColumns($detailSheet);

    // 5. Generate & Return
    $content = $this->excelExportService->generateExcel($spreadsheet);

    $response = new Response($content);
    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $response->headers->set('Content-Disposition', 'attachment; filename="gap_analysis_' . $framework->getCode() . '_' . date('Y-m-d_His') . '.xlsx"');

    return $response;
}
```

### Schritt 2: Template-Button hinzufügen

```twig
{# templates/compliance/gap_analysis.html.twig #}
<div class="button-group">
    <a href="{{ path('app_compliance_export_gaps_excel', {id: framework.id}) }}" class="btn btn-success">
        📊 Excel Export
    </a>
    <a href="{{ path('app_compliance_export_gaps', {id: framework.id}) }}" class="btn btn-secondary">
        📄 CSV Export
    </a>
</div>
```

---

## 🎨 Konfigurierbarkeit für Tenant-Branding

### Vorschlag: Tenant-spezifische Export-Konfiguration

```yaml
# config/packages/export_config.yaml (oder Tenant-Entity)
parameters:
    export_config:
        excel:
            colors:
                critical: 'FF0000'
                high: 'FFA500'
                medium: 'FFFF00'
                low: '90EE90'
                header_bg: 'D9E1F2'
                summary_bg: 'FFF2CC'
            company_logo: '/public/uploads/logos/%tenant_code%_logo.png'

        pdf:
            company_name: '%tenant_name%'
            footer_text: 'Vertraulich - Nur für internen Gebrauch'
            logo_path: '/uploads/logos/%tenant_code%_logo.png'
            primary_color: '#1F4E78'
            font_family: 'DejaVu Sans'
```

### Implementierung

```php
// Service: src/Service/TenantExportConfigService.php
class TenantExportConfigService
{
    public function __construct(
        private ParameterBagInterface $params,
        private ?Tenant $tenant = null
    ) {}

    public function getColor(string $name): string
    {
        $config = $this->params->get('export_config')['excel']['colors'];

        // Tenant-override if available
        if ($this->tenant && $this->tenant->getExportColors()) {
            $tenantColors = $this->tenant->getExportColors();
            return $tenantColors[$name] ?? $config[$name] ?? 'FFFFFF';
        }

        return $config[$name] ?? 'FFFFFF';
    }

    public function getCompanyLogo(): ?string
    {
        if ($this->tenant && $this->tenant->getLogoPath()) {
            return $this->tenant->getLogoPath();
        }

        return $this->params->get('export_config')['excel']['company_logo'] ?? null;
    }
}
```

### Usage

```php
// In Controller
$spreadsheet = $this->excelExportService->createSpreadsheet('Report');

// Add logo if configured
if ($logoPath = $this->tenantExportConfig->getCompanyLogo()) {
    $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
    $drawing->setName('Logo');
    $drawing->setPath($logoPath);
    $drawing->setCoordinates('A1');
    $drawing->setHeight(50);
    $drawing->setWorksheet($spreadsheet->getActiveSheet());
}

// Use tenant colors
$criticalColor = $this->tenantExportConfig->getColor('critical');
```

---

## 📈 Benefits

### Management-Ready Reports
- **Keine Screenshots mehr nötig** - Excel kann direkt in Präsentationen verwendet werden
- **Professionelles Aussehen** - Farben, Formatierung, Frozen Headers
- **Drill-Down möglich** - Mehrere Tabs für verschiedene Detail-Levels

### Bessere Datenanalyse
- **Sortierbar** - Excel-Tabellen können von Usern sortiert werden
- **Filterbar** - Native Excel-Filter nutzbar
- **Berechnungen** - Benutzer können eigene Formeln hinzufügen

### Konsistenz
- **Einheitliches Format** - Alle Exporte folgen dem gleichen Design-Pattern
- **Wiederverwendbar** - ExcelExportService kann überall genutzt werden
- **Wartbar** - Zentrale Farb- und Style-Definitionen

---

## 🔒 Security

Alle Exporte nutzen:
- ✅ **Formula Injection Prevention** - Automatisches Prefixen gefährlicher Zeichen
- ✅ **Filename Sanitization** - Nur sichere Zeichen in Dateinamen
- ✅ **Authentication Required** - Alle Routes mit `#[IsGranted('ROLE_USER')]`
- ✅ **Tenant Isolation** - Nur eigene Daten exportierbar

---

## 📝 Next Steps

### Priorität 1: Remaining Excel Exports
1. Gap Analysis Excel Export (~15min)
2. Data Reuse Insights Excel Export (~15min)
3. Transitive Compliance Excel Export (~15min)

### Priorität 2: PDF Exports
- PDF Template System mit Twig
- Logo/CI Integration
- Professional Header/Footer
- Management Summary PDFs

### Priorität 3: Konfigurationssystem
- Tenant-Entity erweitern mit Export-Config
- Admin-UI für Farben/Logo-Upload
- Preview-Funktion für Exporte

### Priorität 4: Advanced Features
- Charts/Diagramme in Excel (PhpSpreadsheet Charts)
- Pivot Tables
- Email-Versand von Reports
- Scheduled Exports (Cron)

---

## 🎓 Beispiel-Workflow

```bash
# 1. User öffnet Risk Management
GET /risk

# 2. Klickt auf "Excel Export"
GET /risk/export/excel

# 3. Download startet
→ risk_management_report_2025-11-13_153045.xlsx

# 4. User öffnet in Excel
→ Tab 1: Zusammenfassung (KPIs)
→ Tab 2: Alle Risiken (farbcodiert)
→ Tab 3: Kritische Risiken (Management-View)

# 5. User kann:
   - Sortieren nach Spalten
   - Filtern nach Status
   - Eigene Berechnungen hinzufügen
   - Direkt in Präsentation einfügen
```

---

## 📞 Support

Bei Fragen oder Problemen:
- Dokumentation: `/docs/EXPORT_SYSTEM.md`
- Service: `src/Service/ExcelExportService.php`
- Beispiel: `src/Controller/RiskController.php::exportExcel()`

---

**Erstellt:** 2025-11-13
**Autor:** Claude AI
**Version:** 1.0.0
**Branch:** `claude/compliance-compare-page-011CV66D3n3i4LL3cdU8tgtw`
