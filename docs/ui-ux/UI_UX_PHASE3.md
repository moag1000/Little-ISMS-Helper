# 🚀 UI/UX Phase 3 - Additional Module Modernizations

## Übersicht

Phase 3 erweitert die Modernisierungen auf zusätzliche kritische ISMS-Module: **Audit** und **Document Management**. Diese Module profitieren von allen Phase 1 & 2 Features.

---

## ✨ Modernisierte Module

### 1. Internal Audit Module

**Template**: `templates/audit/index_modern.html.twig`

**Warum**: Das Audit-Modul war sehr rudimentär (nur 22 Zeilen) und benötigte dringend eine vollständige Überarbeitung für professionelle ISO 27001-Compliance.

#### Features

##### KPI Dashboard
- **Gesamt Audits**: Überblick über alle geplanten Audits
- **Bevorstehend**: Anzahl der anstehenden Audits
- **In Bearbeitung**: Aktive Audits
- **Abgeschlossen**: Erfolgreich abgeschlossene Audits

##### Status-Übersicht
Visuelle Darstellung der Audit-Stati mit Farbcodierung:
- **Geplant** (Blau): `planned` - Audits in Planung
- **In Bearbeitung** (Orange): `in_progress` - Laufende Audits
- **Abgeschlossen** (Grün): `completed` - Fertiggestelle Audits
- **Abgebrochen** (Grau): `cancelled` - Stornierte Audits

##### Audit-Typen
- **Vollständig** (🏢): Komplettes System-Audit
- **Prozess** (📊): Prozess-spezifisches Audit
- **Standort** (📍): Standort-spezifisches Audit
- **Abteilung** (👥): Abteilungs-spezifisches Audit
- **Framework** (🛡️): Framework-spezifisches Audit (TISAX, DORA, etc.)
- **Asset** (💻): Asset-spezifisches Audit

##### Erweiterte Funktionen
- **Smart Filtering**: Status-Filter + Volltext-Suche
- **Breadcrumb Navigation**: Dashboard → Internes Audit
- **Quick Actions**: Direktlinks zu Details, Bearbeiten, PDF-Export
- **Upcoming Audits Alert**: Prominente Hervorhebung bevorstehender Audits
- **Empty State**: Freundliche Nachricht bei leerer Audit-Liste

#### Verwendung

**Controller Update** (Optional - funktioniert auch mit bestehendem Controller):

```php
// In AuditController.php - Die bestehende index() Methode funktioniert bereits!
// Kein Code-Update notwendig, aber du kannst optional umnennen:

#[Route('/', name: 'app_audit_index')]
public function index(): Response
{
    $audits = $this->auditRepository->findAll();
    $upcoming = $this->auditRepository->findUpcoming();

    // Option 1: Modernized Template verwenden
    return $this->render('audit/index_modern.html.twig', [
        'audits' => $audits,
        'upcoming' => $upcoming,
    ]);

    // Option 2: Als Standard setzen (altes Template umbenennen)
    // Benenne audit/index.html.twig → audit/index_old.html.twig
    // Benenne audit/index_modern.html.twig → audit/index.html.twig
}
```

#### Data Requirements

Das Template erwartet folgende Daten:
- `audits` - Array von `InternalAudit` Entities
- `upcoming` - Array von bevorstehenden Audits (gefiltert nach `findUpcoming()`)

**Benötigte Entity-Felder**:
```php
class InternalAudit {
    private string $auditNumber;       // Z.B. "AUDIT-2025-001"
    private string $title;              // Z.B. "ISO 27001 Q1 2025"
    private ?string $scope;             // Optional: Beschreibung
    private string $scopeType;          // 'full', 'process', 'location', etc.
    private \DateTimeInterface $plannedDate;
    private string $status;             // 'planned', 'in_progress', 'completed', 'cancelled'
}
```

---

### 2. Document Management Module

**Template**: `templates/document/index_modern.html.twig`

**Warum**: Dokumente sind zentral für ISMS-Nachweise. Das Modul benötigte moderne UI mit Bulk-Operationen für effizientes Dokumentenmanagement.

#### Features

##### KPI Dashboard
- **Gesamt Dokumente**: Überblick über alle aktiven Dokumente
- **Gesamtgröße**: Summierte Speichergröße in MB
- **PDF-Dokumente**: Anzahl PDF-Dateien
- **Excel-Dokumente**: Anzahl Tabellendokumente

##### Dokumenttypen-Übersicht
Visuelle Darstellung der Dokumentverteilung:
- **PDF** (Rot 🗎): `application/pdf`
- **Bilder** (Blau 🖼️): `image/*`
- **Tabellen** (Grün 📊): `*spreadsheet*`
- **Sonstige** (Grau 📄): Alle anderen Typen

##### Bulk Actions Integration
- **Multi-Select**: Mehrere Dokumente gleichzeitig auswählen
- **Bulk Delete**: Massenhafte Löschung (mit Bestätigung)
- **Bulk Export**: Export mehrerer Dokumente als ZIP
- **Visual Feedback**: Selected Rows werden hervorgehoben
- **Floating Action Bar**: Erscheint bei Auswahl

##### Smart Filtering
- **Kategorie-Filter**: Dropdown mit allen verfügbaren Kategorien
- **Volltext-Suche**: Suche nach Dateinamen
- **Real-time Filtering**: Sofortiges Filtern ohne Page Reload
- **No Results State**: Freundliche Nachricht bei leerer Suchergebnissen

##### File Type Icons
Automatische Icon-Auswahl basierend auf MIME-Type:
- 📕 PDF: `bi-file-pdf-fill text-danger`
- 🖼️ Images: `bi-file-image-fill text-primary`
- 📊 Excel: `bi-file-earmark-spreadsheet-fill text-success`
- 📝 Word: `bi-file-word-fill text-info`
- 📄 Other: `bi-file-earmark-fill text-secondary`

#### Verwendung

**Controller Update** (Optional):

```php
// In DocumentController.php
#[Route('/', name: 'app_document_index')]
public function index(): Response
{
    $documents = $this->documentRepository->findBy(
        ['status' => 'active'],
        ['uploadedAt' => 'DESC']
    );

    // stats wird im Template selbst berechnet, aber du kannst es optional mitgeben:
    $stats = [
        'total' => count($documents),
        'total_size' => array_reduce($documents, fn($carry, $doc) => $carry + $doc->getFileSize(), 0)
    ];

    return $this->render('document/index_modern.html.twig', [
        'documents' => $documents,
        'stats' => $stats, // Optional
    ]);
}
```

**Backend-Endpoints für Bulk Actions**:

```php
#[Route('/document/bulk-delete', name: 'app_document_bulk_delete', methods: ['POST'])]
public function bulkDelete(Request $request): JsonResponse
{
    $data = json_decode($request->getContent(), true);
    $ids = $data['ids'] ?? [];

    foreach ($ids as $id) {
        $document = $this->documentRepository->find($id);
        if ($document) {
            // Optional: Delete physical file
            $filePath = $this->projectDir . '/public/uploads/documents/' . $document->getFileName();
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $this->entityManager->remove($document);
        }
    }

    $this->entityManager->flush();

    return new JsonResponse(['success' => true, 'deleted' => count($ids)]);
}

#[Route('/document/bulk-export', name: 'app_document_bulk_export', methods: ['POST'])]
public function bulkExport(Request $request): Response
{
    $data = json_decode($request->getContent(), true);
    $ids = $data['ids'] ?? [];

    $documents = $this->documentRepository->findBy(['id' => $ids]);

    // Create ZIP archive
    $zipFileName = 'documents_' . date('Y-m-d_H-i-s') . '.zip';
    $zipPath = sys_get_temp_dir() . '/' . $zipFileName;

    $zip = new \ZipArchive();
    if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
        foreach ($documents as $document) {
            $filePath = $this->projectDir . '/public/uploads/documents/' . $document->getFileName();
            if (file_exists($filePath)) {
                $zip->addFile($filePath, $document->getOriginalFilename());
            }
        }
        $zip->close();
    }

    $response = new BinaryFileResponse($zipPath);
    $response->headers->set('Content-Type', 'application/zip');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $zipFileName . '"');
    $response->deleteFileAfterSend(true);

    return $response;
}
```

#### Data Requirements

Das Template erwartet:
- `documents` - Array von `Document` Entities

**Benötigte Entity-Felder**:
```php
class Document {
    private string $originalFilename;   // Original file name
    private string $mimeType;            // e.g., 'application/pdf'
    private int $fileSize;               // Size in bytes
    private string $category;            // e.g., 'policy', 'evidence'
    private ?string $description;        // Optional description
    private User $uploadedBy;            // User who uploaded
    private \DateTimeInterface $uploadedAt;

    // Helper method (optional)
    public function getFileSizeFormatted(): string {
        $bytes = $this->fileSize;
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }
}
```

---

## 🎨 Design Consistency

### Status-Farbschema (Audit)

```css
.status-planned      { color: #3498db; background: rgba(52, 152, 219, 0.1); }
.status-in_progress  { color: #f39c12; background: rgba(243, 156, 18, 0.1); }
.status-completed    { color: #27ae60; background: rgba(39, 174, 96, 0.1); }
.status-cancelled    { color: #95a5a6; background: rgba(149, 165, 166, 0.1); }
```

### Dokumenttyp-Farbschema (Document)

```css
.type-pdf       { color: #e74c3c; background: rgba(231, 76, 60, 0.1); }
.type-image     { color: #3498db; background: rgba(52, 152, 219, 0.1); }
.type-excel     { color: #27ae60; background: rgba(39, 174, 96, 0.1); }
.type-other     { color: #95a5a6; background: rgba(149, 165, 166, 0.1); }
```

---

## 📊 Features im Überblick

### Beide Module verwenden

| Feature | Audit | Document |
|---------|-------|----------|
| Breadcrumb Navigation | ✅ | ✅ |
| Page Header mit Icon | ✅ | ✅ |
| KPI Cards (4x) | ✅ | ✅ |
| Status/Typ-Übersicht | ✅ | ✅ |
| Smart Filtering | ✅ | ✅ |
| Responsive Design | ✅ | ✅ |
| Empty State | ✅ | ✅ |
| Bulk Actions | ❌ | ✅ |
| Export Buttons | ✅ | ✅ |
| Visual Type Icons | ✅ | ✅ |

---

## 🧪 Testing Checklist

### Internal Audit Module
- [ ] KPI Cards zeigen korrekte Werte (Total, Upcoming, In Progress, Completed)
- [ ] Status-Übersicht zeigt alle 4 Stati
- [ ] Status-Filter funktioniert (geplant, in_progress, completed, cancelled)
- [ ] Volltext-Suche durchsucht Titel und Audit-Nummer
- [ ] Upcoming Audits Alert erscheint bei > 0 bevorstehenden Audits
- [ ] Empty State zeigt bei keinen Audits
- [ ] Audit-Typ-Icons werden korrekt angezeigt
- [ ] Breadcrumb Navigation funktioniert
- [ ] Quick Actions (View, Edit, PDF) funktionieren
- [ ] Responsive Design auf Mobile

### Document Management Module
- [ ] KPI Cards zeigen korrekte Werte (Total, Size, PDF, Excel)
- [ ] Dokumenttypen-Übersicht zeigt korrekte Verteilung
- [ ] Kategorie-Filter funktioniert
- [ ] Volltext-Suche durchsucht Dateinamen
- [ ] Bulk Select All funktioniert
- [ ] Bulk Delete mit Bestätigung
- [ ] Bulk Export erstellt ZIP
- [ ] File Type Icons werden korrekt angezeigt
- [ ] Selected Rows werden hervorgehoben
- [ ] Floating Action Bar erscheint bei Selection
- [ ] Empty State zeigt bei keinen Dokumenten
- [ ] Responsive Design auf Mobile

---

## 🔧 Migration von Alt zu Neu

### Audit Module

**Schritt 1**: Backup erstellen
```bash
cp templates/audit/index.html.twig templates/audit/index_old.html.twig
```

**Schritt 2**: Modern Template als Standard setzen
```bash
mv templates/audit/index_modern.html.twig templates/audit/index.html.twig
```

**Schritt 3**: Controller aktualisieren (optional)
```php
// Kein Update notwendig! Template-Namen bleibt 'audit/index.html.twig'
```

**Schritt 4**: Testen
- Navigiere zu `/audit`
- Prüfe KPI Cards
- Teste Filter
- Erstelle Test-Audit

**Rollback** (falls notwendig):
```bash
mv templates/audit/index_old.html.twig templates/audit/index.html.twig
```

### Document Module

**Schritt 1**: Backup erstellen
```bash
cp templates/document/index.html.twig templates/document/index_old.html.twig
```

**Schritt 2**: Modern Template als Standard setzen
```bash
mv templates/document/index_modern.html.twig templates/document/index.html.twig
```

**Schritt 3**: Bulk Actions Endpoints hinzufügen
```php
// Füge bulkDelete() und bulkExport() Methoden hinzu (siehe oben)
```

**Schritt 4**: Testen
- Navigiere zu `/document`
- Prüfe KPI Cards
- Teste Bulk Actions
- Upload Test-Dokument

**Rollback** (falls notwendig):
```bash
mv templates/document/index_old.html.twig templates/document/index.html.twig
```

---

## 🚀 Performance Optimierungen

### Audit Module
- **Lazy Loading**: Verwende `findUpcoming()` statt alle Audits zu filtern
- **Indexing**: Stelle sicher, dass `status` und `plannedDate` indiziert sind
- **Pagination**: Bei > 100 Audits, implementiere Pagination

```php
// Recommended Database Indexes
CREATE INDEX idx_audit_status ON internal_audit(status);
CREATE INDEX idx_audit_planned_date ON internal_audit(planned_date);
```

### Document Module
- **File Size Caching**: Cache `fileSizeFormatted` in Entity
- **Thumbnail Generation**: Generiere Thumbnails für Bilder
- **Lazy Loading**: Lade nur erste 50 Dokumente, dann Pagination

```php
// Recommended Database Indexes
CREATE INDEX idx_document_category ON document(category);
CREATE INDEX idx_document_uploaded_at ON document(uploaded_at);
CREATE INDEX idx_document_status ON document(status);
```

---

## 💡 Best Practices

### 1. **Konsistente Status-Namen**
Verwende immer lowercase mit underscores:
- ✅ `in_progress`
- ❌ `In Progress`, `inProgress`, `IN_PROGRESS`

### 2. **File Size Formatting**
Implementiere `getFileSizeFormatted()` in Entity für konsistente Anzeige:
```php
public function getFileSizeFormatted(): string {
    // ... siehe Data Requirements oben
}
```

### 3. **Bulk Actions Permissions**
Schütze Bulk-Endpoints mit entsprechenden Rollen:
```php
#[Route('/document/bulk-delete', name: 'app_document_bulk_delete', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]  // Nur Admins dürfen Bulk-Delete
public function bulkDelete(Request $request): JsonResponse {
    // ...
}
```

### 4. **Empty State CTAs**
Leite User immer zu sinnvollen Aktionen:
- Audit: "Neues Audit erstellen"
- Document: "Dokument hochladen"

### 5. **Breadcrumb Hierarchy**
Halte Breadcrumbs konsistent:
- Level 1: Dashboard
- Level 2: Modul (z.B. "Internes Audit")
- Level 3: Detail (z.B. "AUDIT-2025-001")

---

## 🔄 Integration mit Phase 1 & 2

### Verwendete Phase 1 Components
- ✅ `_breadcrumb.html.twig`
- ✅ `_page_header.html.twig`
- ✅ `_kpi_card.html.twig`
- ✅ `_empty_state.html.twig`
- ✅ Command Palette (automatisch)
- ✅ Toast Notifications (automatisch)
- ✅ Keyboard Shortcuts (automatisch)

### Verwendete Phase 2 Components
- ✅ `_bulk_action_bar.html.twig` (nur Document)
- ✅ Bulk Actions Controller (nur Document)
- ✅ Status/Type Overview Cards (eigene Implementierung basierend auf Risk/Incident Pattern)

---

## 📈 Ergebnis

### Audit Module
**Vorher**:
- 22 Zeilen Code
- Nur Text-Übersicht
- Keine Filter
- Keine KPIs
- Placeholder-Text: "wird in der nächsten Phase implementiert"

**Nachher**:
- 460+ Zeilen Feature-reiches Template
- 4 KPI Cards
- Visual Status Overview
- Smart Filtering (Status + Search)
- Typ-Icons mit Bedeutung
- Upcoming Audits Highlight
- Professional Enterprise UI

### Document Module
**Vorher**:
- Basic HTML Table
- 2 Simple Stats
- Keine Filter
- Keine Bulk Actions
- Standard File Icons

**Nachher**:
- 4 KPI Cards mit berechneten Metriken
- Visual Dokumenttypen-Übersicht
- Smart Filtering (Category + Search)
- Full Bulk Actions Support
- Context-aware File Type Icons
- Professional Enterprise UI

---

## 📞 Support

Bei Fragen oder Problemen:
1. Prüfe `docs/UI_UX_IMPLEMENTATION.md` (Phase 1)
2. Prüfe `docs/UI_UX_PHASE2.md` (Phase 2)
3. Schaue in modernisierte Templates (audit, document)
4. Teste mit Browser DevTools Console
5. Erstelle Issue im Repository

---

**Version**: 3.0
**Datum**: 2025-01-06
**Status**: Production Ready
**Abhängigkeiten**: Phase 1 + Phase 2
