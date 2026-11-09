# 🚀 UI/UX Quick Start Guide

> **Stand 2025, teils veraltet.** Aktuelle Komponenten: Aurora-v4-Makros `templates/_components/_fa_*.html.twig`
> (Liste in `CLAUDE.md`, "Aurora v4 Components"), Live-Vorschau `/dev/design-system`. Bootstrap-Markup (`btn btn-*`, `badge bg-*`,
> `alert alert-*`) lässt CI-Gate 62 scheitern.

## Was ist neu?

Das Little-ISMS-Helper hat ein **komplettes UI/UX-Upgrade** erhalten mit modernen 2024/2025 Best Practices.

### 🎯 Top 3 Features

1. **⌘P Command Palette** - Instant Navigation zu jeder Funktion
2. **🔔 Toast Notifications** - Moderne, unaufdringliche Benachrichtigungen
3. **⌨️ Keyboard Shortcuts** - Professionelle Shortcuts für Power Users

---

## ⚡ 30-Sekunden-Demo

### 1. Command Palette öffnen
```
Drücke: ⌘P (Mac) oder Ctrl+P (Windows)
Tippe: "asset"
Enter: Navigiere zu Assets
```

### 2. Keyboard Shortcuts anzeigen
```
Drücke: ?
Siehe: Alle verfügbaren Shortcuts
```

### 3. Schnell-Navigation
```
g + d = Dashboard
g + a = Assets
g + r = Risks
g + i = Incidents
```

---

## 🏗️ Komponenten nutzen

### KPI Cards
```twig
{% include '_components/_kpi_card.html.twig' with {
    icon: 'bi-server',
    label: 'Total Assets',
    value: 42,
    variant: 'primary'
} %}
```

### Breadcrumbs
```twig
{% include '_components/_breadcrumb.html.twig' with {
    breadcrumbs: [
        { label: 'Assets', url: path('app_asset_index') },
        { label: 'Details' }
    ]
} %}
```

### Empty State
```twig
{% include '_components/_empty_state.html.twig' with {
    icon: 'bi-inbox',
    title: 'Keine Daten',
    description: 'Legen Sie etwas Neues an.',
    action: { label: 'Erstellen', url: path('app_new') }
} %}
```

---

## 📚 Mehr Infos

**Vollständige Dokumentation**: Siehe [`UI_UX_IMPLEMENTATION.md`](UI_UX_IMPLEMENTATION.md)

**Beispiel-Implementation**: Siehe `templates/asset/index.html.twig`

---

## 🎨 Verfügbare Komponenten

| Komponente | Datei | Zweck |
|------------|-------|-------|
| Breadcrumb | `_breadcrumb.html.twig` | Navigation Pfad |
| KPI Card | `_kpi_card.html.twig` | Kennzahlen anzeigen |
| Page Header | `_page_header.html.twig` | Seitentitel + Actions |
| Empty State | `_empty_state.html.twig` | "Keine Daten" Screen |
| Floating Toolbar | `_floating_toolbar.html.twig` | Sticky Actions |
| Related Items | `_related_items.html.twig` | Verknüpfungen anzeigen |

Alle in: `templates/_components/`

---

## ⚙️ Setup

### 1. Assets installieren
```bash
php bin/console importmap:install
```

### 2. Cache leeren
```bash
php bin/console cache:clear
```

### 3. Server starten
```bash
symfony server:start
```

### 4. Testen
- Öffne `http://localhost:8000`
- Drücke `⌘P` für Command Palette
- Drücke `?` für Shortcuts

---

## 🆘 Häufige Probleme

### Command Palette öffnet nicht
```bash
php bin/console importmap:install
php bin/console cache:clear
```

### Styling fehlt
Prüfe, ob die CSS-Datei in `templates/_aurora_styles.html.twig` (und in gleicher Reihenfolge in
`assets/styles.css`) steht. `tests/js/styles/stylesheet_wiring.test.js` meldet fehlende Einträge.

---

## 🎯 Nächste Schritte

1. ✅ **Lerne die Shortcuts**: Drücke `?`
2. ✅ **Nutze Command Palette**: Drücke `⌘P`
3. ✅ **Modernisiere deine Module**: Nutze die Komponenten
4. ✅ **Lies die Vollständige Doku**: `docs/ui-ux/UI_UX_IMPLEMENTATION.md`

---

**Happy Coding! 🚀**
