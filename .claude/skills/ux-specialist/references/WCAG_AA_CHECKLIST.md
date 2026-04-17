# WCAG 2.2 Level AA — Checkliste

> WCAG 2.2 (W3C Recommendation, 12. Dezember 2024) ersetzt WCAG 2.1.
> Rueckwaertskompatibel: Alles was WCAG 2.2 erfuellt, erfuellt auch 2.1.
> **9 neue Success Criteria** gegenueber WCAG 2.1 (davon 4 auf Level AA).

## 1. Wahrnehmbar (Perceivable)

### 1.1 Textalternativen
- [ ] Alle nicht-textuellen Inhalte haben Textalternativen (alt, aria-label)
- [ ] Dekorative Bilder: `alt=""` oder `role="presentation"`
- [ ] Icons mit Bedeutung: `aria-label` oder `title`
- [ ] Form-Inputs: Sichtbares `<label>` oder `aria-label`

### 1.2 Zeitbasierte Medien
- [ ] Untertitel fuer Audio/Video (falls vorhanden)
- [ ] Audiodeskription fuer Video-only (falls vorhanden)

### 1.3 Anpassbar
- [ ] **1.3.1** Semantisches HTML: Ueberschriften (h1-h6), Listen, Tabellen, Landmarks
- [ ] **1.3.2** Sinnvolle Reihenfolge im DOM = visuelle Reihenfolge
- [ ] **1.3.3** Anweisungen nicht nur auf sensorische Merkmale (Farbe, Form, Position)
- [ ] **1.3.4** Orientierung: Sowohl Portrait als auch Landscape unterstuetzt
- [ ] **1.3.5** Input-Zweck identifizierbar: `autocomplete`-Attribute

### 1.4 Unterscheidbar
- [ ] **1.4.1** Farbe nicht einziges Unterscheidungsmerkmal
- [ ] **1.4.3** Kontrast Text: mindestens **4.5:1** (normaler Text), **3:1** (grosser Text >=18pt/14pt bold)
- [ ] **1.4.4** Text skalierbar auf 200% ohne Funktionsverlust
- [ ] **1.4.5** Kein Text als Bild (ausser Logo)
- [ ] **1.4.10** Reflow: Kein horizontales Scrollen bei 320px Breite
- [ ] **1.4.11** Kontrast Nicht-Text-Elemente: **3:1** (UI-Komponenten, Grafiken)
- [ ] **1.4.12** Textabstand anpassbar (line-height 1.5x, paragraph spacing 2x, letter 0.12em, word 0.16em)
- [ ] **1.4.13** Content on Hover/Focus: Dismissable, Hoverable, Persistent

## 2. Bedienbar (Operable)

### 2.1 Tastaturzugaenglich
- [ ] **2.1.1** Alle Funktionen per Tastatur bedienbar
- [ ] **2.1.2** Kein Keyboard Trap (Fokus kann immer verlassen werden)
- [ ] **2.1.4** Character Key Shortcuts abschaltbar/aenderbar

### 2.2 Ausreichend Zeit
- [ ] **2.2.1** Zeitlimits anpassbar/abschaltbar (Session-Timeout mit Warnung)
- [ ] **2.2.2** Automatisch bewegte Inhalte pausierbar

### 2.3 Anfaelle und koerperliche Reaktionen
- [ ] **2.3.1** Kein Blinken >3x pro Sekunde

### 2.4 Navigierbar
- [ ] **2.4.1** Skip-Links ("Zum Inhalt springen")
- [ ] **2.4.2** Aussagekraeftige Seitentitel (`<title>`)
- [ ] **2.4.3** Sinnvolle Tab-Reihenfolge
- [ ] **2.4.4** Linkzweck aus Kontext erkennbar (nicht "hier klicken")
- [ ] **2.4.5** Mehrere Navigationswege (Menu + Suche + Sitemap)
- [ ] **2.4.6** Ueberschriften und Labels beschreibend
- [ ] **2.4.7** Fokus sichtbar (`:focus-visible` Styles)
- [ ] **2.4.11** Focus Not Obscured (Minimum) — **NEU WCAG 2.2 AA**: Fokussiertes Element darf nicht vollstaendig verdeckt sein (durch Sticky Header, Modals, etc.)

### 2.5 Eingabemodalitaeten
- [ ] **2.5.1** Zeigergesten-Alternativen (kein Multitouch-Zwang)
- [ ] **2.5.2** Zeigereingabe abbrechen (mouseup statt mousedown)
- [ ] **2.5.3** Label im Namen: Sichtbares Label = accessible name
- [ ] **2.5.4** Bewegungsausloeser abschaltbar
- [ ] **2.5.7** Dragging Movements — **NEU WCAG 2.2 AA**: Drag-Operationen muessen per Single-Pointer-Alternative bedienbar sein (kein Drag-Zwang)
- [ ] **2.5.8** Target Size (Minimum) — **NEU WCAG 2.2 AA**: Interaktive Elemente min. 24x24 CSS-Pixel (Ausnahmen: Inline-Links, Browser-Default, nicht vom Autor kontrolliert)

## 3. Verstaendlich (Understandable)

### 3.1 Lesbar
- [ ] **3.1.1** Seitensprache: `<html lang="de">` / `<html lang="en">`
- [ ] **3.1.2** Sprachteile: `lang`-Attribut bei Sprachwechsel

### 3.2 Vorhersehbar
- [ ] **3.2.1** Bei Fokus: Keine Kontextaenderung
- [ ] **3.2.2** Bei Eingabe: Keine unerwartete Kontextaenderung
- [ ] **3.2.3** Konsistente Navigation ueber alle Seiten
- [ ] **3.2.4** Konsistente Identifikation (gleiche Funktion = gleiches Label)
- [ ] **3.2.6** Consistent Help — **NEU WCAG 2.2 A**: Hilfemechanismen (Kontakt, Chat, FAQ) immer an gleicher Stelle relativ zu anderen Inhalten

### 3.3 Hilfestellung bei Eingaben
- [ ] **3.3.1** Fehleridentifikation: Fehlermeldung beschreibt Problem
- [ ] **3.3.2** Labels und Anweisungen bei Eingabefeldern
- [ ] **3.3.3** Fehlerkorrektur: Vorschlaege wenn moeglich
- [ ] **3.3.4** Fehlervermeidung bei kritischen Aktionen (Bestaetigung/Pruefung/Rueckgaengig)
- [ ] **3.3.7** Redundant Entry — **NEU WCAG 2.2 A**: Bereits eingegebene Daten nicht erneut abfragen (Auto-Fill oder Auswahl aus vorherigen Eingaben)
- [ ] **3.3.8** Accessible Authentication (Minimum) — **NEU WCAG 2.2 AA**: Login darf keinen kognitiven Funktionstest erfordern (Raetsel, Muster merken). Passwort-Eingabe + Paste + Passwort-Manager muss funktionieren

## 4. Robust

### 4.1 Kompatibel
- [ ] **4.1.2** Name, Rolle, Wert fuer alle UI-Komponenten programmatisch bestimmbar
- [ ] **4.1.3** Statusmeldungen programmatisch bestimmbar (`aria-live`, `role="status"`)

## Projektspezifische WCAG-Anforderungen

### Formulare
- Jedes Input hat ein sichtbares `<label>` oder `aria-label`
- Validierungsfehler: `aria-invalid="true"` + `aria-describedby` auf Fehlermeldung
- Required-Felder: `aria-required="true"` oder `required`
- Gruppenbildung: `<fieldset>` + `<legend>` bei zusammengehoerenden Feldern

### Tabellen
- `<th scope="col|row">` fuer Header-Zellen
- `aria-sort` fuer sortierbare Spalten
- Leere Tabellen: Nachricht statt leerer `<tbody>`

### Modale/Dialoge
- `role="dialog"` + `aria-modal="true"` + `aria-labelledby`
- Fokus beim Oeffnen in Dialog verschieben
- Fokus-Trap innerhalb des Dialogs
- Escape schliesst Dialog
- Fokus nach Schliessen zurueck zum Ausloeser

### Toast/Flash Messages
- `role="alert"` oder `aria-live="polite"`
- Nicht nur farblich unterschieden (Icon + Text)

### Fortschrittsanzeigen
- `role="progressbar"` + `aria-valuenow` + `aria-valuemin` + `aria-valuemax`
- Oder `aria-busy="true"` bei Ladezustaenden