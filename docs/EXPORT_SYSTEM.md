# Professional Export System - Documentation

## 🎯 Übersicht

Das Little ISMS Helper verfügt über ein professionelles Export-System mit **CSV** und **Excel (Multi-Tab)** Unterstützung.

### Implementierungsstatus

Alle Routen tragen das Locale-Präfix (`/de/…`, `/en/…`).

| Modul | CSV Export | Excel Export (Multi-Tab) | PDF Export | Status |
|-------|-----------|--------------------------|------------|--------|
| **Risk Management** | ✅ `/risk/export` | ✅ `/risk/export/excel` | ✅ `/risk/export/pdf` | **LIVE** |
| **Framework Comparison** | ✅ `/compliance/export/comparison` | ✅ `/compliance/export/comparison/excel` | ✅ `/compliance/export/comparison/pdf` | **LIVE** |
| **Gap Analysis** | ✅ `/compliance/framework/{id}/gaps/export` | ✅ `/compliance/framework/{id}/gaps/export/excel` | ✅ `/compliance/framework/{id}/gaps/export/pdf` | **LIVE** |
| **Data Reuse Insights** | ✅ `/compliance/framework/{id}/data-reuse/export` | ✅ `/compliance/framework/{id}/data-reuse/export/excel` | ✅ `/compliance/framework/{id}/data-reuse/export/pdf` | **LIVE** |
| **Transitive Compliance** | ✅ `/compliance/export/transitive` | ✅ `/compliance/export/transitive/excel` | ✅ `/compliance/export/transitive/pdf` | **LIVE** |

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

Risiko-Exporte baut `App\Service\Risk\RiskRegisterExporter`. Große CSV-Register laufen asynchron
über `POST /risk/export/dispatch` (Fortschrittsseite, Download unter `/risk/export/download/{id}`,
nur für den Auftraggeber — `AsyncJobVoter`).

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

### 3. Framework Comparison PDF Export

**Route:** `GET /compliance/export/comparison/pdf?framework1={id}&framework2={id}`
**Button:** Framework Compare → "PDF Report" (rot)

**Content:**
- Executive Summary with Framework Metrics
- Overlap Percentage and Mapping Statistics
- **High-Quality Mappings Table** (≥80% Match)
- **All Mappings Detailed Comparison**
- **Unique Requirements Analysis**
- **Recommendations Section:**
  - Harmonization Strategy
  - Mapping Gap Closure Plan (with estimated hours)
  - Implementation Roadmap (3 Phases)
  - Quick Win Opportunities
- ROI calculations for mapping completion

**Example Filename:** `framework_comparison_ISO27001_vs_TISAX_2025-11-13_153045.pdf`

**Special Feature:** Multi-framework harmonization recommendations with time estimates

### 4. Data Reuse Insights PDF Export

**Route:** `GET /compliance/framework/{id}/data-reuse/export/pdf`
**Button:** Data Reuse Insights → "PDF Report" (rot)

**Content:**
- Executive Summary with Time Savings
- Average Reuse Percentage
- **Quick Wins Section** (≥80% reuse + high confidence)
  - Calculated time savings per opportunity
  - Total estimated hours saved
- **All Data Reuse Analysis Table**
- **Recommendations Section:**
  - Priority 1: Quick Wins (Week 1-2)
  - Priority 2: High-Value Opportunities (Month 1-2)
  - Implementation Roadmap (3 Phases)
  - ROI Analysis
- Next Steps with specific action items

**Example Filename:** `data_reuse_insights_ISO27001_2025-11-13_153045.pdf`

**Special Feature:** Quick Wins identification with calculated ROI and time savings

### 5. Transitive Compliance PDF Export

**Route:** `GET /compliance/export/transitive/pdf`
**Button:** Transitive Compliance → "PDF Report" (rot)

**Content:**
- Executive Summary with Framework Statistics
- Framework Relationships Matrix
- **High-Coverage Relationships** (≥70%)
  - Cross-framework synergies
  - Estimated time savings per relationship
- **Compliance Leverage Analysis**
  - Current leverage score
  - Performance assessment
- **Multi-Framework Strategy Recommendations:**
  - Recommendation 1: High-Coverage Relationships (Very High Priority)
  - Recommendation 2: Mapping Gap Closure (High Priority)
  - Recommendation 3: Compliance Hub Strategy
  - Implementation Roadmap (6 months, 3 phases)
  - Quick Win Scorecard
- Next Steps with actionable 6-item checklist

**Example Filename:** `transitive_compliance_report_2025-11-13_153045.pdf`

**Special Feature:** Multi-framework strategy with compliance hub vision and leverage optimization

### PDF Template System

**Base Template:** `templates/pdf/_base_document.html.twig` (fa-report-doc-Layout: Cover, Executive Summary, Inhalt)

**Specific Templates:**
- `templates/pdf/risk_report.html.twig`
- `templates/pdf/gap_analysis_report.html.twig`
- `templates/pdf/framework_comparison_report.html.twig`
- `templates/pdf/data_reuse_insights_report.html.twig`
- `templates/pdf/transitive_compliance_report.html.twig`

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

Export-Buttons sind Aktionen im Seitenkopf (`_fa_page_header`), siehe `templates/compliance/gap_analysis.html.twig`:

```twig
{{ _fa_page_header.render({
    title: 'compliance.title.gap_analysis'|trans({}, 'compliance'),
    actions: [
        { label: 'compliance.action.export_excel'|trans({}, 'compliance'), variant: 'secondary',
          href: path('app_compliance_export_gaps_excel', {id: framework.id}), attrs: {'data-turbo': 'false'} },
        { label: 'compliance.action.export_csv'|trans({}, 'compliance'), variant: 'ghost',
          href: path('app_compliance_export_gaps', {id: framework.id}), attrs: {'data-turbo': 'false'} }
    ]
}) }}
```

---

## 🎨 Tenant-Branding

Eine Export-Konfiguration pro Tenant (Farben, Logo in Excel/PDF) gibt es nicht. Die Farben stehen
zentral in `ExcelExportService::getColor()`. Tenant-Logos liegen in `public/uploads/tenants/`
(`Tenant::logoPath` = `uploads/tenants/<code>-<id>.<ext>`, Upload in `TenantManagementController`).

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
- **Formula Injection Prevention** – Zellen mit Datensatztext laufen durch `App\Util\CsvSanitizer::sanitize()`
  (`ExcelExportService` für Header, Summary und Datenzeilen)
- **Login erforderlich** – `ROLE_USER` (Attribut an `RiskController`, `access_control` für die Compliance-Exporte)
- **Tenant Isolation** – Risiken und Erfüllungen nur der eigenen Organisation
- **PDF ohne Remote-Ressourcen** – DomPDF mit `isRemoteEnabled: false`

---

## 🎓 Beispiel-Workflow

```bash
# 1. User öffnet Risk Management
GET /de/risk

# 2. Klickt auf "Excel Export"
GET /de/risk/export/excel

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
- Service: `src/Service/ExcelExportService.php`, `src/Service/PdfExportService.php`
- Beispiel: `src/Service/Risk/RiskRegisterExporter.php`
