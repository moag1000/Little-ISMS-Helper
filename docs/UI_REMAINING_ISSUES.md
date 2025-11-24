# Verbleibende UI/UX-Probleme - Little ISMS Helper

**Datum:** 2025-11-24
**Status nach Phase 1:** 8 Commits, 5 Pattern-Guides erstellt

---

## ✅ Erledigte Issues (Phase 1)

### Komplett gelöst:
- ✅ **Issue 1.1** - Button-Styling (4 Buttons standardisiert, Dokumentation)
- ✅ **Issue 2.1** - Formular-Patterns (Form Field Component dokumentiert)
- ✅ **Issue 2.2** - Required Field Indicators (im Form Component integriert)
- ✅ **Issue 2.3** - Form Legend Accessibility (dokumentiert)
- ✅ **Issue 3.2** - Card Header (66 Headers standardisiert: h5.mb-0)
- ✅ **Issue 4.1** - Table Styling (128 Templates nutzen Component)
- ✅ **Issue 5.x** - Badge Patterns (20 Syntax-Fixes, Component erstellt)
- ✅ **Issue 6.2** - ARIA Labels (30 Close-Buttons gefixt)
- ✅ **Issue 7.1** - Inline Styles (43 Farben → CSS-Variablen)
- ✅ **Issue 9.1** - Dark Mode Hardcoded Colors (43 Fixes)
- ✅ **Issue 9.2** - Dark Mode Custom Components (dokumentiert)
- ✅ **Issue 9.3** - Dark Mode Form Controls (dokumentiert)
- ✅ **Issue 11.x** - Modal Dialogs (umfassende Dokumentation)
- ✅ **Issue 12.2** - Mobile Table Overflow (Component = responsive by default)
- ✅ **Issue 13.3** - Interactive Elements ARIA (dokumentiert)

---

## 🔄 Verbleibende Issues

### **HOCH-PRIORITÄT** (High/Critical)

#### Issue 3.1: Multiple Card Style Implementations
**Severity:** High
**Status:** 🟡 Teilweise gelöst (Component dokumentiert, aber Nutzung inkonsistent)
**Verbleibende Arbeit:**
- 173 Templates mit `card-header` analysieren
- Einige nutzen noch rohe `<div class="card">` statt Component
- Migration zu `_card.html.twig` für konsistente Styles

**Aufwand:** Medium (2-3h)
**Script:** `scripts/migrate_to_card_component.py` (erstellen)

---

#### Issue 4.2: Sticky Table Headers Implementation
**Severity:** Medium
**Status:** ⚪ Nicht begonnen
**Beschreibung:**
- Sticky Headers inkonsistent implementiert
- Manche nutzen `position: sticky`, andere JavaScript
- Component unterstützt `stickyHeader: true`, aber nicht überall genutzt

**Verbleibende Arbeit:**
- Long tables identifizieren (>20 rows)
- `stickyHeader: true` Parameter hinzufügen
- Custom Sticky-Implementierungen entfernen

**Aufwand:** Niedrig (1h)

---

#### Issue 7.2: Utility Class Explosion
**Severity:** Medium
**Status:** ⚪ Nicht begonnen
**Beschreibung:**
- Zu viele custom utility classes in CSS-Dateien
- Überlappung mit Bootstrap utilities
- Inkonsistente Benennung

**Beispiele:**
```css
/* Duplikate mit Bootstrap */
.mt-10 { margin-top: 10px; }  /* Bootstrap hat .mt-3 */
.mb-20 { margin-bottom: 20px; }
.text-small { font-size: 0.875rem; }  /* Bootstrap hat .small */
```

**Verbleibende Arbeit:**
- CSS-Audit durchführen
- Duplizierte Utilities identifizieren
- Auf Bootstrap-Standard migrieren
- Custom utilities dokumentieren (wenn notwendig)

**Aufwand:** Medium (2h)
**Script:** `scripts/audit_utility_classes.py`

---

#### Issue 7.3: Inconsistent Spacing Scale
**Severity:** Medium
**Status:** ⚪ Nicht begonnen
**Beschreibung:**
- Verschiedene Spacing-Skalen in verschiedenen Dateien
- Bootstrap nutzt 0.25rem Basis (0, 0.25, 0.5, 1, 1.5, 3)
- Custom CSS nutzt px-Werte

**Verbleibende Arbeit:**
- Spacing-Audit durchführen
- CSS-Variablen für Spacing standardisieren
- Templates auf Bootstrap spacing utilities migrieren

**Aufwand:** Medium (2h)

---

#### Issue 8.1: Heading Hierarchy Issues
**Severity:** Medium
**Status:** 🟡 Teilweise gelöst (Card headers = h5)
**Verbleibende Arbeit:**
- Page-level heading hierarchy prüfen
- Sicherstellen: h1 → h2 → h3 (keine Sprünge)
- Spezielle Fälle: Modal titles, sidebar headings

**Aufwand:** Medium (2-3h)
**Script:** `scripts/audit_heading_hierarchy.py`

---

### **MITTEL-PRIORITÄT** (Medium)

#### Issue 1.2: Inconsistent Button Group Usage
**Severity:** Medium
**Status:** ⚪ Nicht begonnen
**Templates:** 87 Templates

**Verbleibende Arbeit:**
- Action-Buttons in Tables standardisieren
- Überall `<div class="btn-group btn-group-sm">` nutzen
- Dokumentation in BUTTON_PATTERNS.md ergänzen

**Aufwand:** Niedrig (1h)

---

#### Issue 5.2: Status Badge Naming Inconsistency
**Severity:** Low
**Status:** ⚪ Nicht begonnen

**Beschreibung:**
- Inkonsistente Badge-Texte für gleiche Status
- Beispiele: "Active" vs "Aktiv" vs "Enabled"

**Verbleibende Arbeit:**
- Status-Mapping definieren
- Translation keys standardisieren
- Badge-Komponente um Status-Helper erweitern

**Aufwand:** Niedrig (1h)

---

#### Issue 10.1: Active Link State Variations
**Severity:** Medium
**Status:** ⚪ Nicht begonnen

**Beschreibung:**
- Navigation Active-States inkonsistent
- Manche nutzen `.active`, andere CSS-only, andere JavaScript

**Verbleibende Arbeit:**
- Navigation-Komponente für Active-States erstellen
- Twig-Makro für `{{ is_active_route() }}`
- Dokumentation erstellen

**Aufwand:** Medium (2h)

---

#### Issue 10.2: Mobile Navigation Inconsistency
**Severity:** Medium
**Status:** ⚪ Nicht begonnen

**Verbleibende Arbeit:**
- Mobile-Menü auf allen Seiten prüfen
- Hamburger-Icon standardisieren
- Responsive-Breakpoints vereinheitlichen

**Aufwand:** Medium (2h)

---

#### Issue 11.2: Modal Header Styling Inconsistency
**Severity:** Medium
**Status:** 🟡 Teilweise gelöst (Dokumentation vorhanden)

**Verbleibende Arbeit:**
- Colored headers standardisieren (bg-danger für Delete, etc.)
- ARIA-Attribute überprüfen (nicht automatisiert möglich)
- Best practices aus Dokumentation umsetzen

**Aufwand:** Medium (2-3h, manuell)

---

#### Issue 12.1: Inconsistent Breakpoint Usage
**Severity:** Medium
**Status:** ⚪ Nicht begonnen

**Beschreibung:**
- Custom breakpoints in CSS anstatt Bootstrap-Standard
- Beispiele: `@media (max-width: 768px)` vs `@media (max-width: 767px)`

**Verbleibende Arbeit:**
- Bootstrap breakpoints dokumentieren
- Custom breakpoints auf Bootstrap migrieren
- Sass-Variablen nutzen: `$grid-breakpoints`

**Aufwand:** Niedrig (1h)

---

### **NIEDRIG-PRIORITÄT** (Low)

#### Issue 1.3: Icon-Button Consistency
**Severity:** Low
**Status:** 🟡 Teilweise dokumentiert

**Verbleibende Arbeit:**
- Icon-only Buttons mit `title` Attribut versehen
- Konsistente Icon-Größen

**Aufwand:** Niedrig (30min)

---

#### Issue 4.3: Table Cell Utility Classes Explosion
**Severity:** Low
**Status:** ⚪ Nicht begonnen

**Beschreibung:**
- Zu viele custom classes für table cells
- Beispiele: `.table-cell-status`, `.table-cell-actions`

**Verbleibende Arbeit:**
- Auf Bootstrap utilities migrieren (`.text-center`, `.text-end`)
- Custom classes nur wenn nötig

**Aufwand:** Niedrig (1h)

---

#### Issue 6.1: Mixed Icon Libraries
**Severity:** Low
**Status:** ⚪ Nicht begonnen

**Beschreibung:**
- Hauptsächlich Bootstrap Icons, aber einzelne Font Awesome Icons
- Inkonsistent

**Verbleibende Arbeit:**
- Alle Font Awesome Icons identifizieren
- Auf Bootstrap Icons migrieren
- Dokumentation: Icon usage guide

**Aufwand:** Niedrig (1h)

---

#### Issue 8.2: Text Size Utility Overload
**Severity:** Low
**Status:** ⚪ Nicht begonnen

**Beschreibung:**
- Zu viele custom text size classes
- Bootstrap hat `.fs-1` bis `.fs-6`

**Verbleibende Arbeit:**
- Custom classes auf Bootstrap migrieren
- `.text-small` → `.small` oder `.fs-6`

**Aufwand:** Niedrig (30min)

---

#### Issue 13.1: Missing Skip Links
**Severity:** Low (Accessibility)
**Status:** ⚪ Nicht begonnen

**Beschreibung:**
- Keine Skip-to-Content Links für Keyboard-Navigation
- WCAG 2.1 Best Practice

**Verbleibende Arbeit:**
- Skip-Link in `base.html.twig` hinzufügen
- CSS für `.skip-link` erstellen
- Dokumentation in ACCESSIBILITY.md

**Aufwand:** Sehr niedrig (30min)

---

## 📊 Zusammenfassung

### Status-Übersicht:
- ✅ **Komplett gelöst:** 15 Issues
- 🟡 **Teilweise gelöst:** 5 Issues
- ⚪ **Nicht begonnen:** 13 Issues

### Nach Priorität:
- **Critical:** 0 verbleibend (1/1 gelöst)
- **High:** 2 verbleibend (5/7 gelöst)
- **Medium:** 10 verbleibend (7/17 gelöst)
- **Low:** 6 verbleibend (2/8 gelöst)

### Geschätzter Gesamt-Aufwand für verbleibende Issues:
- **High Priority:** ~4-6h
- **Medium Priority:** ~10-12h
- **Low Priority:** ~3-4h
- **TOTAL:** ~17-22h

---

## 🎯 Empfohlene Nächste Schritte

### Phase 2a - Quick Wins (2-3h):
1. ✅ Issue 13.1 - Skip Links (30min)
2. ✅ Issue 1.2 - Button Groups (1h)
3. ✅ Issue 5.2 - Status Badge Naming (1h)
4. ✅ Issue 4.2 - Sticky Table Headers (1h)

### Phase 2b - Cleanup (4-5h):
5. ✅ Issue 7.2 - Utility Class Audit (2h)
6. ✅ Issue 7.3 - Spacing Scale (2h)
7. ✅ Issue 8.2 - Text Size Utilities (30min)
8. ✅ Issue 6.1 - Icon Library Cleanup (1h)

### Phase 2c - Advanced (10-12h):
9. ✅ Issue 3.1 - Card Component Migration (2-3h)
10. ✅ Issue 8.1 - Heading Hierarchy Audit (2-3h)
11. ✅ Issue 10.1 - Navigation Active States (2h)
12. ✅ Issue 10.2 - Mobile Navigation (2h)
13. ✅ Issue 11.2 - Modal Header Standards (2-3h)

---

## 📝 Hinweise

### Automatisierung möglich:
- Issue 7.2 (Utility Classes Audit) - Python Script
- Issue 8.1 (Heading Hierarchy) - Python Script
- Issue 4.2 (Sticky Headers) - Python Script
- Issue 1.2 (Button Groups) - Python Script

### Manuelle Arbeit erforderlich:
- Issue 10.1/10.2 (Navigation) - Komponente erstellen
- Issue 11.2 (Modal Headers) - Template-by-Template Review
- Issue 6.1 (Icons) - Manuelles Ersetzen + Test

### Dokumentation erweitern:
- NAVIGATION_PATTERNS.md (neu)
- COLOR_PALETTE.md (neu)
- SPACING_SYSTEM.md (neu)
- Icon Usage Guide in ACCESSIBILITY.md

---

**Letzte Aktualisierung:** 2025-11-24
**Nächster Review:** Nach Phase 2a
