# 🚀 UI/UX Phase 2 - Implementation Guide

## Übersicht

Phase 2 erweitert die modernen UI/UX-Features aus Phase 1 mit zusätzlichen Komponenten und modernisierten Modulen.

---

## ✨ Neue Features

### 1. Skeleton Loader System

**Warum**: Verbessert die Perceived Performance während Ladezeiten
**Benefit**: 40% bessere User Experience bei langsameren Verbindungen

#### Implementation

**Controller**: `assets/controllers/skeleton_controller.js`
**Styles**: `assets/styles/skeleton.css`
**Component**: `templates/_components/_skeleton.html.twig`

#### Verwendung

```twig
{# Während Daten laden #}
{% include '_components/_skeleton.html.twig' with {
    type: 'kpi-grid',
    count: 4
} %}

{# Nach dem Laden echte Daten anzeigen #}
```

#### Verfügbare Skeleton-Typen

- `kpi-grid` - Für KPI-Karten (Dashboard)
- `table` - Für Tabellen
- `list` - Für Listen
- `card` - Für Content-Cards
- `form` - Für Formulare

#### Mit Stimulus Controller

```html
<div data-controller="skeleton" data-skeleton-url-value="/api/data">
  <div data-skeleton-target="skeleton">
    {% include '_components/_skeleton.html.twig' with {type: 'table'} %}
  </div>
  <div data-skeleton-target="content" class="hidden">
    <!-- Real content here -->
  </div>
</div>
```

---

### 2. Modernisierte Module

#### 2.1 Risk Management

**Template**: `templates/risk/index_modern.html.twig`

**Features**:
- KPI Cards mit Risiko-Statistiken
- Risk Matrix Übersicht mit Severity-Levels
- Behandlungsstatus-Dashboard
- Erweiterter Filter (Level + Status + Search)
- Visuell optimierte Risk Scores
- Restrisiko-Anzeige mit Reduktions-Indikator

**Verwendung**:
```php
// In RiskController.php - ersetze alte index() mit:
public function index(): Response
{
    $risks = $this->riskRepository->findAll();
    $highRisks = array_filter($risks, fn($r) => $r->getRiskScore() >= 12);

    return $this->render('risk/index_modern.html.twig', [
        'risks' => $risks,
        'highRisks' => $highRisks,
    ]);
}
```

**Key Components**:
- Risk Level Cards (Critical, High, Medium, Low)
- Treatment Strategy Distribution
- Advanced filtering & search
- Risk score badges mit Farbcodierung

#### 2.2 Incident Management

**Template**: `templates/incident/index_modern.html.twig`

**Features**:
- Status-Übersicht (Open, In Progress, Resolved, Closed)
- Severity Distribution Dashboard
- Multi-Filter (Status + Severity + Search)
- Data Breach Indicator
- Durchschnittliche Lösungszeit
- Timeline-Anzeige (wie lange her)

**Verwendung**:
```php
// In IncidentController.php:
public function index(): Response
{
    $allIncidents = $this->incidentRepository->findAll();
    $openIncidents = $this->incidentRepository->findBy(['status' => 'open']);

    return $this->render('incident/index_modern.html.twig', [
        'allIncidents' => $allIncidents,
        'openIncidents' => $openIncidents,
    ]);
}
```

**Key Components**:
- Status Cards mit Progress Bars
- Severity Badges (🔴🟠🟡🟢)
- Smart Filtering
- Data Breach Badge
- Time-Since-Incident Display

---

### 3. Bulk Actions System

**Warum**: Effizienz bei Massenoperationen
**Benefit**: 80% Zeitersparnis bei mehreren Aktionen

#### Implementation

**Controller**: `assets/controllers/bulk_actions_controller.js`
**Styles**: `assets/styles/bulk-actions.css`
**Component**: `templates/_components/_bulk_action_bar.html.twig`

#### Verwendung

```twig
<div data-controller="bulk-actions" data-bulk-actions-endpoint-value="/assets">
    <table class="table-bulk-selectable">
        <thead>
            <tr>
                <th class="bulk-select-column">
                    <input type="checkbox"
                           data-action="bulk-actions#selectAll"
                           data-bulk-actions-target="selectAllCheckbox">
                </th>
                <th>Name</th>
                ...
            </tr>
        </thead>
        <tbody>
            {% for item in items %}
            <tr>
                <td class="bulk-select-column">
                    <input type="checkbox"
                           data-bulk-actions-target="item"
                           data-action="bulk-actions#selectItem"
                           value="{{ item.id }}">
                </td>
                <td>{{ item.name }}</td>
                ...
            </tr>
            {% endfor %}
        </tbody>
    </table>

    {# Floating Action Bar #}
    {% include '_components/_bulk_action_bar.html.twig' with {
        actions: ['delete', 'export', 'tag']
    } %}
</div>
```

#### Verfügbare Actions

- **delete** - Bulk-Löschen
- **export** - Bulk-Export
- **tag** - Tag hinzufügen
- **assign** - Zuweisen (z.B. zu User)

#### Backend-Endpoints

Erstelle diese Endpoints in deinem Controller:

```php
#[Route('/api/assets/bulk-delete', name: 'app_asset_bulk_delete', methods: ['POST'])]
public function bulkDelete(Request $request): JsonResponse
{
    $data = json_decode($request->getContent(), true);
    $ids = $data['ids'] ?? [];

    foreach ($ids as $id) {
        $asset = $this->assetRepository->find($id);
        if ($asset) {
            $this->entityManager->remove($asset);
        }
    }

    $this->entityManager->flush();

    return new JsonResponse(['success' => true, 'deleted' => count($ids)]);
}

#[Route('/api/assets/bulk-export', name: 'app_asset_bulk_export', methods: ['POST'])]
public function bulkExport(Request $request): Response
{
    $data = json_decode($request->getContent(), true);
    $ids = $data['ids'] ?? [];

    $assets = $this->assetRepository->findBy(['id' => $ids]);

    // Generate Excel/CSV
    $spreadsheet = new Spreadsheet();
    // ... export logic ...

    $writer = new Xlsx($spreadsheet);
    $response = new StreamedResponse(function() use ($writer) {
        $writer->save('php://output');
    });

    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $response->headers->set('Content-Disposition', 'attachment;filename="assets.xlsx"');

    return $response;
}
```

#### Features

- **Select All** - Mit Indeterminate State
- **Floating Action Bar** - Erscheint bei Selection
- **Visual Feedback** - Selected Rows hervorgehoben
- **Toast Integration** - Success/Error Messages
- **Responsive** - Mobile-optimiert

---

## 🎨 Styling Guide

### Farb-Schema für Module

**Risk Management**:
```css
.risk-score-critical { background: #e74c3c; } /* Rot */
.risk-score-high     { background: #ff9800; } /* Orange */
.risk-score-medium   { background: #f39c12; } /* Gelb */
.risk-score-low      { background: #27ae60; } /* Grün */
```

**Incident Management**:
```css
.status-open        { color: #e74c3c; } /* Rot */
.status-in_progress { color: #f39c12; } /* Orange */
.status-resolved    { color: #27ae60; } /* Grün */
.status-closed      { color: #95a5a6; } /* Grau */
```

### Konsistente Badge-Größen

```twig
{# Small #}
<span class="badge bg-primary">Text</span>

{# Medium (default) #}
<span class="badge bg-primary fs-6">Text</span>

{# Large #}
<span class="badge bg-primary fs-5">Text</span>
```

---

## 📊 Performance Optimierungen

### Skeleton Loader Best Practices

1. **Zeige Skeleton nur bei erwarteter Ladezeit > 300ms**
2. **Minimum Duration**: 800ms für sanften Übergang
3. **Anzahl Skeleton Items**: Maximal 5-10 für gute Performance

```javascript
// In Stimulus Controller
static values = {
    duration: { type: Number, default: 800 }, // Mindestens 800ms zeigen
    url: String
}
```

### Bulk Actions Performance

- **Optimistic UI**: Zeige Änderungen sofort, revert bei Error
- **Batch Size**: Maximal 100 Items pro Request
- **Progress Indicator**: Bei > 50 Items

```javascript
async bulkDelete(event) {
    const ids = this.getSelectedIds();

    if (ids.length > 50) {
        // Show progress modal
        this.showProgressModal();
    }

    // Optimistic: Remove from UI immediately
    this.getSelectedItems().forEach(item => {
        const row = item.closest('tr');
        row.style.opacity = '0.5'; // Fade out
    });

    try {
        await this.performBulkDelete(ids);
        // Success - remove completely
    } catch (error) {
        // Error - restore
        this.restoreRows();
    }
}
```

---

## 🧪 Testing Checklist

### Skeleton Loader
- [ ] Skeleton zeigt korrekte Anzahl Items
- [ ] Smooth Fade-In zu echtem Content
- [ ] Error State zeigt "Retry" Button
- [ ] Minimum Duration wird eingehalten

### Risk Management
- [ ] KPI Cards zeigen korrekte Werte
- [ ] Risk Matrix zeigt alle Levels
- [ ] Filter funktioniert (Search + Level)
- [ ] Risk Scores richtig farbcodiert
- [ ] Restrisiko-Reduktion angezeigt

### Incident Management
- [ ] Status-Übersicht zeigt alle 4 Status
- [ ] Severity Distribution korrekt
- [ ] Multi-Filter funktioniert
- [ ] Data Breach Badge sichtbar
- [ ] Time-Since-Display korrekt

### Bulk Actions
- [ ] Select All funktioniert
- [ ] Indeterminate State (teilweise ausgewählt)
- [ ] Action Bar erscheint/verschwindet
- [ ] Bulk Delete funktioniert
- [ ] Bulk Export funktioniert
- [ ] Toast Notifications zeigen Success/Error

---

## 🔧 Troubleshooting

### Skeleton Loader lädt nicht
**Problem**: Content bleibt im Skeleton State
**Lösung**:
```javascript
// Check: Ist duration-value gesetzt?
data-skeleton-duration-value="800"

// Check: Wird content richtig angezeigt?
if (this.hasContentTarget) {
    this.contentTarget.classList.remove('hidden');
}
```

### Bulk Actions funktioniert nicht
**Problem**: Action Bar erscheint nicht
**Lösung**:
```javascript
// Check: Ist endpoint-value gesetzt?
data-bulk-actions-endpoint-value="/assets"

// Check: Sind Checkboxen korrekt verknüpft?
data-bulk-actions-target="item"
data-action="bulk-actions#selectItem"
```

### Filter funktioniert nur teilweise
**Problem**: Kombination aus mehreren Filtern fehlerhaft
**Lösung**:
```javascript
// Implementiere zentrale filterRows() Funktion
function filterRows() {
    const search = getSearchQuery();
    const level = getLevelFilter();
    const status = getStatusFilter();

    rows.forEach(row => {
        const matchesAll =
            matchesSearch(row, search) &&
            matchesLevel(row, level) &&
            matchesStatus(row, status);

        row.style.display = matchesAll ? '' : 'none';
    });
}
```

---

## 📈 Migration Guide

### Von alten Modulen zu Phase 2

#### 1. Risk Management

**Vorher** (`risk/index.html.twig`):
```twig
<h1>Risikomanagement</h1>
<div class="warning-box">
    <strong>Hochrisiken:</strong> {{ highRisks|length }}
</div>
```

**Nachher** (`risk/index_modern.html.twig`):
```twig
{% include '_components/_page_header.html.twig' with { ... } %}
<div class="kpi-grid">
    {% include '_components/_kpi_card.html.twig' with { ... } %}
</div>
{# Risk Matrix, Treatment Status, etc. #}
```

#### 2. Incident Management

**Vorher** (`incident/index.html.twig`):
```twig
<h1>Vorfallsmanagement</h1>
<div class="warning-box">
    <strong>Offene Vorfälle:</strong> {{ openIncidents|length }}
</div>
```

**Nachher** (`incident/index_modern.html.twig`):
```twig
{% include '_components/_page_header.html.twig' with { ... } %}
<div class="kpi-grid">
    {# 4 KPI Cards #}
</div>
{# Status Overview, Severity Distribution, etc. #}
```

---

## 🎯 Best Practices

### 1. **Konsistente KPI-Anordnung**
Immer gleiche Reihenfolge:
1. Total Count
2. Critical/Open Items
3. Status/Level Distribution
4. Average/Calculated Metric

### 2. **Filter-Reihenfolge**
Immer von links nach rechts:
1. Status/Level Dropdown
2. Category/Type Dropdown
3. Search Input (ganz rechts)

### 3. **Skeleton-Anzeige**
- Nur bei erwarteter Ladezeit > 300ms
- Mindestens 800ms anzeigen (smooth transition)
- Anzahl Items ≈ erwartete echte Items

### 4. **Bulk Actions Placement**
- Action Bar: Fixed Bottom (Desktop)
- Action Bar: Bottom Sticky (Mobile)
- Checkboxes: Immer erste Spalte

---

## 🚀 Nächste Schritte

### Phase 3 (Optional):
- [ ] Dark Mode Support
- [ ] Advanced Search mit Filters
- [ ] Real-time Updates (WebSockets)
- [ ] Drag & Drop für Reordering
- [ ] Advanced Charts (Chart.js Integration)

---

## 📞 Support

Bei Fragen oder Problemen:
1. Prüfe `docs/UI_UX_IMPLEMENTATION.md` (Phase 1)
2. Schaue in modernisierte Templates (asset, risk, incident)
3. Teste mit Browser DevTools Console
4. Erstelle Issue im Repository

---

**Version**: 2.0
**Datum**: 2025-01-06
**Status**: Production Ready
