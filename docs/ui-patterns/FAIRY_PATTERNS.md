# Cyberpunk Fairy Magic - UI Patterns

**Version:** 1.0
**Date:** 2025-12-28
**Purpose:** Visual indicators for automation, smart suggestions, and system intelligence

---

## Overview

Die "Cyberpunk Fee" kennzeichnet automatisierte Prozesse und Datenübernahmen visuell - **subtil, professionell, mit einem Augenzwinkern**.

### Design Principles

1. **Subtil**: Animationen sind langsam (2.5-3s), nicht hyperaktiv
2. **Professionell**: Pink/Purple Gradient passt zum Cyberpunk-Theme
3. **Accessible**: `prefers-reduced-motion` wird respektiert
4. **Dark Mode**: Automatisch angepasste Visibility
5. **Kontextuell**: Die Fee erscheint dort, wo das System **denkt**, **antizipiert** oder **automatisiert**

---

## Core Components

### Basic Classes

| Klasse | Verwendung |
|--------|------------|
| `.fairy-magic-glow` | Container mit subtilen Shimmer-Effekt |
| `.fairy-icon-sparkle` | Icon mit sanftem Pulsieren + Sparkle |
| `.fairy-badge` | Gradient-Badge mit Prefix |
| `.fairy-field-automatic` | Formularfeld mit pinkem Left-Border |
| `.fairy-helper` | Hilfetext unter automatischen Feldern |
| `.fairy-tooltip` | Tooltip via `data-fairy-tip` Attribut |
| `.fairy-alert` | Notification-Box mit Fairy-Styling |

### Usage in Twig Templates

```twig
{# Automatisch befülltes Formularfeld #}
<div class="mb-3">
    {{ form_widget(form.name, {'attr': {'class': 'fairy-field-automatic'}}) }}
    <small class="fairy-helper">Automatisch aus Asset übernommen</small>
</div>

{# Badge für automatische Werte #}
<span class="fairy-badge">Automatisch</span>

{# Tooltip für Erklärung #}
<span class="fairy-tooltip" data-fairy-tip="Die Fee hat das für dich erledigt">
    <i class="bi bi-info-circle"></i>
</span>

{# Glow-Container für Bereiche mit Automatisierung #}
<div class="card fairy-magic-glow">
    <div class="card-body">
        <i class="bi bi-stars fairy-icon-sparkle"></i>
        Dieser Bereich wurde automatisch befüllt
    </div>
</div>
```

### Usage in JavaScript

```javascript
// Fairy-styled Notification
container.innerHTML = `
    <div class="fairy-alert">
        <i class="bi bi-stars fairy-alert-icon"></i>
        <div class="fairy-alert-content">
            <div class="fairy-alert-title">Automatisch übernommen</div>
            <div>Die Cyberpunk Fee hat 5 Felder für dich ausgefüllt.</div>
        </div>
    </div>
`;

// Oder einfacher mit Bootstrap Alert + Glow
container.innerHTML = `
    <div class="alert alert-success fairy-magic-glow">
        <i class="bi bi-stars fairy-icon-sparkle"></i> Erledigt!
    </div>
`;
```

---

## Contextual Patterns

### 1. Smart Insights - Suggestions

Für intelligente Vorschläge und Ähnlichkeitserkennung.

```twig
{# Wenn die Fee eine Ähnlichkeit erkennt #}
<div class="fairy-suggestion" onclick="applyFairySuggestion()">
    <i class="bi bi-stars fairy-suggestion-icon"></i>
    <span class="fairy-suggestion-text">
        Ähnlichkeit zu "Risiko Server-Ausfall" erkannt
    </span>
    <span class="fairy-suggestion-action">Übernehmen</span>
</div>
```

**CSS Features:**
- Pink/Purple gradient background
- Subtle sparkle animation on hover
- Transform scale on hover
- `::before` indicator line

### 2. Workflow Auto-Transition

Für automatische Workflow-Fortschritte und Completion-Events.

```twig
{# Badge mit Sweep-Animation bei Auto-Completion #}
<span class="badge bg-success fairy-workflow-magic">
    Automatisch abgeschlossen
</span>

{# Oder für Pulse-Effekt #}
<span class="badge bg-success fairy-workflow-complete">
    <i class="bi bi-stars"></i> Nächster Schritt bereit
</span>
```

**CSS Features:**
- `@keyframes fairySweep` diagonal shimmer
- Scale transition on hover
- "Magisch" label via `::after`

### 3. Bulk Actions - Cleanup Fairy

Für Massenoperationen und Bereinigungsaktionen.

```twig
{# Fairy-styled Bulk Action Bar #}
<div class="fairy-bulk-bar p-2 rounded d-flex gap-2">
    <span>5 Elemente ausgewählt</span>
    <button class="fairy-bulk-action">
        Bereinigen
    </button>
</div>

{# Nach Aktion: Zeilen flashen #}
<tr class="fairy-bulk-complete">...</tr>
```

**CSS Features:**
- Pink gradient buttons
- `@keyframes fairyBulkFlash` for completed rows
- Scaling hover effect

### 4. Search Priority - Command Palette

Für priorisierte Suchergebnisse und intelligente Sortierung.

```twig
{# Priorisierte Suchergebnisse #}
<div class="search-result fairy-search-priority">
    <i class="bi bi-file-text"></i> Risikobewertung Server
</div>
{# Zeigt automatisch "Priorisiert" rechts an #}
```

**CSS Features:**
- Gradient left border
- "Priorisiert" label via `::after`
- Subtle gradient background

### 5. Onboarding - Guided Highlights

Für geführte Touren und Einsteiger-Hilfe.

```twig
{# Element zum Anklicken hervorheben #}
<button class="btn btn-primary fairy-onboarding-highlight">
    Erstes Asset anlegen
    <span class="fairy-onboarding-step">Klicke hier</span>
</button>
```

**CSS Features:**
- `@keyframes fairyPulseRing` pulsing outline
- Step badge positioned top-right
- Attention-grabbing without being intrusive

### 6. Auto-Correct - Field Correction

Für automatische Formatierung und Feldkorrektur.

```javascript
// Nach automatischer Formatierung
inputField.classList.add('fairy-auto-correct');
inputField.insertAdjacentHTML('afterend',
    '<span class="fairy-corrected-indicator">Formatiert</span>'
);
```

**CSS Features:**
- Bottom border gradient flash
- Indicator badge below field
- Subtle, non-disruptive feedback

---

## Ambient Patterns

These patterns provide ambient feedback without requiring explicit triggers.

### 7. Empty States - Ghost-Writer Fee

Automatic styling for `.empty-state` components:

```twig
{# Standard empty state gets subtle fairy magic automatically #}
{% include '_components/_empty_state.html.twig' with {
    icon: 'bi-inbox',
    title: 'Noch keine Assets',
    description: 'Legen Sie Ihr erstes Asset an.',
    action: { label: 'Asset erstellen', url: path('app_asset_new') }
} %}
```

**CSS Features:**
- Transparent star (`✦`) watermark in background (3.5% opacity)
- Icon color transition to pink on hover
- All child elements properly z-indexed

### 8. Filter Hint - Smart Reset Nudge

Highlight reset button when many filters are active:

```html
{# Apply when filter count > 3 #}
<button class="btn btn-outline-secondary fairy-filter-hint">
    Filter zurücksetzen
</button>
```

**CSS Features:**
- Subtle pulsing box-shadow
- Sparkle (`✦`) indicator after text
- Draws attention without being intrusive

### 9. Upload Success - Glow-up

Applied automatically after successful file upload:

```javascript
// In file_upload_controller.js
fileItem.classList.add('fairy-upload-success');
```

**CSS Features:**
- 2.5s pink glow animation
- Fades from visible to transparent
- Applied to file list items

### 10. Transition Success - One-time Flash

Utility class for one-time magic sweep:

```javascript
// Apply for instant feedback
element.classList.add('fairy-transition-success');
// Automatically removes effect after animation
```

**CSS Features:**
- Horizontal shimmer sweep
- 0.8s duration, runs once

### 11. Toast Notifications

Success toasts automatically receive fairy styling:

```javascript
// toast_controller.js adds 'fairy-toast' to success type
this.show('Erfolgreich gespeichert', 'success');
```

**CSS Features:**
- Pink left border accent
- Animated gradient shimmer at top
- Subtle enhancement, not distracting

---

## Color Palette

The Fairy Magic system uses the Cyberpunk color palette:

| Variable | Light Mode | Dark Mode | Usage |
|----------|------------|-----------|-------|
| `--color-accent-pink` | #ec4899 | #ec4899 | Primary fairy accent |
| `--color-accent-purple` | #8b5cf6 | #8b5cf6 | Secondary fairy accent |
| `--gradient-fairy` | Pink → Purple | Pink → Purple | Badges, buttons |

---

## Accessibility

All fairy patterns respect user preferences:

```css
@media (prefers-reduced-motion: reduce) {
    .fairy-magic-glow::before,
    .fairy-icon-sparkle,
    .fairy-badge::before,
    .fairy-workflow-magic::before,
    .fairy-onboarding-highlight::before {
        animation: none;
    }
}
```

- No motion for users who prefer reduced motion
- Sufficient color contrast maintained
- Patterns are decorative enhancements, not essential for understanding

---

## Implementation Location

All fairy CSS classes are defined in:

```
assets/styles/app.css
```

Search for `/* === FAIRY MAGIC COMPONENTS ===` to find the section.

---

## Best Practices

### DO

- Use fairy patterns to highlight **automated** actions
- Apply subtly - a little magic goes a long way
- Combine with informative text explaining what happened
- Test with `prefers-reduced-motion: reduce`

### DON'T

- Overuse on static content
- Apply to user-initiated actions (those aren't "magic")
- Use animations that distract from the main task
- Forget dark mode testing

---

## Examples by Use Case

### Risk Assessment Auto-Fill

```twig
{# When risk fields are auto-populated from asset data #}
<div class="card fairy-magic-glow">
    <div class="card-header">
        <i class="bi bi-stars fairy-icon-sparkle"></i>
        Risikoanalyse
        <span class="fairy-badge ms-2">Auto</span>
    </div>
    <div class="card-body">
        <div class="mb-3">
            {{ form_widget(form.probability, {'attr': {'class': 'fairy-field-automatic'}}) }}
            <small class="fairy-helper">Basierend auf Asset-Kritikalität</small>
        </div>
    </div>
</div>
```

### Workflow Completion Notification

```javascript
// When a workflow step auto-completes
showNotification(`
    <div class="fairy-alert">
        <i class="bi bi-stars fairy-alert-icon"></i>
        <div class="fairy-alert-content">
            <div class="fairy-alert-title">Workflow-Schritt abgeschlossen</div>
            <div>Die Genehmigung wurde automatisch erteilt.</div>
        </div>
    </div>
`);
```

### Bulk Delete Confirmation

```twig
{# After successful bulk delete #}
<div class="alert alert-success fairy-magic-glow">
    <i class="bi bi-stars fairy-icon-sparkle"></i>
    12 Einträge wurden erfolgreich bereinigt.
</div>
```

---

**Last Updated:** 2025-12-28
**Maintained by:** Little ISMS Helper Team
**CSS Location:** `assets/styles/app.css`
