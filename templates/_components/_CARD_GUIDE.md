# Card Component Guide

## Overview
Standardized card system with 5 variants, full dark mode support, and consistent styling across the application.

## Card Component Usage

The `_card.html.twig` component provides a flexible, reusable card system based on UI/UX Audit Issue 3.1.

### Basic Include Pattern

```twig
{% include '_components/_card.html.twig' with {
    'title': 'Card Title',
    'body': '<p>Content here</p>'
} %}
```

### Embed Pattern (for complex content)

```twig
{% embed '_components/_card.html.twig' with {
    'title': 'Asset Details',
    'headerIcon': 'bi-cpu'
} %}
    {% block card_body %}
        <p>Complex content here</p>
        {{ form_row(form.name) }}
    {% endblock %}
{% endembed %}
```

---

## Card Variants

### 1. Default Card (Bootstrap)

**Use for:** General content containers, forms, detail views

```twig
{% include '_components/_card.html.twig' with {
    'title': 'Asset Information',
    'headerIcon': 'bi-info-circle',
    'body': '<p>General content</p>'
} %}
```

**Renders:**
```html
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            Asset Information
        </h5>
    </div>
    <div class="card-body">
        <p>General content</p>
    </div>
</div>
```

**CSS:** Bootstrap 5 `.card` + dark mode in `dark-mode.css`

---

### 2. KPI Card (Metrics & Statistics)

**Use for:** Key Performance Indicators, metrics, statistics, counts

```twig
{% include '_components/_card.html.twig' with {
    'variant': 'kpi',
    'borderColor': 'primary',
    'body': '
        <div class="kpi-card-icon">
            <i class="bi bi-shield-check"></i>
        </div>
        <div class="kpi-card-content">
            <div class="kpi-card-label">Total Risks</div>
            <div class="kpi-card-value">42</div>
            <div class="kpi-card-trend trend-up">
                <i class="bi bi-arrow-up"></i> 12% this month
            </div>
        </div>
    '
} %}
```

**Border Colors:** `primary`, `success`, `warning`, `danger`, `info`

**CSS:** `ui-components.css` lines 56-229

**Child Elements:**
- `.kpi-card-icon` - Icon container (56x56px circle)
- `.kpi-card-content` - Content wrapper
- `.kpi-card-label` - Metric label (uppercase)
- `.kpi-card-value` - Metric value (32px, bold)
- `.kpi-card-unit` - Unit suffix (e.g., "EUR", "%")
- `.kpi-card-trend` - Trend indicator with `.trend-up` or `.trend-down`
- `.kpi-card-detail` - Additional details (small text)

**Dark Mode:** ✅ Gradient background with glow effect

---

### 3. Stat Card (Legacy - Deprecated)

**⚠️ Deprecated:** Use `variant: 'kpi'` instead for new code.

**Use for:** Legacy compatibility only (51 existing usages)

```twig
{% include '_components/_card.html.twig' with {
    'variant': 'stat',
    'body': '
        <div class="stat-card-icon bg-primary">
            <i class="bi bi-graph-up"></i>
        </div>
        <div class="stat-card-title">Compliance Rate</div>
        <div class="stat-card-value">87%</div>
        <div class="stat-card-change positive">
            <i class="bi bi-arrow-up"></i> +5% from last month
        </div>
    '
} %}
```

**CSS:** `premium.css` lines 110-206

**Migration Path:** Replace `stat-card-*` classes with `kpi-card-*` equivalents

---

### 4. Widget Card (Dashboard Widgets)

**Use for:** Dashboard widgets, summary panels

```twig
{% include '_components/_card.html.twig' with {
    'variant': 'widget',
    'body': '
        <div class="widget-header">
            <h6>Recent Activity</h6>
        </div>
        <div class="widget-content">
            <!-- Widget content here -->
        </div>
    '
} %}
```

**CSS:** `premium.css` lines 211-247

**Features:**
- Gradient top border on hover
- Premium styling
- Similar to KPI card but for dashboard widgets

**Dark Mode:** ✅ Supported

---

### 5. Feature Card (Showcase)

**Use for:** Feature showcases, landing pages, marketing content

```twig
{% include '_components/_card.html.twig' with {
    'variant': 'feature',
    'body': '
        <div class="feature-icon">
            <i class="bi bi-shield-check"></i>
        </div>
        <h5>ISO 27001 Compliance</h5>
        <p>Comprehensive information security management system.</p>
    '
} %}
```

**CSS:** `premium.css` lines 429-443

**Characteristics:**
- Centered text
- Circular icon (80x80px)
- Hover: translateY(-10px)
- Best in 3-column grid

**Dark Mode:** ✅ Supported

---

### 6. Bordered Card (Accent Border)

**Use for:** Highlighted content, categorized sections

```twig
{% include '_components/_card.html.twig' with {
    'variant': 'bordered',
    'borderColor': 'danger',
    'title': 'Critical Alerts',
    'body': '<p>Important content</p>'
} %}
```

**Renders:** Card with left border accent in specified color

---

## Component Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `variant` | string | `'default'` | Card type: `default`, `kpi`, `stat`, `widget`, `feature`, `bordered` |
| `title` | string | - | Card title (renders as h5 in header) |
| `header` | string | - | Custom header HTML (overrides title) |
| `headerIcon` | string | - | Bootstrap icon class (e.g., `'bi-shield-check'`) |
| `actions` | string | - | Header actions HTML (buttons, dropdowns) |
| `body` | string | - | Card body content (use block for complex content) |
| `footer` | string | - | Card footer content |
| `borderColor` | string | `'primary'` | Border accent color for `kpi` and `bordered` variants |
| `class` | string | - | Additional CSS classes |
| `noPadding` | boolean | `false` | Remove default body padding (for tables, charts) |
| `hoverable` | boolean | `false` | Add hover animation effect |

---

## Advanced Examples

### Card with Header Actions

```twig
{% include '_components/_card.html.twig' with {
    'title': 'Risk Management',
    'headerIcon': 'bi-exclamation-triangle',
    'actions': '
        <button class="btn btn-sm btn-primary">Add Risk</button>
        <button class="btn btn-sm btn-secondary">Export</button>
    ',
    'body': '<p>Risk list here</p>'
} %}
```

### Card with Footer

```twig
{% include '_components/_card.html.twig' with {
    'title': 'Statistics',
    'body': '<p>Stats content</p>',
    'footer': '<small class="text-muted">Last updated: 2025-11-19</small>'
} %}
```

### Card with No Padding (for tables)

```twig
{% embed '_components/_card.html.twig' with {
    'title': 'Asset List',
    'noPadding': true
} %}
    {% block card_body %}
        <table class="table table-hover mb-0">
            <!-- table content -->
        </table>
    {% endblock %}
{% endembed %}
```

### Hoverable Card

```twig
{% include '_components/_card.html.twig' with {
    'variant': 'kpi',
    'hoverable': true,
    'borderColor': 'success',
    'body': '<div class="kpi-card-value">100%</div>'
} %}
```

---

## Migration Guide

### From Direct Bootstrap Cards

**❌ OLD:**
```twig
<div class="card">
    <div class="card-header">
        <h5>Title</h5>
    </div>
    <div class="card-body">
        Content
    </div>
</div>
```

**✅ NEW:**
```twig
{% include '_components/_card.html.twig' with {
    'title': 'Title',
    'body': 'Content'
} %}
```

### From Inline Styled Cards

**❌ OLD:**
```twig
<div class="card" style="margin-bottom: var(--spacing-lg);">
    ...
</div>
```

**✅ NEW:**
```twig
{% include '_components/_card.html.twig' with {
    'class': 'mb-4',
    'title': '...'
} %}
```

### From stat-card to kpi-card

**❌ OLD:**
```twig
<div class="stat-card">
    <div class="stat-card-icon bg-primary">...</div>
    <div class="stat-card-title">Title</div>
    <div class="stat-card-value">42</div>
</div>
```

**✅ NEW:**
```twig
{% include '_components/_card.html.twig' with {
    'variant': 'kpi',
    'borderColor': 'primary',
    'body': '
        <div class="kpi-card-icon">...</div>
        <div class="kpi-card-content">
            <div class="kpi-card-label">Title</div>
            <div class="kpi-card-value">42</div>
        </div>
    '
} %}
```

---

## Dark Mode Support

All card variants have comprehensive dark mode support:

**Light Mode:**
- White backgrounds
- Light borders
- Standard shadows

**Dark Mode:**
- Elevated backgrounds (`var(--bg-elevated)`)
- Adaptive borders (`var(--border-color)`)
- Enhanced shadows
- Gradient effects with glow

**CSS Files:**
- Base: `assets/styles/app.css`
- Dark Mode: `assets/styles/dark-mode.css` (lines 134-142)
- KPI Cards: `assets/styles/ui-components.css` (lines 56-229)
- Premium Cards: `assets/styles/premium.css` (lines 110-443)

---

## Accessibility

### Header Hierarchy

Always use semantic heading levels:
```twig
{# h5 for card titles (default) #}
{% include '_components/_card.html.twig' with {
    'title': 'Card Title'
} %}

{# Custom heading level if needed #}
{% include '_components/_card.html.twig' with {
    'header': '<h3 class="mb-0">Custom Heading</h3>'
} %}
```

### Icon Accessibility

```twig
{# Icons are automatically marked as aria-hidden #}
{% include '_components/_card.html.twig' with {
    'headerIcon': 'bi-shield-check',  {# Decorative only #}
    'title': 'Security Status'  {# Screen reader reads this #}
} %}
```

### Interactive Elements

```twig
{# Buttons in header actions should have aria-labels #}
{% include '_components/_card.html.twig' with {
    'title': 'Risks',
    'actions': '<button class="btn btn-sm btn-primary" aria-label="Add new risk">
        <i class="bi bi-plus" aria-hidden="true"></i>
    </button>'
} %}
```

---

## Best Practices

### ✅ DO

- Use `variant: 'kpi'` for all new metric cards
- Use `noPadding: true` when embedding tables
- Use Bootstrap utility classes (`mb-4`, `mt-3`) for spacing
- Provide both `title` and `headerIcon` for better UX
- Use embed pattern for complex content (forms, tables)
- Test cards in both light and dark modes

### ❌ DON'T

- Don't use inline styles (`style="..."`)
- Don't use `variant: 'stat'` (deprecated, use 'kpi')
- Don't mix card header patterns (always use component)
- Don't forget `aria-hidden="true"` on decorative icons
- Don't create custom card classes without dark mode support
- Don't use `card-header` with `h3` inside the component (uses h5 by default)

---

## Performance Tips

- **Include vs Embed:** Use `include` for simple cards, `embed` only when you need block overrides
- **Caching:** Card components are compiled by Twig, no runtime performance impact
- **CSS:** All card styles use CSS variables for theming, no JS required

---

## Card Statistics

- **Total card occurrences:** ~1990 across 215 templates
- **kpi-card:** 89 usages ✅ Primary
- **stat-card:** 51 usages ⚠️ Migrate to kpi-card
- **widget-card:** 4 usages ✅ Keep for dashboard
- **feature-card:** 5 usages ✅ Keep for showcases
- **Inline styles:** 0 ✅ All removed

---

## Testing Checklist

- [ ] Card renders correctly in light mode
- [ ] Card renders correctly in dark mode
- [ ] Header hierarchy is semantic (h5 by default)
- [ ] Icons have `aria-hidden="true"`
- [ ] No inline styles (`style="..."`)
- [ ] Hover effects work (if `hoverable: true`)
- [ ] Border colors match design system
- [ ] Content is accessible to screen readers
- [ ] Responsive on mobile (320px-768px)
- [ ] Template lints successfully

---

**Last Updated:** 2025-11-19
**Version:** 2.0 (Post UI/UX Audit Consolidation)
**Related:** Issue 3.1 - Card Standardization, Issue 3.2 - Card Header Inconsistency
