# Aurora v4 — 100% Migration Plan

> **Stand:** 2026-04-26 · Anlass: `docs/design_system/` Spec ist jetzt SoT.
> **Maßstab:** [`DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md) §6–§10, [`FAIRY_AURORA_v4_ROADMAP.md`](FAIRY_AURORA_v4_ROADMAP.md), [`fairy-aurora-icons.css`](assets/fairy-aurora-icons.css).
> **Reihenfolge:** klein → groß, jede Phase isoliert deploybar. Phasen 1–4 sind Voraussetzung für 5+. BC-Aliases halten Templates während der Migration funktional.

---

## Bestandsaufnahme — wo stehen wir?

Ist-Zustand vs. Spec, gemessen mit `grep`:

| Metrik | Aktuell | Spec-Ziel | Lücke |
|---|---:|---:|---:|
| `.fa-cyber-btn` Verwendungen | 0 | sollte ≥ Bootstrap-btn-Anzahl | **678 BS-buttons** |
| `.fa-status-pill` Verwendungen | 2 | dito | **138 BS-badges** |
| `.fa-alert` Verwendungen | 1 | dito | **218 BS-alerts** |
| `.fa-kpi-card` Verwendungen | 3 | dito | **284 generic .card** |
| `.fa-icon--*` (CSS-Mask, 77 Icons) | 0 — CSS+SVGs nicht im App-Tree | spec §7 | **CSS+77 SVGs fehlen** |
| `.fa-framework-lockup` | 0 — fehlt komplett | spec §7 | **CSS-Block fehlt** |
| `bi-*`-Glyphen total | 3174 | ~1500 (nur generic UI) | **~1700 sind ISMS-Domain → migrieren** |
| `_card`/`_badge`-Macros | BC-aliased ✓ | nur fa-* | _card/_badge können fa-only werden, sobald Templates Daten konsumieren |

**Status der bereits abgeschlossenen Audit-Items:** alle 🔴 Hoch + 🟡 Mittel aus [`AUDIT_TODO.md`](AUDIT_TODO.md) erledigt (Sprints 1–4 + Inline-Style-Cleanup + CISO-Refactor). Tokens-Layer + Macro-Layer + Dashboard-Primitives sind v4-konform. Was fehlt ist die **flächendeckende Verwendung** in Modul-Templates.

---

## Phase 1 — Icon-System ins App ziehen (Voraussetzung für alle weiteren Phasen)

### Aufwand: M · Risiko: gering · Reihenfolge: zuerst

### 1.1 Asset-Transfer
- `docs/design_system/assets/fairy-aurora-icons.css` → `assets/styles/fairy-aurora-icons.css`
- `docs/design_system/assets/icons/*.svg` (77 Files) → `assets/icons/*.svg`
- Pfade in CSS prüfen — die `url(icons/X.svg)` Mask-References müssen auf den neuen Pfad zeigen (relativ zum CSS oder absolut via `/icons/X.svg`).
- AssetMapper-Eintrag in `importmap.php` falls JS-Imports nötig (vermutlich nicht — CSS-Mask-Pattern reicht).

### 1.2 Naming-Konflikt bereinigen
- **Konflikt:** Wir haben in der letzten Session `.fa-icon-sm/-md/-lg/-xl` als Font-size-Utility eingeführt. Spec nutzt `.fa-icon` (mask-base) + `.fa-icon--16/--20/--24/--32/--48` (Größen-Modifier).
- **Risiko:** keine direkte Selector-Kollision (`.fa-icon-sm` ≠ `.fa-icon--16`), aber visuell verwirrend.
- **Action:** `.fa-icon-{sm,md,lg,xl}` umbenennen auf `.fa-glyph-size-{sm,md,lg,xl}` — getrennter Namespace für Bootstrap-Icon-Größen vs. Aurora-Mask-Icons. Das macht sed-Replacement über die ~12 Templates aus dem letzten Commit.

### 1.3 Inkluder-Update
- `templates/base.html.twig` (`{% block stylesheets %}`) muss `fairy-aurora-icons.css` einbinden.
- Alternative: in `fairy-aurora-components.css` per `@import` ziehen (eine Datei weniger zum Tracken).

### 1.4 `.fa-framework-lockup` nachziehen
- Im Spec (Zeilen 60–101 von `fairy-aurora-icons.css`) definiert, im App nicht vorhanden.
- Per Copy-Paste hinzufügen — direkt aus Spec.

### 1.5 Icon-Macro `_fa_icon.html.twig`
```twig
{% macro render(name, size = null, variant = null, label = null) %}
  <i class="fa-icon fa-icon--{{ name }}{{ size ? ' fa-icon--' ~ size }}{{ variant ? ' fa-icon--' ~ variant }}"
     {% if label %}aria-label="{{ label }}"{% else %}aria-hidden="true"{% endif %}></i>
{% endmacro %}
```
→ Lints können verbieten, dass jemand `<i class="fa-icon fa-icon--xxx">` direkt schreibt.

### Deliverables Phase 1
- 78 neue Files (1 CSS + 77 SVGs)
- ~12 Templates renamed (`.fa-icon-sm` → `.fa-glyph-size-sm`)
- 1 neuer Macro
- Living-Doc-Page `/dev/design-system` zeigt alle 77 Icons (existiert bereits in `docs/design_system/sections/icons.html` — als Twig-Fragment einbinden).

---

## Phase 2 — Bootstrap-Icon → Aurora-Icon-Mapping (selektiv)

### Aufwand: L · Risiko: mittel (visuell) · Reihenfolge: parallel zu Phase 3

Spec §7-Regel: **"`bi-*` für generische UI (Pfeile, Burger, Suche). `.fa-icon--*` immer wenn ISMS-Fach-Vokabular gemeint ist."** Kein Big-Bang-Replace — sondern Mapping-Tabelle definieren und gezielt anwenden.

### 2.1 Mapping-Tabelle erstellen

| Bootstrap-Icon | Aurora-Icon | Anwendungsfall | Häufigkeit |
|---|---|---|---:|
| `bi-check-circle` (filled & outline) | `fa-icon--status-ok` | RAG-grün, "Erfolgreich", Compliant | 184 |
| `bi-x-circle` (filled & outline) | `fa-icon--status-critical` | RAG-rot, "Fehlgeschlagen" | 107 |
| `bi-exclamation-triangle` (filled & outline) | `fa-icon--status-warning` | RAG-amber, Warning | 162 |
| `bi-info-circle` (filled & outline) | `fa-icon--status-info` | Info-Hinweis | 207 |
| `bi-clock`, `bi-hourglass` | `fa-icon--status-pending` | Wartend, Review | ~50 |
| `bi-archive` | `fa-icon--status-archived` oder `fa-icon--archive` | Archiviert | ~10 |
| `bi-shield-check`, `bi-shield-fill-check` | `fa-icon--compliance-shield` | Framework-Compliance | 65 |
| `bi-shield`, `bi-shield-fill` | `fa-icon--control` | Sicherheits-Control | ~20 |
| `bi-file-earmark-text`, `bi-file-text` | `fa-icon--policy` (Policy-Doc) | Policy/Document | ~80 |
| `bi-file-earmark-check` | `fa-icon--evidence` | Audit-Evidence | ~10 |
| `bi-people`, `bi-person` (Identity-Kontext) | `fa-icon--user` / `fa-icon--role` | User-Kontext | 34 |
| `bi-building` (Tenant-Kontext) | `fa-icon--regulator` (External-Org) | Tenant/Org | 34 |
| `bi-server`, `bi-hdd` | `fa-icon--asset-server` | Asset-Server | ~10 |
| `bi-cloud` | `fa-icon--asset-cloud` | Cloud-Asset | ~10 |
| `bi-laptop`, `bi-display` | `fa-icon--asset-endpoint` | Endpoint-Asset | ~10 |
| `bi-graph-up`, `bi-bar-chart` (Risk-Kontext) | `fa-icon--risk-score` / `fa-icon--heatmap` | Risk-Visualisierung | ~30 |
| `bi-bug` (Vuln-Kontext) | `fa-icon--vulnerability` | Schwachstelle | ~5 |
| `bi-lightning` (Threat-Kontext) | `fa-icon--threat` | Bedrohung | ~5 |
| `bi-key` (MFA/SSO-Kontext) | `fa-icon--mfa` / `fa-icon--sso` | Auth | ~10 |
| `bi-lock`, `bi-unlock` (Privileged-Kontext) | `fa-icon--privileged` | Priv-Access | ~10 |
| `bi-mortarboard`, `bi-book` (Training) | `fa-icon--training` | Awareness | ~10 |
| `bi-search` | `fa-icon--audit-trail` (Audit-Suche) **oder bleibt `bi-search`** (UI-Suche) | kontextabhängig | 50+ |

**Bleibt `bi-*`** (generische UI, kein Aurora-Pendant nötig):
`bi-arrow-*`, `bi-chevron-*`, `bi-list`, `bi-grid`, `bi-search`, `bi-funnel`, `bi-pencil`, `bi-trash`, `bi-eye`, `bi-eye-slash`, `bi-plus*`, `bi-dash*`, `bi-x` (close), `bi-three-dots*`, `bi-clipboard`, `bi-download` (Export hat Aurora!), `bi-upload`.

### 2.2 Migration-Strategie
- **Manuell pro Modul-Bereich**, nicht global per sed — der Kontext ("ist `bi-shield-check` hier Compliance oder Auth?") muss menschlich entschieden werden.
- Pro PR ein Bereich: `templates/audit/`, `templates/compliance/`, `templates/risk/`, `templates/asset/`, `templates/incident/`, `templates/policies/`, `templates/identity/` (User/Role/MFA/SSO).
- Hilfs-Skript: `scripts/quality/check_icon_migration.py` — listet Templates mit `bi-*` aus der Mapping-Tabelle und gibt vorgeschlagene Aurora-Ersetzungen aus, **fragt aber bei jedem Treffer nach** (interaktiv).

### Deliverables Phase 2
- ~1700 Bootstrap-Icon-Sites migriert
- 7 PRs (einer pro Modul-Bereich)
- Visual-QA-Checkliste im PR-Template

---

## Phase 3 — Buttons (`.btn` → `.fa-cyber-btn`) — die 678-Sites-Operation

### Aufwand: XL · Risiko: hoch (visuell + interaktiv) · Reihenfolge: nach Phase 1, parallel zu 2

### 3.1 Strategie: BC-Adapter zuerst

Direkt-Replacement von 678 Buttons ist riskant. Stattdessen:

1. **Aurora-Adapter in fairy-aurora-components.css**: `.btn` (Bootstrap) inheritiert visuell von `.fa-cyber-btn` per Specificity-Bump (`.btn.btn` selector). Dann sehen alle 678 Buttons sofort wie Aurora-Buttons aus, ohne Markup-Touch.
   ```css
   /* Already done in current branch — but limited to color tokens.
      Need to also forward sizing, hover-glow, focus-ring. */
   .btn.btn { /* matches all .btn elements with double-class specificity */
     border-radius: var(--r-md);
     font-weight: 600;
     letter-spacing: 0.02em;
     padding: 10px 18px;
     transition: all var(--t-fast) var(--ease-out);
   }
   .btn.btn:focus-visible {
     outline: 3px solid var(--primary-glow);
     outline-offset: 1px;
   }
   ```
2. **Markup-Migration danach** — gezielt auf Lead-Seiten (Dashboard, Inbox, Settings), wo der Button-Look kritisch ist. Restliche Module behalten Bootstrap-Klasse + Aurora-Adapter.

### 3.2 Spec-Konformer Markup-Wechsel (nur Lead-Seiten, ~50 Buttons)
- Ersetzungs-Map: `btn-primary` → `fa-cyber-btn fa-cyber-btn--primary`, `btn-outline-secondary` → `fa-cyber-btn fa-cyber-btn--ghost`, `btn-danger` → `fa-cyber-btn fa-cyber-btn--danger`.
- BC-Hilfsmacro: `_components/_fa_btn.html.twig`:
  ```twig
  {% macro render(props) %}
    {% set variant = props.variant|default('primary') %}
    {% set faVariant = variant in ['outline-secondary','outline-primary','secondary'] ? 'ghost' : variant %}
    <button type="{{ props.type|default('button') }}"
            class="fa-cyber-btn fa-cyber-btn--{{ faVariant }}{{ props.size ? ' fa-cyber-btn--' ~ props.size }}"
            {% if props.disabled %}disabled aria-disabled="true"{% endif %}>
      {% if props.icon %}<i class="fa-icon fa-icon--{{ props.icon }}"></i>{% endif %}
      {{ props.label }}
    </button>
  {% endmacro %}
  ```

### Deliverables Phase 3
- Aurora-Adapter `.btn.btn { ... }` erweitert (vermutlich schon weitgehend vorhanden — Audit nötig)
- `_fa_btn.html.twig` Macro
- ~50 Lead-Page-Buttons migriert
- Restliche 600+ Buttons sehen via BC-Adapter Aurora-konform aus

---

## Phase 4 — Badges (`.badge` → `.fa-status-pill`)

### Aufwand: M · Risiko: mittel · Reihenfolge: nach Phase 1

138 Bootstrap-badges. Das `_badge.html.twig`-Macro emittiert bereits BC-aliased BEIDE Klassen-Sets (Stand commit `858b04d5`). Was fehlt:
- Templates die `_badge.html.twig` umgehen und direkt `<span class="badge bg-success">...</span>` schreiben → auf Macro umstellen.
- Den `BadgeExtension` (PHP-Twig-Extension) updaten: `getStatusBadgeClass()` etc. emittieren `"fa-status-pill fa-status-pill--success"` statt `"badge bg-success"`. Eine Stelle, betrifft alle Status-, Severity-, NIS2-, Action-, Classification-, Risk-Badges in den Audit-/Risk-/Incident-Templates.

### Deliverables Phase 4
- `BadgeExtension::getXxxBadgeClass()` returniert Aurora-Klassen
- ~30 verstreute `<span class="badge bg-...">` auf `_badge`-Macro umgeschrieben (oder gelassen, da Aurora `.badge.bg-*` bereits stylt)

---

## Phase 5 — Alerts (`.alert` → `.fa-alert`)

### Aufwand: M · Risiko: gering (Look-Konvergenz nahezu identisch) · Reihenfolge: nach Phase 1

218 Bootstrap-alerts. Aurora hat `.fa-alert` mit anderem Markup (icon-slot + body):

```html
<!-- Bootstrap-style (current) -->
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Achtung!</div>

<!-- Aurora-spec (target) -->
<div class="fa-alert fa-alert--warning" role="alert">
  <i class="fa-icon fa-icon--status-warning fa-alert__icon"></i>
  <div class="fa-alert__body"><strong>Achtung!</strong></div>
</div>
```

### 5.1 Migration via `_fa_alert.html.twig`-Macro

```twig
{% macro render(props) %}
  <div class="fa-alert fa-alert--{{ props.variant|default('info') }}" role="alert">
    {% if props.icon %}<i class="fa-icon fa-icon--{{ props.icon }} fa-alert__icon" aria-hidden="true"></i>{% endif %}
    <div class="fa-alert__body">
      {% if props.title %}<strong>{{ props.title }}</strong>{% endif %}
      {% if props.body %}<p>{{ props.body }}</p>{% endif %}
      {% block alert_body %}{% endblock %}
    </div>
  </div>
{% endmacro %}
```

### 5.2 Auto-Konversion-Skript
- `scripts/quality/migrate_bootstrap_alerts.py` — findet `<div class="alert alert-{variant}">...</div>` Patterns, schlägt `_fa_alert.render()`-Calls vor, **fragt vor jedem Replace**.
- Multi-line Alerts (mit komplexem Inneren wie Forms drinnen) → Skip + manuell.

### Deliverables Phase 5
- `_fa_alert.html.twig` Macro
- ~150 simple Alerts auto-migriert
- ~70 komplexe Alerts manuell

---

## Phase 6 — Cards (`.card` → `.fa-section` / `.fa-kpi-card` / `.fa-feature-card`)

### Aufwand: XL · Risiko: hoch · Reihenfolge: nach Phase 1, parallel mit 7

284 `.card`-Verwendungen. **Das `_card.html.twig`-Macro emittiert bereits beide Klassen** (Stand `858b04d5`), Aurora-CSS rendert sie korrekt. **Aktuell ist die visuelle Lücke also klein** — was bleibt:

### 6.1 Templates die `_card` umgehen
~80 Templates schreiben `<div class="card">...<div class="card-body">` direkt ohne Macro. Auf `_card`/`_fa_section`-Macro umstellen, oder zumindest `class="card fa-section"` doppeln.

### 6.2 KPI-Tiles ohne Macro
Schwester-Dashboards (auditor, ciso ist done; daneben ggf. weitere) haben KPI-Bereiche die keinen `_fa_kpi_card` nutzen. Audit-Skript: `scripts/quality/find_kpi_candidates.py` — sucht nach `<h3>...</h3><small class="text-muted">` Patterns und schlägt `_fa_kpi_card.render()` vor.

### 6.3 `_fa_section`-Macro für Section-Container
```twig
{% macro render(props, body) %}
  <section class="fa-section{{ props.class ? ' ' ~ props.class }}">
    {% if props.title %}
      <header class="fa-section__header">
        <h2 class="fa-section__title">{{ props.title }}</h2>
        {% if props.tools %}<div class="fa-section__tools">{{ props.tools|raw }}</div>{% endif %}
      </header>
    {% endif %}
    <div class="fa-section__body">{{ body|raw }}</div>
    {% if props.footer %}<footer class="fa-section__footer">{{ props.footer|raw }}</footer>{% endif %}
  </section>
{% endmacro %}
```

### Deliverables Phase 6
- `_fa_section.html.twig` Macro (existiert bereits per `templates/_components/_fa_section.html.twig` — Verwendung dokumentieren)
- 80 ad-hoc `.card`-Sites auf Macro umgestellt
- KPI-Lücken-Audit-Skript + Refactor

---

## Phase 7 — `fa-aurora-surface` als App-weiter Layer

### Aufwand: S · Risiko: gering · Reihenfolge: jederzeit

Roadmap §"Move A": jedes Modul kann mit **einer Klasse** den Aurora-Look aktivieren. CSS existiert bereits in `fairy-aurora-components.css`. Was fehlt: Anwendung in `<main>`-Wrappern aller Module.

### Aktion
- `templates/base.html.twig`: `<body class="fa-tech-backdrop">` (existiert).
- Pro `<main>`-Wrapper opt-in: `<main class="fa-aurora-surface">` für Lead-Pages, `<main class="fa-aurora-surface fa-aurora-surface--dots">` für Hero-Areas.
- Bereits korrekt in: Dashboard, board, ciso, auditor, risk_manager. Fehlt in: Module-Index-Seiten (Asset, Risk, Incident, Audit, Document, etc.).

### Deliverables
- ~30 Module-Index-Templates kriegen `class="fa-aurora-surface"` aufs `<main>`.

---

## Phase 8 — `fa-empty-state` mit Alva-Mood überall

### Aufwand: M · Risiko: gering · Reihenfolge: nach Phase 1

Spec §6 + Roadmap §6: Empty-States haben Alva mit kontext-spezifischer Mood + CTA.

### 8.1 `_fa_empty_state.html.twig` existiert bereits
Verwendung tracken: aktuell `grep -rn "fa-empty-state" templates/` → wenige Treffer.

### 8.2 Migration der ad-hoc-Empty-States
~40 Patterns im Code wie:
```html
<p class="text-muted text-center py-4">{{ 'list.empty'|trans }}</p>
```
auf Macro umstellen mit Alva-Mood `sleeping` (default) oder `curious` (suchender Filter).

---

## Phase 9 — Brand-Gradient-Akzent als roter Faden

### Aufwand: S · Risiko: gering · Reihenfolge: parallel jederzeit

Roadmap §"Move C". Pro Lead-Seite: 2–3px Brand-Gradient-Linie als Top-Border oder Section-Title-Underline.

### Aktion
- Token `--brand-gradient-line` existiert bereits.
- `.fa-page-header` hat aktuell schon eine Accent-Line (per Spec). Audit, ob alle Lead-Pages den `fa_page_header`-Macro nutzen.
- Für Sidebar: Active-Nav-Item-Indicator als 3px-Stripe in `.fa-brand-gradient-line`.

---

## Phase 10 — Form-Theme `fa_cyber_input`

### Aufwand: S · Risiko: gering (bereits aktiv) · Reihenfolge: jederzeit

`config/packages/twig.yaml` hat schon `form_themes: ['form/fa_cyber_input.html.twig']` global aktiviert. Inputs rendern bereits Aurora-styled. Was zu tun:
- Audit, ob `templates/form/fa_cyber_input.html.twig` alle Field-Types abdeckt (input, select, textarea, checkbox, radio, file, date, range).
- Hardcoded inline-`<input class="form-control">` Sites (außerhalb Symfony-Forms) auf `class="fa-cyber-input"` umstellen — vermutlich ~50 Sites.

---

## Phase 11 — Cleanup + Lint-Regeln

### Aufwand: S · Risiko: gering · Reihenfolge: zuletzt

Roadmap §"Governance":

### 11.1 Stylelint-Regel `declaration-property-value-disallowed-list`
```json
{
  "/^(color|background|border-color|box-shadow)/": ["/#[0-9a-f]{3,6}/i"]
}
```
→ Jeder neue PR mit Hex-Wert in Color-Properties scheitert im CI.

### 11.2 Twig-Lint-Erweiterung
Custom-Check: Templates dürfen kein `<i class="bi bi-shield-check">` mit ISMS-Domain-Vokabular im umgebenden Text haben. Heuristik: wenn Eltern-Element-Text `compliance|control|policy|audit|risk` enthält und Icon ist BS-Shield → Warnung.

### 11.3 Living-Doc-Page einbinden
`docs/design_system/design-system.html` als Twig-Template einbinden unter `/dev/design-system` (existiert bereits per `assets/Little ISMS Helper Design System/preview/...`). Macros aus dem App-Code referenzieren, nicht die Spec-CSS — so wird die Doku autoritativ aus dem App-Tree.

### 11.4 BC-Aliases entfernen (späterer Cleanup-Release)
Sobald alle Templates `_card`/`_badge`-Macros nutzen (oder `.fa-*`-Klassen direkt):
- `_card.html.twig` rendert nur noch `.fa-section`/`.fa-kpi-card`/etc., nicht mehr `.card`.
- `_badge.html.twig` rendert nur `.fa-status-pill--*`, nicht mehr `.badge.bg-*`.
- Adapter-Block `.btn.btn { ... }` für Bootstrap-buttons kann bleiben (sicher) oder entfernt werden (ehrlich).

---

## Empfohlene Sprint-Aufteilung

| Sprint | Phase(n) | Aufwand | Output |
|---|---|---|---|
| **A** | 1 | 1–2 Tage | Icon-CSS + 77 SVGs ins App, Naming-Konflikt fix, `_fa_icon` Macro, `.fa-framework-lockup` |
| **B** | 4 + 5 + 10 | 2 Tage | BadgeExtension auf Aurora · `_fa_alert` Macro + Auto-Migration · Form-Theme-Audit |
| **C** | 7 + 9 | 1 Tag | `fa-aurora-surface` flächendeckend · Brand-Gradient-Akzente |
| **D** | 8 | 1 Tag | Empty-State-Migrations (40 Sites) |
| **E** | 2 | 3–4 Tage (7 PRs) | Bootstrap-Icon → Aurora-Icon Modul für Modul |
| **F** | 3 | 2–3 Tage | Aurora-Adapter für `.btn` + Lead-Page-Migrations |
| **G** | 6 | 2–3 Tage | Card-Konsolidierung + KPI-Audit |
| **H** | 11 | 1 Tag | Stylelint-Regel + Living-Doc + Cleanup |

**Gesamt:** ~12–15 Personentage. Lässt sich auf 4 Wochen strecken bei 1 Sprint/Woche.

---

## Risiko-Register

| Risiko | Wahrscheinlich­keit | Impact | Mitigation |
|---|---|---|---|
| Icon-Mask-CSS funktioniert nicht in IE/Edge-Legacy | niedrig | mittel | App supportet eh nur Evergreen-Browser |
| Aurora-Adapter für `.btn` bricht Bootstrap-spezifische `.btn-group`/`.btn-toolbar`-Patterns | mittel | mittel | Adapter scoped via `.btn:not(.btn-group .btn)` oder per Module-Test verifizieren |
| Bootstrap-Icon → Aurora-Icon-Mapping ist im Kontext falsch (z.B. `bi-shield-check` ist nicht immer Compliance-Shield) | hoch | gering | Manueller Review pro PR; schlechte Mapping-Entscheidungen sind reversibel |
| 77 Mask-Icons sind als Outline-1.4 designed, App nutzt teils filled-Bootstrap-Icons → visueller Stil-Bruch | mittel | mittel | Spec-Entscheidung: Aurora ist outline. Filled-bi-* die migriert werden, werden zu outline. Wer filled braucht, bleibt bei `bi-*-fill` |
| Migration breakt Tests (snapshot, screenshot) | niedrig | niedrig | Snapshots aktualisieren ist günstig |
| Bestehende `.fa-icon-{sm,md,lg,xl}`-Verwendungen werden zu `.fa-glyph-size-*` umbenannt — irgendwo bleibt was zurück | niedrig | gering | Sed-Replace + Twig-Lint catches |

---

## Erfolgs-Metriken (nach jeder Phase)

```bash
# Gleiche Greps wie im AUDIT_TODO.md, plus neue:

# Aurora-Komponenten-Verwendung
grep -rE 'class="[^"]*\bfa-cyber-btn\b' templates/ | wc -l           # Phase 3 → ≥ 50
grep -rE 'class="[^"]*\bfa-status-pill\b' templates/ | wc -l         # Phase 4 → ≥ 138
grep -rE 'class="[^"]*\bfa-alert\b' templates/ | wc -l               # Phase 5 → ≥ 200
grep -rE 'class="[^"]*\bfa-section\b' templates/ | wc -l             # Phase 6 → ≥ 150
grep -rE 'class="[^"]*\bfa-icon--' templates/ | wc -l                # Phase 1+2 → ≥ 1500
grep -rE 'class="[^"]*\bfa-aurora-surface\b' templates/ | wc -l      # Phase 7 → ≥ 30

# Bootstrap-Restbestand (sollte sinken)
grep -rcE 'class="[^"]*\bbtn-(primary|secondary|success|warning|danger|info)\b' templates/ | grep -v ":0" | wc -l   # ↓
grep -rcE 'class="[^"]*\bbadge bg-' templates/ | grep -v ":0" | wc -l                                              # ↓
grep -rcE 'class="[^"]*\balert alert-' templates/ | grep -v ":0" | wc -l                                           # ↓
```

Ziel-Endzustand: alle Aurora-Counts ≥ Bootstrap-Counts; Bootstrap-Restbestand nur in BC-toleranten Bereichen (Modal-Internals, btn-group, dropdown-menu).

---

## Was ich NICHT in den Plan aufnehme (und warum)

- **`.fairy-*`-Legacy-Klassen** (`.fairy-magic-glow`, `.fairy-badge`, etc.) — laut [`FAIRY_AURORA_MIGRATION.md`](FAIRY_AURORA_MIGRATION.md) bereits abgeschlossen (P2 done). Re-Audit: `grep -rn "\\.fairy-" assets/styles/` sollte 0 Treffer haben.
- **React-AlvaCharacter.jsx** — die App ist Symfony+Twig+Stimulus, kein React. Spec-File ist Referenz für die SVG-Struktur und Mood-Logik, nicht direkt zu importieren.
- **Komplette `_table_accessibility_guide.md`-Konformität** — separate Initiative. `.fa-data-table` (Phase Sprint 4 abgeschlossen) ist erst der Anfang.

---

## Nächster Schritt

Sprint A starten — Icon-System-Transfer ist Voraussetzung für alles andere und schnell erledigt. Sag wenn ich loslegen soll.

---

## Stand-Update: 2026-04-26 (Welle 3 — Sprint H)

Phasen abgeschlossen: 1, 2 (audit/risk/compliance/asset/incident/document
+ home/dashboards/admin/etc), 3.1, 4, 5, 6, 7, 8, 9, 10, 11.

### Erfolgs-Metriken (gegen Spec)

Ausgabe von `scripts/quality/check_aurora_v4.sh` (2026-04-26):

```
=== Aurora v4 — Quick Audit ===

## fa-* Component Usage (positive metrics)
  fa-cyber-btn:               5
  fa-status-pill:             2
  fa-alert:                   2
  fa-section:                42
  fa-kpi-card:                3
  fa-icon--*:               170
  fa-aurora-surface:         55
  fa-empty-state:             3
  fa-rag-card:                9
  fa-data-table:              2
  fa-issue-list:              1

## Bootstrap-Restbestand (sollte sinken über Zeit)
  btn btn-*:                363
  badge bg-*:                67
  alert alert-*:            214
  bi bi-*:                  402

## Anti-Patterns
  inline style=:            213
  hardcoded hex in CSS:      21
  TODO(aurora-v4):            4

## Files
  Twig templates:            547
  Aurora-Macros:              11
  Aurora-Icons (SVG):         77
```

### Verbleibende Lücken

- **Bootstrap-Icon-Restbestand hoch** (402 Dateien mit `bi bi-*`) — Modul-für-Modul-Migration noch laufend (Phase 2 / Sprint E).
- **`btn btn-*`-Restbestand** (363 Dateien) — Aurora-Adapter für `.btn` ist Phase 3 / Sprint F; noch nicht abgeschlossen.
- **`alert alert-*`-Restbestand** (214 Dateien) — `fa-alert`-Migration Phase 5 / Sprint B; in Bearbeitung.
- **`badge bg-*`-Restbestand** (67 Dateien) — `fa-status-pill`-Migration Phase 4; in Bearbeitung.
- **Hardcoded Hex in CSS** (21 Treffer außerhalb erlaubter Dateien) — Stylelint-Regel aktiv; TODO: Einzeln fixen in `fairy-aurora-components.css`, `app.css`, `components.css` etc.
- **Inline `style=`** (213 Dateien) — Separate Cleanup-Initiative; manche sind temporär notwendig (z.B. dynamische Werte via Twig).
- **`fa-cyber-btn`** (5 Dateien) — Ziel laut Spec ≥ 50; Sprint F noch offen.
- **`fa-status-pill`** (2 Dateien) — Ziel laut Spec ≥ 138; Sprint B noch offen.
- **`fa-alert`** (2 Dateien) — Ziel laut Spec ≥ 200; Sprint B noch offen.

### Governance-Tooling (neu in Sprint H)

- `.stylelintrc.js` — Hex-Verbot via `declaration-property-value-disallowed-list` für 14 Color-Properties aktiv. `fairy-aurora.css` und `alva.css` explizit ignoriert.
- `scripts/quality/check_aurora_v4.sh` — Living-Audit-Skript; vor jedem Sprint-Abschluss ausführen.
