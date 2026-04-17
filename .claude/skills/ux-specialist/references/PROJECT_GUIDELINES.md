# Projektspezifische UI/UX Guidelines — Little ISMS Helper

## Tech Stack

- **Symfony 7.4** + Twig Templates
- **Bootstrap 5.3** (CSS Framework)
- **Stimulus 3.2** (JS Controllers) + **Turbo 7.3** (SPA-Navigation)
- **AssetMapper** (kein Webpack)
- **Chart.js 3.9** (Datenvisualisierung)
- **Bootstrap Icons** (Iconset)
- **Dark/Light Mode** (CSS custom properties)

## Komponentenbibliothek

Alle wiederverwendbaren Komponenten liegen in `templates/_components/`. **Immer zuerst pruefen ob Komponente existiert** bevor neue erstellt wird.

### Verfuegbare Komponenten (34 Twig-Partials)

**Layout & Navigation:**
- `_breadcrumb.html.twig` — Breadcrumb-Navigation
- `_command_palette.html.twig` — Cmd+K Schnellsuche
- `_global_search.html.twig` — Globale Suche
- `_keyboard_shortcuts.html.twig` — Keyboard-Shortcut-Hilfe
- `_notification_bell.html.twig` — Benachrichtigungs-Glocke
- `_notification_center.html.twig` — Benachrichtigungszentrale

**Formulare:**
- `_form_field.html.twig` — Standard-Formularfeld (Label + Input + Fehler)
- `_auto_form.html.twig` — Automatisch generiertes Formular
- `_risk_slider.html.twig` — Risiko-Slider (1-5 Skala)
- `_slider.html.twig` — Generischer Slider

**Daten-Anzeige:**
- `_badge.html.twig` — Status-Badge (Farbe + Text)
- `_detail_group.html.twig` — Detail-Anzeige Gruppe
- `_activity_feed.html.twig` — Aktivitaets-Timeline
- `_audit_timeline.html.twig` — Audit-Trail-Timeline
- `_loading_spinner.html.twig` — Ladeanzeige

**Dialoge & Modals:**
- `_quick_view_modal.html.twig` — Schnellansicht (Preview)
- `_bulk_delete_confirmation.html.twig` — Massen-Loesch-Bestaetigung
- `_preferences_modal.html.twig` — Einstellungs-Dialog
- `_dashboard_settings_modal.html.twig` — Dashboard-Konfiguration

**Rollen & Hilfe:**
- `_role_help.html.twig` — Rollenbasierte Hilfe

### Komponentenverwendung (Twig)
```twig
{# Badge einbinden #}
{% include '_components/_badge.html.twig' with {
    status: 'implemented',
    label: 'Umgesetzt'
} only %}

{# Form-Feld #}
{% include '_components/_form_field.html.twig' with {
    field: form.name,
    label: 'Name',
    help: 'Eindeutiger Name'
} only %}

{# WICHTIG: Immer 'only' verwenden um Variable-Leaking zu verhindern #}
```

## Stimulus Controllers (39+)

Alle in `assets/controllers/`. Namenskonvention: `name_controller.js`.

**Wichtige Controller:**
- `filter_controller.js` — Client-side Filterung fuer Tabellen
- `chart_controller.js` — Chart.js Wrapper mit Theme-Support
- `modal_controller.js` — Modal-Steuerung
- `command_palette_controller.js` — Cmd+K Palette
- `bulk_actions_controller.js` — Massenoperationen
- `file_upload_controller.js` — Datei-Upload mit Validierung
- `notification_controller.js` — Toast-Benachrichtigungen
- `tab_controller.js` — Tab-Navigation
- `tooltip_controller.js` — Tooltip-Steuerung

### Stimulus-Konvention
```html
{# Controller mit Targets und Values #}
<div data-controller="filter"
     data-filter-url-value="{{ path('app_asset_index') }}">
    <input data-filter-target="searchInput"
           data-action="input->filter#search">
    <div data-filter-target="filterableRow">...</div>
    <div data-filter-target="noResults" class="d-none">Keine Ergebnisse</div>
</div>
```

## Design Patterns

### Card-basiertes Layout
```twig
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Titel</h5>
        <div class="card-actions">
            <a href="#" class="btn btn-sm btn-outline-primary">Aktion</a>
        </div>
    </div>
    <div class="card-body">
        {# Inhalt #}
    </div>
</div>
```

### KPI-Dashboard-Cards
```twig
<div class="col-md-3">
    <div class="card border-start border-4 border-primary">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small">Label</div>
                    <div class="h3 mb-0">{{ value }}</div>
                </div>
                <i class="bi bi-icon-name fs-1 text-primary opacity-50"></i>
            </div>
        </div>
    </div>
</div>
```

### Tabellen mit Filter
```twig
<div data-controller="filter">
    <input data-filter-target="searchInput"
           data-action="input->filter#search"
           class="form-control mb-3"
           placeholder="{{ 'common.search'|trans({}, 'messages') }}">
    <table class="table table-hover">
        <thead>...</thead>
        <tbody>
            {% for item in items %}
            <tr data-filter-target="filterableRow">
                <td>{{ item.name }}</td>
            </tr>
            {% endfor %}
        </tbody>
    </table>
    <div data-filter-target="noResults" class="d-none text-center text-muted py-4">
        {{ 'common.no_results'|trans({}, 'messages') }}
    </div>
</div>
```

### Status-Badge-Konvention

| Status | Bootstrap-Klasse | Farbe |
|--------|-----------------|-------|
| Umgesetzt/Aktiv | `bg-success` | Gruen |
| In Bearbeitung | `bg-warning text-dark` | Gelb |
| Geplant | `bg-info` | Blau |
| Nicht begonnen | `bg-secondary` | Grau |
| Kritisch/Fehler | `bg-danger` | Rot |
| Entwurf | `bg-light text-dark` | Hellgrau |

### Flash Messages
```twig
{# In base.html.twig — automatisch fuer alle Seiten #}
{% for type in ['success', 'error', 'warning', 'info'] %}
    {% for message in app.flashes(type) %}
        <div class="alert alert-{{ type == 'error' ? 'danger' : type }} alert-dismissible fade show">
            {{ message }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    {% endfor %}
{% endfor %}
```

## Turbo-Konventionen

- `<meta name="turbo-cache-control" content="no-cache">` fuer dynamische Seiten
- `turbo:load` Event statt `DOMContentLoaded` fuer JS-Initialisierung
- `<turbo-frame>` fuer partielle Seitenaktualisierungen
- `<turbo-stream>` fuer Server-initiated Updates (CRUD)

## I18n/L10n

- Alle sichtbaren Texte ueber Twig `|trans()` mit explizitem Domain
- 49 Translation-Domains (siehe CLAUDE.md)
- Locale-Prefix in URLs: `/{locale}/...`
- Unterstuetzte Sprachen: `de`, `en`

## Dark Mode

- CSS Custom Properties fuer Farben
- `data-bs-theme="dark"` auf `<html>`
- Charts: Theme-aware ueber `chart_controller.js`
- Keine hartcodierten Farben in Inline-Styles