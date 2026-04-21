# Guided-Tour-Plan (Phase 8G / Sprint 13)

**Erstellt:** 2026-04-21
**Scope:** Rollenbasierte Einweisungs-Tour für Erst-Nutzer
**Aufwand:** MVP ~4 FTE-d · Vollausbau ~8,5 FTE-d
**Status:** 📅 geplant — offene Entscheidungen am Ende

---

## 1. Problem & Zielbild

Nach Sprint 4–12 ist das Tool funktional junior-tauglich (Wizard,
3-Bucket-Applicability, Admin-Restructuring, Empty-State-CTAs,
Command Palette, Shortcut-Cheatsheet). Was fehlt, ist die **erste
Begegnungs-Phase**: Nach dem Setup-Wizard landet der Nutzer auf
`/dashboard` und sieht 47 Entities, 8 Module, 7 Mega-Menü-Kategorien,
23 Frameworks. Ohne Guidance geht der Time-to-First-Value auf Tage.

**Zielbild:** Der erste Login führt in < 5 min zu *„ich weiß wo ich
bin und was ich als Nächstes tue"* — rollenabhängig, freiwillig,
jederzeit wiederholbar.

---

## 2. Experten-Input (konsolidiert)

### 🎨 UX-Specialist

- **Library-Wahl:** `driver.js` (5 KB, Stimulus-kompatibel,
  aktive Maintenance, openSource). Shepherd.js (40 KB) zu groß für
  Importmap-Setup. Custom-Stimulus-Wrapper wäre ~1,5 FTE-d mehr
  und bringt bei < 10 Steps pro Tour keinen Mehrwert.
- **Opt-in statt Auto-Start:** Banner „3-Min-Tour starten?" statt
  Popover-Invasion — User-Control-Prinzip.
- **Re-Run-Mechanik:** Permanent erreichbar im Header-User-Dropdown
  unter „Mein Profil".
- **Persistenz:** Pausierbar + resumable via LocalStorage pro
  (User, Tour-ID).
- **Keyboard:** ←/→ Steps, ESC beenden, `?` öffnet Shortcut-Hilfe
  (existiert schon).
- **A11y:** `aria-live="polite"` bei Step-Wechsel, `prefers-reduced-
  motion` respektieren.
- **Mobile:** Tour auf < 768px deaktivieren (Popover klappen auf
  kleinen Screens nicht) — Fallback: statische Hilfe-Seite.

### 🧑‍🎓 Junior-Implementer (Primary Target)

> *„QMB-9001-Hintergrund. Meine 5-Min-Tour: 7 Stopps — Mega-Menü,
> Dashboard-KPIs, 9001-Bridge-Link, Command Palette (⌘P),
> Asset-CTA mit Schutzbedarf, Risk-CTA mit 5×5-Matrix, Shortcut-
> Hinweis. Max 6-7 Stopps, jederzeit überspringbar."*

### 🧑‍💼 Compliance-Manager

> *„5 Stopps: Framework-Dashboard, Mapping-Hub, /reuse, Portfolio-
> Report, Seed-Review-Queue. Kein Asset/Risk-Basics — ich bin
> GRC-erfahren."*

### 🎖️ CISO-Executive

> *„4 Stopps, 90 Sekunden: Board-One-Pager-Export, ISMS-Health-Score,
> Framework-Ampel, Incident-Timeline. Fertig."*

### 🧑‍🔧 ISB-Practitioner

> *„5 Stopps: SoA mit Bulk-Edit, Incident-Filter, Workflow-Queue,
> Audit-Log-Chain, Command-Palette. Operative Werkzeuge pur."*

### 🏢 Business-Risk-Owner

> *„2 Stopps, ~1 min: Meine-Risiken-Filter, Bestätigungs-Flow.
> Unbedingt Jargon vermeiden (Residual-Risk/Annex-A/SoA = böhmisch)."*

### 🧑‍⚖️ External-Auditor

> *„3 Stopps: Documents, Audit-Log, Export-Funktionen (PDF, Excel,
> Audit-Package-ZIP). Read-only-Scope explizit benennen."*

### 🧭 Senior-Consultant

> *„Tour als Demo-Script für Neukunden-Schulung. Drei Zusatzwünsche:*
> *(a) Tour als PDF-Guide exportierbar — für Consultant-Handouts.*
> *(b) Tour-Steps im Admin-Bereich editierbar (wenn Compliance-Policy-*
> *Admin aktiv) — sonst kann Consultant den Text nicht anpassen.*
> *(c) Tour-Completion als Checkbox im User-Profil — Audit-Nachweis*
> *für ISO 27001 A.6.3 Awareness-Training."*

### 📚 Domain-Specialist Content-Beiträge (kompakt)

- **ISMS-Specialist:** Junior-Tour-Fokus = SoA-Seite. Drei Unter-
  Stopps: Filter-Bar, Inline-Applicability-Editor, Export-PDF.
  Clauses 4-10 nicht in der Tour (zu abstrakt).
- **BSI-Specialist:** Nur wenn BSI-Grundschutz-Modul aktiv. Ein
  Stopp: Absicherungsstufen-Filter (Basis/Standard/Kern).
- **DPO-Specialist:** Nur wenn GDPR-Modul aktiv. Drei Stopps:
  Art.-30-VVT, Art.-35-DSFA, Art.-33-72h-Timer.
- **BCM-Specialist:** Nur wenn BCM-Modul aktiv. 4 Stopps: Business-
  Processes, BIA, BC-Plans, Exercises.
- **Risk-Management-Specialist:** 5×5-Matrix-Erklärung ist in
  Junior-Tour zwingend (nicht selbsterklärend). Einen Stopp mit
  Legende + Beispielrisk.

---

## 3. Rollenbasierte Tour-Matrix

| Rolle | Steps | Dauer | Kern-Stopps |
|-------|-------|-------|-------------|
| **Junior** | 7 | ~5 min | Dashboard → Mega-Menü → 9001-Bridge → ⌘P → Asset → Risk → Shortcuts |
| **CM** | 5 | ~3 min | Framework-Dashboard → Mapping-Hub → Reuse → Portfolio → Seed-Review |
| **CISO** | 4 | ~90 s | Board-Export → Health-Score → Framework-Ampel → Incident-Timeline |
| **ISB** | 5 | ~4 min | SoA → Incidents → Workflows → Audit-Log → ⌘P |
| **Risk-Owner** | 2 | ~1 min | Meine-Risiken → Bestätigungs-Flow |
| **Auditor** | 3 | ~2 min | Documents → Audit-Log → Export-PDF |

**Modul-bedingte Zusatz-Stopps** (dynamisch angehängt an Junior/ISB):

- BSI-Grundschutz-Modul aktiv → +1 Stopp (Absicherungsstufen)
- GDPR-Modul aktiv → +3 Stopps (VVT / DSFA / 72h-Timer)
- BCM-Modul aktiv → +4 Stopps (BP/BIA/BC-Plans/Exercises)

Rollen-spezifische Flags via `ROLE_*`-Grants + Modul-Status aus
`ModuleConfigurationService`.

---

## 4. Technischer Plan — Artefakte

```
src/
  Service/
    GuidedTourService.php       # Liefert rollenbasierte Steps als JSON,
                                # berücksichtigt aktive Module
  Entity/
    User.php                    # + completedTours: json (Array von Tour-IDs)
  Controller/
    GuidedTourController.php    # GET  /tour/steps/{role}   JSON-Steps
                                # POST /tour/complete/{id}  markiert als gemacht
                                # POST /tour/reset          Admin: tour-completion zurücksetzen

migrations/
  Version202604XX_guided_tours.php
                                # users.completed_tours JSON column (default [])

assets/
  packages/
    driver.js                   # via importmap (~5 KB)
  controllers/
    guided_tour_controller.js   # Stimulus-Wrapper um driver.js
                                # - liest Steps aus data-value
                                # - persistiert Current-Step in LocalStorage
                                # - aria-live-Announce bei Step-Wechsel
                                # - prefers-reduced-motion respektieren

templates/
  _components/
    _guided_tour.html.twig              # Tour-Modal-Container (Stimulus-mounted)
    _tour_trigger_banner.html.twig      # Dismissible Banner oben auf /dashboard
    _tour_launcher_menu_item.html.twig  # „Tour neu starten"-Eintrag im User-Dropdown

translations/
  guided_tour.de.yaml
  guided_tour.en.yaml           # ~200 Keys (6 Rollen × ~30 Step-Titles+Body)
```

### Trigger-Logik

1. **First-Login-Detection:** `User.completedTours` ist leer
   → dismissible Banner auf /dashboard: *„3-Min-Tour für Ihre Rolle
   starten?"* mit Buttons [Start] [Später] [Nicht wieder fragen].
2. **Tour-Role-Auto-Detect** basierend auf granted Roles:
   - `ROLE_AUDITOR` → Auditor-Tour
   - `ROLE_GROUP_CISO` / `ROLE_ADMIN` only → CISO-Tour
   - `ROLE_COMPLIANCE_MANAGER` → CM-Tour
   - `ROLE_ISB` / `ROLE_MANAGER` → ISB-Tour
   - `ROLE_RISK_OWNER` only → Risk-Owner-Tour
   - Fallback → Junior-Tour
   User kann im Banner die Rolle überschreiben (Dropdown).
3. **Re-Run:** Permanent im Header-User-Dropdown → Role-Picker.

### Persistenz

```json
User.completedTours = ["junior", "cm"]
```

- Banner zeigt nur Rollen, die noch fehlen.
- Admin-Report: *„Wer hat welche Tour durchlaufen?"* — Audit-Nachweis
  für ISO 27001 A.6.3 Awareness-Training (Consultant-Wunsch).

---

## 5. Sprint 13 Breakdown

| # | Item | FTE-d | Abhängigkeit |
|---|------|-------|--------------|
| S13-1 | `driver.js` via importmap + Stimulus-Wrapper | 0,5 | — |
| S13-2 | `GuidedTourService` + Role-Auto-Detect | 0,5 | — |
| S13-3 | `User.completedTours` Migration + Entity | 0,25 | — |
| S13-4 | Tour-Banner-Component + Dismiss-Cookie | 0,5 | S13-3 |
| S13-5 | Junior-Tour (7 Steps) | 1,0 | S13-1 |
| S13-6 | CM-Tour (5 Steps) | 0,75 | S13-1 |
| S13-7 | CISO-Tour (4 Steps) | 0,5 | S13-1 |
| S13-8 | ISB-Tour (5 Steps) | 0,75 | S13-1 |
| S13-9 | Risk-Owner-Tour (2 Steps) | 0,25 | S13-1 |
| S13-10 | Auditor-Tour (3 Steps) | 0,25 | S13-1 |
| S13-11 | Übersetzungen DE+EN (~200 Keys) | 1,0 | S13-5..10 |
| S13-12 | Admin-Report *„Tour-Completion pro User"* | 0,5 | S13-3 |
| S13-13 | Tour-PDF-Export | 1,0 | S13-5..10 |
| S13-14 | Tests + Accessibility-Check + Reduced-Motion | 0,75 | S13-5..10 |
| | **Summe Vollausbau** | **8,5** | |

### MVP (~4 FTE-d)

S13-1 + S13-2 + S13-3 + S13-4 + **nur** S13-5 Junior-Tour + 1/6 von
S13-11 + S13-14 (Acc-Check). Weitere Rollen-Touren iterativ nachziehen.

---

## 6. Offene Entscheidungen

1. **Library-Wahl:** `driver.js` (Empfehlung) oder Custom-Stimulus-
   Controller (~1,5 FTE-d mehr, aber keine externe Dep)?
2. **MVP oder Vollausbau?** Nur Junior-Tour zum Start (~4 FTE-d)
   oder gleich alle 6 Rollen (~8,5 FTE-d)?
3. **Consultant-Wünsche S13-12/13** (PDF-Export + Completion-
   Tracking) — jetzt bauen, später, oder ganz skippen?
4. **Tour-Content-Editierbarkeit** (Consultant-Wunsch c) — jetzt
   bauen (+ ~2 FTE-d extra) oder später bei konkretem Kundenbedarf?
5. **Mobile-Strategie:** Tour disabled auf < 768px + statische
   Fallback-Seite bestätigen?

---

## 7. Abhängigkeiten zu bestehenden Features

- **Modul-Awareness:** `ModuleConfigurationService::isModuleActive()`
  steuert Zusatz-Stopps für BSI/GDPR/BCM.
- **Rollen-Grants:** `Security::isGranted()` entscheidet Auto-Role.
- **Admin-Report:** existierendes `/admin`-Dashboard-KPI-Pattern
  (S11-1) kann `completedTours`-Count aufnehmen.
- **A11y-Basis:** `_keyboard_shortcuts.html.twig` + Focus-Management-
  Patterns aus Phase 8H.2 wiederverwenden.
- **i18n:** bestehende 49 Translation-Domains → `guided_tour.*.yaml`
  als 50. Domain anlegen.

---

## 8. Nicht-Ziele

- **Keine interaktiven Demos** mit Fake-Daten (Tenant-Daten sind
  real) — Tour markiert nur UI-Elemente, triggert keine Mutations.
- **Keine Multi-Page-Durchklicks mit State-Retention** — jede Tour
  läuft innerhalb einer Page oder maximal über 1-2 Turbo-Navigationen.
- **Keine Gamification** (Badges, Progress-Bars, XP) — Compliance-
  Tool-Kontext, nicht Edutainment.
- **Keine A/B-Tests** der Tour-Varianten — Multi-Mandanten-Setup
  macht Statistik-Sample zu klein.

---

## 9. Metriken zur Erfolgsmessung

Nach 3 Monaten Deployment messen:

- **Tour-Opt-in-Rate:** Anteil der User die nach First-Login die
  Tour starten (Ziel: > 60 %).
- **Tour-Completion-Rate:** Anteil der Starter die bis Ende klicken
  (Ziel: > 50 %).
- **Time-to-First-Entity:** Zeit von Registration bis zum ersten
  angelegten Asset/Risk (Ziel: < 15 min vs. heute ~2 h).
- **Support-Ticket-Reduction:** Tickets mit Kategorie *„wo finde
  ich X?"* → Ziel −40 %.

Messung via bestehenden Audit-Log-Service + neuem User-Metric-
Command (Sprint 14 optional).
